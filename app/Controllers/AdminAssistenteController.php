<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\IA\Assistente;
use App\IA\AssistenteRepository;
use App\IA\OpenRouter;

// Tela "Assistente" (só proprietário): humor, conhecimento, fluxos, modelo e custo, e o teste lado a lado (D70).
// A chave do OpenRouter NUNCA passa por aqui: só existe na variável de ambiente OPENROUTER_API_KEY.
final class AdminAssistenteController
{
    private const BOOLEANOS = ['ia_ativo', 'ia_avisar_ia', 'ia_incluir_cardapio', 'ia_incluir_horarios', 'ia_incluir_entrega', 'whatsapp_enquetes'];
    private const TEXTOS = [   // chave => [rótulo, tamanho máximo]
        'ia_nome' => ['O nome do assistente', 40], 'ia_papel' => ['O papel', 1500], 'ia_tom_texto' => ['O tom personalizado', 300], 'ia_saudacao' => ['A saudação', 200],
        'ia_regras' => ['As regras', 1500], 'ia_escalonar' => ['A regra de passar para uma pessoa', 1000], 'ia_texto_livre' => ['O texto livre', 4000],
    ];

    /** GET /api/admin/assistente: tudo que a tela precisa para desenhar. */
    public function ler(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $repo = new AssistenteRepository();
        Resposta::json([
            'config' => self::configParaTela($repo->config()),
            'faqs' => $repo->faqs(),
            'fluxos' => $repo->fluxos(),
            'chave_configurada' => OpenRouter::configurada(),
            'indisponivel' => (new Assistente())->indisponivel('0'),
            'modelos' => AssistenteRepository::MODELOS_SUGERIDOS,
            'tons' => AssistenteRepository::TONS,
            'tamanhos' => AssistenteRepository::TAMANHOS,
            'uso' => $repo->resumoDeUso(),
            'credito' => ['alerta_usd' => (float) $repo->config()['ia_alerta_saldo_usd'], 'recargas' => $repo->recargas(10)] + $repo->carteira(),
        ]);
    }

    /** PUT /api/admin/assistente  {config, faqs, fluxos}: salva tudo de uma vez. */
    public function salvar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $dados = $req->json();
        $erros = [];
        $config = self::validarConfig(is_array($dados['config'] ?? null) ? $dados['config'] : [], $erros);
        $faqs = self::validarFaqs(is_array($dados['faqs'] ?? null) ? $dados['faqs'] : [], $erros);
        $fluxos = self::validarFluxos(is_array($dados['fluxos'] ?? null) ? $dados['fluxos'] : [], $erros);
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        $repo = new AssistenteRepository();
        $repo->salvarConfig($config);
        $repo->salvarListas($faqs, $fluxos);
        $this->ler($req);
    }

    /** GET /api/admin/assistente/saldo: saldo e uso da chave no OpenRouter (uma chamada de rede, por isso à parte). */
    public function saldo(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        if (!OpenRouter::configurada()) {
            Resposta::json(['ok' => false, 'erro' => 'A chave do OpenRouter ainda não foi configurada (variável OPENROUTER_API_KEY no servidor).']);
            return;
        }
        Resposta::json((new OpenRouter())->saldo());
    }

    /** GET /api/admin/assistente/prompt: o texto exato que o modelo recebe como instrução (para o dono conferir o que ele sabe). */
    public function prompt(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $texto = (new Assistente())->prompt(null, true, 'Cliente');
        Resposta::json(['prompt' => $texto, 'caracteres' => mb_strlen($texto), 'tokens_aprox' => (int) round(mb_strlen($texto) / 3.5)]);
    }

    /**
     * POST /api/admin/assistente/testar  {pergunta, modelo, historico?}: uma pergunta a UM modelo, sem tocar em nenhuma conversa.
     * A tela dispara uma chamada por modelo ao mesmo tempo, então comparar 3 modelos leva o tempo do mais lento.
     */
    public function testar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $dados = $req->json();
        $pergunta = mb_substr(trim((string) ($dados['pergunta'] ?? '')), 0, 500);
        $modelo = trim((string) ($dados['modelo'] ?? ''));
        $erros = [];
        if ($pergunta === '') {
            $erros['pergunta'] = 'Escreva a pergunta do cliente.';
        }
        if (!self::modeloValido($modelo)) {
            $erros['modelo'] = 'Informe o identificador do modelo, como anthropic/claude-haiku-5.5.';
        }
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        if (!OpenRouter::configurada()) {
            throw new ErroHttp(409, 'sem_chave', 'A chave do OpenRouter ainda não foi configurada (variável OPENROUTER_API_KEY no servidor).');
        }
        $repo = new AssistenteRepository();
        $teto = (float) $repo->config()['ia_teto_usd'];
        if ($teto > 0 && $repo->gastoDoMes() >= $teto) {
            throw new ErroHttp(409, 'teto', 'O teto de gasto do mês foi atingido. Aumente o teto na aba Modelo e custo para testar.');
        }
        $historico = [];
        foreach (array_slice(is_array($dados['historico'] ?? null) ? $dados['historico'] : [], -6) as $m) {
            if (is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant'], true) && is_string($m['content'] ?? null)) {
                $historico[] = ['role' => $m['role'], 'content' => mb_substr($m['content'], 0, 600)];
            }
        }
        $r = (new Assistente())->responder('teste', $pergunta, 'Cliente Teste', ['modelo' => $modelo, 'origem' => 'teste', 'simulacao' => true, 'historico' => $historico]);
        Resposta::json($r);
    }

    /** POST /api/admin/assistente/recargas  {valor_usd, nota?}: registra o que o cliente pagou (liga o controle de crédito). */
    public function recarregar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $dados = $req->json();
        $bruto = str_replace(',', '.', (string) ($dados['valor_usd'] ?? ''));
        try {
            if (!is_numeric($bruto)) {
                throw new \InvalidArgumentException('Informe o valor da recarga em dólares, como 10 ou 12,50.');
            }
            (new AssistenteRepository())->registrarRecarga((float) $bruto, (string) ($dados['nota'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw ErroHttp::validacao(['valor_usd' => $e->getMessage()]);
        }
        $this->ler($req);
    }

    /** DELETE /api/admin/assistente/recargas/{id}: tira uma recarga lançada por engano. */
    public function apagarRecarga(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        (new AssistenteRepository())->apagarRecarga((int) $req->parametro('id'));
        $this->ler($req);
    }

    // ---------- Validação ----------

    public static function modeloValido(string $modelo): bool
    {
        return strlen($modelo) <= 100 && preg_match('#^[A-Za-z0-9~._-]+/[A-Za-z0-9~._:-]+$#', $modelo) === 1;
    }

    /** @param array<string,string> $c @return array<string,mixed> */
    private static function configParaTela(array $c): array
    {
        $saida = [];
        foreach ($c as $chave => $valor) {
            $saida[$chave] = in_array($chave, self::BOOLEANOS, true) ? $valor === '1' : (in_array($chave, ['ia_limite_hora', 'ia_memoria'], true) ? (int) $valor : (in_array($chave, ['ia_teto_usd', 'ia_alerta_saldo_usd'], true) ? (float) $valor : $valor));
        }
        return $saida;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,string> $erros
     * @return array<string,string> valores prontos para a tabela de configuração
     */
    private static function validarConfig(array $in, array &$erros): array
    {
        $out = [];
        foreach (self::BOOLEANOS as $chave) {
            $out[$chave] = !empty($in[$chave]) ? '1' : '0';
        }
        foreach (self::TEXTOS as $chave => [$rotulo, $max]) {
            $valor = trim((string) ($in[$chave] ?? ''));
            if (mb_strlen($valor) > $max) {
                $erros[$chave] = "{$rotulo} passou de {$max} caracteres.";
            }
            $out[$chave] = $valor;
        }
        if ($out['ia_nome'] === '') {
            $erros['ia_nome'] = 'Dê um nome ao assistente.';
        }
        $palavras = array_values(array_filter(array_map('trim', explode(',', (string) ($in['whatsapp_palavras_cardapio'] ?? ''))), static fn (string $p): bool => $p !== ''));
        $reservadas = array_intersect(array_map(\App\WhatsApp\FluxoPedido::chave(...), $palavras), \App\WhatsApp\FluxoPedido::RESERVADAS);
        if ($palavras === [] || count($palavras) > 5 || max(array_map('mb_strlen', $palavras ?: [''])) > 30 || $reservadas !== []) {
            $erros['whatsapp_palavras_cardapio'] = $reservadas !== []
                ? 'Essa palavra o bot já usa para outra coisa: ' . implode(', ', $reservadas) . '.'
                : 'Escreva de 1 a 5 palavras, separadas por vírgula (cada uma com até 30 letras).';
        }
        $out['whatsapp_palavras_cardapio'] = implode(', ', $palavras);
        $modelo = trim((string) ($in['ia_modelo'] ?? ''));
        if (!self::modeloValido($modelo)) {
            $erros['ia_modelo'] = 'Informe o identificador do modelo no OpenRouter, como anthropic/claude-haiku-5.5.';
        }
        $out['ia_modelo'] = $modelo;
        $tom = (string) ($in['ia_tom'] ?? '');
        $tamanho = (string) ($in['ia_tamanho'] ?? '');
        if (!isset(AssistenteRepository::TONS[$tom])) {
            $erros['ia_tom'] = 'Escolha um tom.';
        }
        if (!isset(AssistenteRepository::TAMANHOS[$tamanho])) {
            $erros['ia_tamanho'] = 'Escolha o tamanho das respostas.';
        }
        $out['ia_tom'] = $tom;
        $out['ia_tamanho'] = $tamanho;
        $out['ia_privacidade'] = ($in['ia_privacidade'] ?? 'deny') === 'allow' ? 'allow' : 'deny';

        $teto = is_numeric(str_replace(',', '.', (string) ($in['ia_teto_usd'] ?? ''))) ? (float) str_replace(',', '.', (string) $in['ia_teto_usd']) : -1.0;
        if ($teto < 0.5 || $teto > 1000) {
            $erros['ia_teto_usd'] = 'O teto mensal vai de US$ 0,50 a US$ 1.000.';
        }
        $out['ia_teto_usd'] = rtrim(rtrim(number_format(max($teto, 0), 2, '.', ''), '0'), '.');
        $alerta = is_numeric(str_replace(',', '.', (string) ($in['ia_alerta_saldo_usd'] ?? ''))) ? (float) str_replace(',', '.', (string) $in['ia_alerta_saldo_usd']) : -1.0;
        if ($alerta < 0 || $alerta > 1000) {
            $erros['ia_alerta_saldo_usd'] = 'O alerta de saldo baixo vai de US$ 0 (sem alerta) a US$ 1.000.';
        }
        $out['ia_alerta_saldo_usd'] = rtrim(rtrim(number_format(max($alerta, 0), 2, '.', ''), '0'), '.') ?: '0';
        foreach (['ia_limite_hora' => ['O limite por cliente', 1, 100], 'ia_memoria' => ['A memória da conversa', 0, 20]] as $chave => [$rotulo, $min, $max]) {
            $n = $in[$chave] ?? null;
            if (!is_numeric($n) || (int) $n < $min || (int) $n > $max) {
                $erros[$chave] = "{$rotulo} vai de {$min} a {$max}.";
            }
            $out[$chave] = (string) (int) ($n ?? 0);
        }
        return $out;
    }

    /** @param list<mixed> $lista @param array<string,string> $erros @return list<array{pergunta:string, resposta:string, ativo:bool}> */
    private static function validarFaqs(array $lista, array &$erros): array
    {
        $saida = [];
        if (count($lista) > 50) {
            $erros['faqs'] = 'No máximo 50 perguntas e respostas.';
        }
        foreach (array_slice($lista, 0, 50) as $i => $f) {
            $pergunta = trim((string) ($f['pergunta'] ?? ''));
            $resposta = trim((string) ($f['resposta'] ?? ''));
            if ($pergunta === '' && $resposta === '') {
                continue;   // linha em branco: some
            }
            if ($pergunta === '' || $resposta === '' || mb_strlen($pergunta) > 200 || mb_strlen($resposta) > 800) {
                $erros["faqs.{$i}"] = 'Preencha a pergunta (até 200 caracteres) e a resposta (até 800).';
            }
            $saida[] = ['pergunta' => $pergunta, 'resposta' => $resposta, 'ativo' => !empty($f['ativo'])];
        }
        return $saida;
    }

    /** @param list<mixed> $lista @param array<string,string> $erros @return list<array{titulo:string, quando:string, passos:string, ativo:bool}> */
    private static function validarFluxos(array $lista, array &$erros): array
    {
        $saida = [];
        if (count($lista) > 20) {
            $erros['fluxos'] = 'No máximo 20 fluxos.';
        }
        foreach (array_slice($lista, 0, 20) as $i => $f) {
            $titulo = trim((string) ($f['titulo'] ?? ''));
            $quando = trim((string) ($f['quando'] ?? ''));
            $passos = trim((string) ($f['passos'] ?? ''));
            if ($titulo === '' && $quando === '' && $passos === '') {
                continue;
            }
            if ($titulo === '' || $quando === '' || $passos === '' || mb_strlen($titulo) > 80 || mb_strlen($quando) > 300 || mb_strlen($passos) > 1000) {
                $erros["fluxos.{$i}"] = 'Preencha o título (até 80), quando acontece (até 300) e o que fazer (até 1000).';
            }
            $saida[] = ['titulo' => $titulo, 'quando' => $quando, 'passos' => $passos, 'ativo' => !empty($f['ativo'])];
        }
        return $saida;
    }
}
