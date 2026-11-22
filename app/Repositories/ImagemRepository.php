<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Banco;
use App\Core\Relogio;
use PDO;

/**
 * Fotos do cardápio enviadas pelo painel, guardadas no banco (tabela `imagens`, só no Postgres): o disco da hospedagem gratuita
 * some quando o serviço reinicia. No SQLite (desenvolvimento) as fotos continuam em arquivos, em public/uploads.
 */
final class ImagemRepository
{
    public function guardar(string $nome, string $tipo, string $conteudo): void
    {
        $fluxo = fopen('php://memory', 'r+');
        fwrite($fluxo, $conteudo);
        rewind($fluxo);
        $consulta = Banco::conexao()->prepare('INSERT INTO imagens (nome, tipo, dados, criado_em) VALUES (?, ?, ?, ?)');
        $consulta->bindValue(1, $nome);
        $consulta->bindValue(2, $tipo);
        $consulta->bindValue(3, $fluxo, PDO::PARAM_LOB);   // bytea: os bytes seguem como estão
        $consulta->bindValue(4, Relogio::agora());
        $consulta->execute();
        fclose($fluxo);
    }

    /**
     * Apaga as fotos que nada mais usa: a de um item que trocou de foto ou foi apagado, a logo antiga e a miniatura de cada uma.
     * Só as com mais de 1 dia: a foto recém-enviada ainda não foi salva no item (o dono envia e depois clica em Salvar).
     * @return int quantas saíram
     */
    public function apagarSemUso(): int
    {
        // "abc-mini.webp" pertence a "abc.webp": as duas ficam enquanto a grande estiver em uso.
        $consulta = Banco::conexao()->prepare(
            "DELETE FROM imagens WHERE criado_em < ?
               AND NOT EXISTS (SELECT 1 FROM itens t WHERE t.imagem = '/uploads/' || replace(imagens.nome, '-mini.', '.'))
               AND NOT EXISTS (SELECT 1 FROM configuracoes c WHERE c.chave = 'logo_loja' AND c.valor = '/uploads/' || replace(imagens.nome, '-mini.', '.'))",
        );
        $consulta->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
        return $consulta->rowCount();
    }

    /** @return array{tipo:string, dados:string}|null */
    public function porNome(string $nome): ?array
    {
        $consulta = Banco::conexao()->prepare('SELECT tipo, dados FROM imagens WHERE nome = ?');
        $consulta->execute([$nome]);
        $linha = $consulta->fetch();
        if (!$linha) {
            return null;
        }
        $dados = $linha['dados'];
        return ['tipo' => (string) $linha['tipo'], 'dados' => is_resource($dados) ? (string) stream_get_contents($dados) : (string) $dados];
    }
}
