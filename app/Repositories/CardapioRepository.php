<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Banco;
use App\Core\ErroHttp;

// Leitura e escrita do cardápio. As telas nunca falam com o banco direto: passam por aqui.
final class CardapioRepository
{
    /**
     * Recursos editáveis no painel: tabela, colunas que podem ser gravadas e estados aceitos.
     * A lista fechada também impede SQL com nome de tabela/coluna vindo do navegador.
     */
    public const RECURSOS = [
        'categorias'   => ['colunas' => ['nome', 'ordem', 'estado'], 'estados' => ['disponivel', 'oculto']],
        'tamanhos'     => ['colunas' => ['nome', 'sigla', 'descricao', 'preco_centavos', 'inclusos', 'ordem', 'estado'], 'estados' => ['disponivel', 'esgotado', 'oculto']],
        'massas'       => ['colunas' => ['nome', 'descricao', 'acrescimo_centavos', 'ordem', 'estado'], 'estados' => ['disponivel', 'esgotado', 'oculto']],
        'molhos'       => ['colunas' => ['nome', 'descricao', 'acrescimo_centavos', 'cor', 'ordem', 'estado'], 'estados' => ['disponivel', 'esgotado', 'oculto']],
        'ingredientes' => ['colunas' => ['nome', 'grupo', 'preco_extra_centavos', 'ordem', 'estado'], 'estados' => ['disponivel', 'esgotado', 'oculto']],
        'itens'        => ['colunas' => ['categoria_id', 'tipo', 'nome', 'descricao', 'preco_centavos', 'imagem', 'massa_id', 'molho_id', 'ordem', 'estado'], 'estados' => ['disponivel', 'esgotado', 'oculto']],
    ];

    private const INTEIROS = ['id', 'ordem', 'preco_centavos', 'inclusos', 'acrescimo_centavos', 'preco_extra_centavos', 'categoria_id', 'massa_id', 'molho_id'];

    /** Cardápio inteiro, do jeito que o painel precisa (inclui ocultos). */
    public function completo(): array
    {
        $pdo = Banco::conexao();
        $dados = [];
        foreach (array_keys(self::RECURSOS) as $recurso) {
            $dados[$recurso] = array_map([$this, 'tipar'], $pdo->query("SELECT * FROM {$recurso} ORDER BY ordem, id")->fetchAll());
        }
        $porItem = [];
        foreach ($pdo->query('SELECT item_id, ingrediente_id FROM item_ingredientes ORDER BY ingrediente_id') as $linha) {
            $porItem[(int) $linha['item_id']][] = (int) $linha['ingrediente_id'];
        }
        foreach ($dados['itens'] as &$item) {
            $item['ingrediente_ids'] = $porItem[$item['id']] ?? [];
        }
        return $dados;
    }

    /** Cardápio que o cliente vê: sem nada oculto. Esgotados continuam (aparecem desabilitados). */
    public function publico(): array
    {
        $dados = $this->completo();
        $visivel = static fn (array $linha): bool => $linha['estado'] !== 'oculto';
        foreach ($dados as $recurso => $linhas) {
            $dados[$recurso] = array_values(array_filter($linhas, $visivel));
        }
        $categorias = array_column($dados['categorias'], null, 'id');
        $dados['itens'] = array_values(array_filter($dados['itens'], static fn (array $item): bool => isset($categorias[$item['categoria_id']])));
        return $dados;
    }

    public function buscar(string $recurso, int $id): ?array
    {
        $this->exigirRecurso($recurso);
        $sql = Banco::conexao()->prepare("SELECT * FROM {$recurso} WHERE id = ?");
        $sql->execute([$id]);
        $linha = $sql->fetch();
        return $linha ? $this->tipar($linha) : null;
    }

    /** @param array<string,mixed> $valores já validados */
    public function criar(string $recurso, array $valores): int
    {
        $valores = $this->soColunasPermitidas($recurso, $valores);
        $colunas = array_keys($valores);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $recurso, implode(', ', $colunas), implode(', ', array_fill(0, count($colunas), '?')));
        return Banco::inserir(Banco::conexao(), $sql, array_values($valores));
    }

    /** @param array<string,mixed> $valores já validados */
    public function atualizar(string $recurso, int $id, array $valores): void
    {
        $valores = $this->soColunasPermitidas($recurso, $valores);
        if ($valores === []) {
            return;
        }
        $partes = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($valores)));
        Banco::conexao()->prepare("UPDATE {$recurso} SET {$partes} WHERE id = ?")->execute([...array_values($valores), $id]);
    }

    public function apagar(string $recurso, int $id): void
    {
        $this->exigirRecurso($recurso);
        Banco::conexao()->prepare("DELETE FROM {$recurso} WHERE id = ?")->execute([$id]);
    }

    public function proximaOrdem(string $recurso): int
    {
        $this->exigirRecurso($recurso);
        return (int) Banco::conexao()->query("SELECT COALESCE(MAX(ordem), -1) + 1 FROM {$recurso}")->fetchColumn();
    }

    public function contarItensDaCategoria(int $categoriaId): int
    {
        $sql = Banco::conexao()->prepare('SELECT COUNT(*) FROM itens WHERE categoria_id = ?');
        $sql->execute([$categoriaId]);
        return (int) $sql->fetchColumn();
    }

    /** @param list<int> $ingredienteIds */
    public function definirIngredientesDoItem(int $itemId, array $ingredienteIds): void
    {
        $pdo = Banco::conexao();
        $pdo->prepare('DELETE FROM item_ingredientes WHERE item_id = ?')->execute([$itemId]);
        $insere = $pdo->prepare('INSERT INTO item_ingredientes (item_id, ingrediente_id) VALUES (?, ?)');
        foreach (array_unique($ingredienteIds) as $ingredienteId) {
            $insere->execute([$itemId, $ingredienteId]);
        }
    }

    private function exigirRecurso(string $recurso): void
    {
        if (!isset(self::RECURSOS[$recurso])) {
            throw ErroHttp::naoEncontrado('Este tipo de cadastro não existe.');
        }
    }

    private function soColunasPermitidas(string $recurso, array $valores): array
    {
        $this->exigirRecurso($recurso);
        return array_intersect_key($valores, array_flip(self::RECURSOS[$recurso]['colunas']));
    }

    /** O PDO devolve tudo como texto no SQLite antigo; aqui números viram números de verdade. */
    private function tipar(array $linha): array
    {
        foreach (self::INTEIROS as $coluna) {
            if (array_key_exists($coluna, $linha) && $linha[$coluna] !== null) {
                $linha[$coluna] = (int) $linha[$coluna];
            }
        }
        return $linha;
    }
}
