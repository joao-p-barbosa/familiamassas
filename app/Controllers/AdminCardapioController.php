<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Banco;
use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Repositories\CardapioRepository;

// Edição do cardápio (Etapa 5): cadastro de categorias, itens, tamanhos, massas, molhos e ingredientes.
final class AdminCardapioController
{
    private CardapioRepository $repo;

    public function __construct()
    {
        $this->repo = new CardapioRepository();
    }

    public function listar(Requisicao $req): void
    {
        Sessao::exigir($req);
        Resposta::json($this->repo->completo());
    }

    public function criar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $recurso = $req->parametro('recurso');
        $dados = $req->json();
        $valores = $this->validar($recurso, $dados, null);
        $valores['ordem'] ??= $this->repo->proximaOrdem($recurso);
        $id = Banco::transacao(function () use ($recurso, $valores, $dados): int {
            $id = $this->repo->criar($recurso, $valores);
            $this->gravarIngredientesDoItem($recurso, $id, $valores, $dados);
            return $id;
        });
        Resposta::json($this->repo->buscar($recurso, $id), 201);
    }

    public function atualizar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $recurso = $req->parametro('recurso');
        $id = (int) $req->parametro('id');
        $atual = $this->repo->buscar($recurso, $id) ?? throw ErroHttp::naoEncontrado('Este cadastro não existe mais.');
        $dados = $req->json();
        $valores = $this->validar($recurso, $dados, $atual);
        Banco::transacao(function () use ($recurso, $id, $valores, $dados): void {
            $this->repo->atualizar($recurso, $id, $valores);
            $this->gravarIngredientesDoItem($recurso, $id, $valores, $dados);
        });
        Resposta::json($this->repo->buscar($recurso, $id));
    }

    /** PATCH .../estado   corpo: {"estado": "disponivel" | "esgotado" | "oculto"}. Cozinha e atendimento só marcam esgotado/disponível. */
    public function estado(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, [], true);
        $recurso = $req->parametro('recurso');
        $id = (int) $req->parametro('id');
        $atual = $this->repo->buscar($recurso, $id) ?? throw ErroHttp::naoEncontrado('Este cadastro não existe mais.');
        $estado = (string) ($req->json()['estado'] ?? '');
        if (!in_array($estado, CardapioRepository::RECURSOS[$recurso]['estados'], true)) {
            throw ErroHttp::validacao(['estado' => 'Estado não aceito para este cadastro.']);
        }
        if ($usuario['perfil'] !== 'admin' && ($estado === 'oculto' || $atual['estado'] === 'oculto')) {
            throw new ErroHttp(403, 'sem_permissao', 'Só o administrador oculta ou volta a mostrar itens.');
        }
        $this->repo->atualizar($recurso, $id, ['estado' => $estado]);
        Resposta::json($this->repo->buscar($recurso, $id));
    }

    public function apagar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $recurso = $req->parametro('recurso');
        $id = (int) $req->parametro('id');
        $this->repo->buscar($recurso, $id) ?? throw ErroHttp::naoEncontrado('Este cadastro não existe mais.');
        if ($recurso === 'categorias' && ($n = $this->repo->contarItensDaCategoria($id)) > 0) {
            throw new ErroHttp(409, 'categoria_com_itens', "Esta categoria ainda tem {$n} " . ($n === 1 ? 'item' : 'itens') . '. Mova ou apague os itens antes.');
        }
        $this->repo->apagar($recurso, $id);
        Resposta::json(['ok' => true]);
    }

    /** POST /api/admin/upload (multipart, campo "imagem"). A foto já chega reduzida pelo navegador. */
    public function upload(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $arquivo = $_FILES['imagem'] ?? null;
        if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
            throw ErroHttp::validacao(['imagem' => 'Não recebemos a imagem. Tente de novo.']);
        }
        if ($arquivo['size'] > 1_500_000) {
            throw ErroHttp::validacao(['imagem' => 'A imagem ficou acima de 1,5 MB. Escolha outra foto.']);
        }
        // O tipo é conferido pelo conteúdo do arquivo, nunca pelo nome que o navegador mandou.
        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
        $extensao = ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$tipo] ?? null;
        if ($extensao === null || @getimagesize($arquivo['tmp_name']) === false) {
            throw ErroHttp::validacao(['imagem' => 'Use uma foto em JPG, PNG ou WebP.']);
        }
        $nome = bin2hex(random_bytes(12)) . '.' . $extensao;
        $pasta = RAIZ . '/public/uploads';
        if (!is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }
        if (!move_uploaded_file($arquivo['tmp_name'], $pasta . '/' . $nome)) {
            throw new ErroHttp(500, 'upload_falhou', 'Não conseguimos salvar a imagem. Tente de novo.');
        }
        Resposta::json(['caminho' => '/uploads/' . $nome], 201);
    }

    private function gravarIngredientesDoItem(string $recurso, int $id, array $valores, array $dados): void
    {
        if ($recurso !== 'itens' || !array_key_exists('ingrediente_ids', $dados)) {
            return;
        }
        $ids = ($valores['tipo'] ?? '') === 'prato_casa' ? array_map('intval', (array) $dados['ingrediente_ids']) : [];
        $existentes = array_column($this->repo->completo()['ingredientes'], 'id');
        $this->repo->definirIngredientesDoItem($id, array_values(array_intersect($ids, $existentes)));
    }

    /**
     * Valida e limpa os campos de um cadastro. $atual = registro existente (edição) ou null (criação).
     * @return array<string,mixed> só as colunas que podem ser gravadas
     */
    private function validar(string $recurso, array $d, ?array $atual): array
    {
        if (!isset(CardapioRepository::RECURSOS[$recurso])) {
            throw ErroHttp::naoEncontrado('Este tipo de cadastro não existe.');
        }
        $erros = [];
        $v = [];
        $texto = static fn (string $campo, int $max): string => mb_substr(trim((string) ($d[$campo] ?? $atual[$campo] ?? '')), 0, $max);
        $dinheiro = static function (string $campo, bool $obrigatorio) use ($d, $atual, &$erros): int {
            $valor = $d[$campo] ?? $atual[$campo] ?? null;
            if ($valor === null || $valor === '' || !is_numeric($valor) || (int) $valor < 0 || (int) $valor > 1_000_000) {
                $erros[$campo] = 'Informe um valor em reais, de 0 a 10.000.';
                return 0;
            }
            if ($obrigatorio && (int) $valor === 0) {
                $erros[$campo] = 'O preço precisa ser maior que zero.';
            }
            return (int) $valor;
        };

        $v['nome'] = $texto('nome', 60);
        if ($v['nome'] === '') {
            $erros['nome'] = 'Dê um nome.';
        }
        $estado = (string) ($d['estado'] ?? $atual['estado'] ?? 'disponivel');
        if (!in_array($estado, CardapioRepository::RECURSOS[$recurso]['estados'], true)) {
            $erros['estado'] = 'Estado não aceito.';
        }
        $v['estado'] = $estado;
        if (isset($d['ordem']) && is_numeric($d['ordem'])) {
            $v['ordem'] = (int) $d['ordem'];
        }

        switch ($recurso) {
            case 'tamanhos':
                $v['sigla'] = mb_strtoupper($texto('sigla', 3));
                if ($v['sigla'] === '') { $erros['sigla'] = 'Informe a sigla (P, M, G...).'; }
                $v['descricao'] = $texto('descricao', 60);
                $v['preco_centavos'] = $dinheiro('preco_centavos', true);
                $inclusos = $d['inclusos'] ?? $atual['inclusos'] ?? null;
                if (!is_numeric($inclusos) || (int) $inclusos < 0 || (int) $inclusos > 20) {
                    $erros['inclusos'] = 'Informe quantos ingredientes estão inclusos (0 a 20).';
                }
                $v['inclusos'] = (int) $inclusos;
                break;
            case 'massas':
                $v['descricao'] = $texto('descricao', 100);
                $v['acrescimo_centavos'] = $dinheiro('acrescimo_centavos', false);
                break;
            case 'molhos':
                $v['descricao'] = $texto('descricao', 100);
                $v['acrescimo_centavos'] = $dinheiro('acrescimo_centavos', false);
                $v['cor'] = $texto('cor', 7) ?: '#B8402A';
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v['cor'])) { $erros['cor'] = 'Escolha uma cor válida.'; }
                break;
            case 'ingredientes':
                $v['grupo'] = $texto('grupo', 40) ?: 'Outros';
                $v['preco_extra_centavos'] = $dinheiro('preco_extra_centavos', false);
                break;
            case 'itens':
                $cardapio = $this->repo->completo();
                $existe = static fn (string $lista, mixed $id): bool => in_array((int) $id, array_column($cardapio[$lista], 'id'), true);
                $v['descricao'] = $texto('descricao', 160);
                $v['categoria_id'] = (int) ($d['categoria_id'] ?? $atual['categoria_id'] ?? 0);
                if (!$existe('categorias', $v['categoria_id'])) { $erros['categoria_id'] = 'Escolha a categoria.'; }
                $v['tipo'] = (string) ($d['tipo'] ?? $atual['tipo'] ?? 'simples');
                if (!in_array($v['tipo'], ['simples', 'prato_casa'], true)) { $erros['tipo'] = 'Tipo de item não aceito.'; }
                $imagem = array_key_exists('imagem', $d) ? $d['imagem'] : ($atual['imagem'] ?? null);
                if ($imagem !== null && $imagem !== '' && !preg_match('#^/uploads/[a-f0-9]{24}\.(webp|jpg|png)$#', (string) $imagem)) {
                    $erros['imagem'] = 'Envie a foto de novo.';
                }
                $v['imagem'] = $imagem ?: null;
                if ($v['tipo'] === 'prato_casa') {
                    $v['preco_centavos'] = 0;   // o preço vem da montagem (tamanho + acréscimos)
                    $v['massa_id'] = (int) ($d['massa_id'] ?? $atual['massa_id'] ?? 0);
                    $v['molho_id'] = (int) ($d['molho_id'] ?? $atual['molho_id'] ?? 0);
                    if (!$existe('massas', $v['massa_id'])) { $erros['massa_id'] = 'Escolha a massa do prato.'; }
                    if (!$existe('molhos', $v['molho_id'])) { $erros['molho_id'] = 'Escolha o molho do prato.'; }
                } else {
                    $v['preco_centavos'] = $dinheiro('preco_centavos', true);
                    $v['massa_id'] = null;
                    $v['molho_id'] = null;
                }
                break;
        }

        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        return $v;
    }
}
