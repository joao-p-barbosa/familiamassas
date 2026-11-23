<?php
declare(strict_types=1);

namespace App\IA;

use App\Core\Env;

/**
 * Cliente do OpenRouter (D70): POST https://openrouter.ai/api/v1/chat/completions, formato Chat Completions com ferramentas.
 * A chave vem só da variável de ambiente OPENROUTER_API_KEY e vai só no cabeçalho Authorization: nunca em log, nunca em mensagem de erro.
 * O custo real de cada resposta chega em `usage.cost` (em dólares); não precisa de parâmetro extra.
 *
 * O transporte HTTP é um parâmetro do construtor para os testes não precisarem de rede (nem gastar crédito).
 */
final class OpenRouter
{
    public const URL = 'https://openrouter.ai/api/v1';

    /** @var (callable(string, string, list<string>, ?string): array{status:int, corpo:string, erro:?string})|null */
    private $transporte;

    /** @param (callable(string, string, list<string>, ?string): array{status:int, corpo:string, erro:?string})|null $transporte */
    public function __construct(?callable $transporte = null)
    {
        $this->transporte = $transporte;
    }

    public static function chave(): string
    {
        return Env::get('OPENROUTER_API_KEY');
    }

    /** Endereço da API. Só muda por OPENROUTER_BASE_URL (testes com um servidor de mentira, ou um proxy da empresa). */
    private static function base(): string
    {
        return rtrim(Env::get('OPENROUTER_BASE_URL', self::URL), '/');
    }

    public static function configurada(): bool
    {
        return strlen(self::chave()) >= 20;
    }

    /**
     * Uma chamada ao modelo.
     * @param list<array<string,mixed>> $mensagens no formato Chat Completions (system, user, assistant, tool)
     * @param list<array<string,mixed>> $ferramentas definições de ferramentas (vazio = sem ferramentas)
     * @param array{max_tokens?:int, privacidade?:string} $opcoes privacidade: "deny" (padrão) só usa provedores que não guardam os dados
     * @return array{ok:bool, mensagem:array<string,mixed>, texto:string, chamadas:list<array{id:string, nome:string, argumentos:array<string,mixed>}>,
     *   tokens_in:int, tokens_out:int, custo_usd:?float, ms:int, erro:?string, status:int}
     */
    public function conversar(string $modelo, array $mensagens, array $ferramentas = [], array $opcoes = []): array
    {
        $corpo = [
            'model' => $modelo,
            'messages' => $mensagens,
            'max_tokens' => (int) ($opcoes['max_tokens'] ?? 700),
            // Padrão "deny": o OpenRouter só roteia para provedores que não guardam nem treinam com as conversas dos clientes.
            'provider' => ['data_collection' => ($opcoes['privacidade'] ?? 'deny') === 'allow' ? 'allow' : 'deny'],
        ];
        if ($ferramentas !== []) {
            $corpo['tools'] = $ferramentas;
            $corpo['tool_choice'] = 'auto';
        }
        // Raciocínio mínimo e sem devolver o raciocínio: resposta de atendimento não precisa pensar muito (e cada token pensado é cobrado).
        // Só para os modelos da Anthropic, que aceitam o parâmetro; nos outros ele poderia dar erro.
        if (str_starts_with($modelo, 'anthropic/')) {
            $corpo['reasoning'] = ['effort' => 'low', 'exclude' => true];
        }

        $inicio = microtime(true);
        $r = $this->enviar('POST', self::base() . '/chat/completions', json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $ms = (int) round((microtime(true) - $inicio) * 1000);
        $vazio = ['ok' => false, 'mensagem' => [], 'texto' => '', 'chamadas' => [], 'tokens_in' => 0, 'tokens_out' => 0, 'custo_usd' => null, 'ms' => $ms, 'erro' => null, 'status' => $r['status']];

        $dados = json_decode($r['corpo'], true);
        $dados = is_array($dados) ? $dados : [];
        if ($r['erro'] !== null) {
            return ['erro' => 'Sem conexão com o OpenRouter: ' . mb_substr($r['erro'], 0, 150)] + $vazio;
        }
        $mensagem = $dados['choices'][0]['message'] ?? null;
        if ($r['status'] !== 200 || isset($dados['error']) || !is_array($mensagem)) {
            return ['erro' => self::erroLegivel($r['status'], $dados)] + $vazio;
        }

        $chamadas = [];
        foreach ($mensagem['tool_calls'] ?? [] as $chamada) {
            $argumentos = json_decode((string) ($chamada['function']['arguments'] ?? '{}'), true);
            $chamadas[] = [
                'id' => (string) ($chamada['id'] ?? ''),
                'nome' => (string) ($chamada['function']['name'] ?? ''),
                'argumentos' => is_array($argumentos) ? $argumentos : [],
            ];
        }
        $uso = $dados['usage'] ?? [];
        return [
            'ok' => true,
            'mensagem' => $mensagem,
            'texto' => trim((string) ($mensagem['content'] ?? '')),
            'chamadas' => $chamadas,
            'tokens_in' => (int) ($uso['prompt_tokens'] ?? 0),
            'tokens_out' => (int) ($uso['completion_tokens'] ?? 0),
            'custo_usd' => isset($uso['cost']) && is_numeric($uso['cost']) ? (float) $uso['cost'] : null,
            'ms' => $ms,
            'erro' => null,
            'status' => $r['status'],
        ];
    }

    /**
     * Transcrição de um áudio (D120): POST /audio/transcriptions com o áudio em base64 (bytes crus, sem "data:").
     * @return array{ok:bool, texto:string, custo_usd:?float, ms:int, erro:?string, status:int}
     */
    public function transcrever(string $modelo, string $base64, string $formato): array
    {
        $corpo = ['model' => $modelo, 'input_audio' => ['data' => $base64, 'format' => $formato], 'language' => 'pt'];
        $inicio = microtime(true);
        $r = $this->enviar('POST', self::base() . '/audio/transcriptions', json_encode($corpo, JSON_UNESCAPED_SLASHES));
        $ms = (int) round((microtime(true) - $inicio) * 1000);
        $dados = json_decode($r['corpo'], true);
        $dados = is_array($dados) ? $dados : [];
        if ($r['erro'] !== null) {
            return ['ok' => false, 'texto' => '', 'custo_usd' => null, 'ms' => $ms, 'erro' => 'Sem conexão com o OpenRouter: ' . mb_substr($r['erro'], 0, 150), 'status' => 0];
        }
        if ($r['status'] !== 200 || isset($dados['error']) || !is_string($dados['text'] ?? null)) {
            return ['ok' => false, 'texto' => '', 'custo_usd' => null, 'ms' => $ms, 'erro' => self::erroLegivel($r['status'], $dados), 'status' => $r['status']];
        }
        $custo = $dados['usage']['cost'] ?? null;
        return ['ok' => true, 'texto' => $dados['text'], 'custo_usd' => is_numeric($custo) ? (float) $custo : null, 'ms' => $ms, 'erro' => null, 'status' => 200];
    }

    /**
     * Saldo e uso da chave (GET /api/v1/key).
     * @return array{ok:bool, erro:?string, limite:?float, restante:?float, uso_total:float, uso_mes:float, uso_hoje:float, gratuito:bool, gratis_hoje:?array{usadas:int, limite:int}}
     */
    public function saldo(): array
    {
        $r = $this->enviar('GET', self::base() . '/key', null);
        $dados = json_decode($r['corpo'], true);
        $d = is_array($dados) && is_array($dados['data'] ?? null) ? $dados['data'] : null;
        if ($r['erro'] !== null || $r['status'] !== 200 || $d === null) {
            return ['ok' => false, 'erro' => $r['erro'] !== null ? 'Sem conexão com o OpenRouter.' : self::erroLegivel($r['status'], is_array($dados) ? $dados : []),
                'limite' => null, 'restante' => null, 'uso_total' => 0.0, 'uso_mes' => 0.0, 'uso_hoje' => 0.0, 'gratuito' => false, 'gratis_hoje' => null];
        }
        $gratis = $d['free_model_daily_requests'] ?? null;
        return [
            'ok' => true,
            'erro' => null,
            'limite' => isset($d['limit']) ? (float) $d['limit'] : null,
            'restante' => isset($d['limit_remaining']) ? (float) $d['limit_remaining'] : null,
            'uso_total' => (float) ($d['usage'] ?? 0),
            'uso_mes' => (float) ($d['usage_monthly'] ?? 0),
            'uso_hoje' => (float) ($d['usage_daily'] ?? 0),
            'gratuito' => (bool) ($d['is_free_tier'] ?? false),
            'gratis_hoje' => is_array($gratis) ? ['usadas' => (int) ($gratis['used'] ?? 0), 'limite' => (int) ($gratis['limit'] ?? 0)] : null,
        ];
    }

    /** @return array{status:int, corpo:string, erro:?string} */
    private function enviar(string $metodo, string $url, ?string $corpo): array
    {
        $cabecalhos = ['Authorization: Bearer ' . self::chave(), 'Content-Type: application/json', 'Accept: application/json'];
        return ($this->transporte ?? self::curl(...))($metodo, $url, $cabecalhos, $corpo);
    }

    /** @param list<string> $cabecalhos @return array{status:int, corpo:string, erro:?string} */
    private static function curl(string $metodo, string $url, array $cabecalhos, ?string $corpo): array
    {
        $conexao = curl_init($url);
        $opcoes = [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $cabecalhos,
        ];
        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = $corpo;
        }
        curl_setopt_array($conexao, $opcoes);
        $resposta = curl_exec($conexao);
        return [
            'status' => (int) curl_getinfo($conexao, CURLINFO_RESPONSE_CODE),
            'corpo' => $resposta === false ? '' : (string) $resposta,
            'erro' => $resposta === false ? curl_error($conexao) : null,
        ];
    }

    /** Mensagem pronta para o painel. Nunca inclui a chave. */
    private static function erroLegivel(int $status, array $dados): string
    {
        $detalhe = mb_substr((string) ($dados['error']['message'] ?? ''), 0, 200);
        return match (true) {
            $status === 401 => 'O OpenRouter não aceitou a chave (OPENROUTER_API_KEY). Confira o valor.',
            $status === 402 => 'Sem créditos no OpenRouter. Adicione créditos na conta.',
            $status === 429 => 'Limite de uso do modelo atingido. Tente de novo em instantes.',
            $status === 404 && stripos($detalhe, 'data policy') !== false => 'Nenhum provedor deste modelo atende à política de privacidade escolhida.',
            $detalhe !== '' => "OpenRouter {$status}: {$detalhe}",
            default => "OpenRouter respondeu {$status}.",
        };
    }
}
