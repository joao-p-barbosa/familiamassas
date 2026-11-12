<?php
declare(strict_types=1);

namespace App\WhatsApp;

/**
 * O webhook da Meta: confere a assinatura e transforma o JSON da Cloud API em mensagens para o bot.
 *
 * Formato (resumido): {"entry":[{"changes":[{"value":{"contacts":[{"profile":{"name":"Ana"}}],
 *   "messages":[{"id":"wamid...","from":"5511...","type":"text","text":{"body":"oi"}}], "statuses":[...]}}]}]}
 */
final class Webhook
{
    public function __construct(private readonly Bot $bot = new Bot(), private readonly Conversas $conversas = new Conversas()) {}

    /**
     * Verificação do GET (cadastro do webhook na Meta): devolve o hub.challenge se o verify token bater.
     * O PHP troca o "." dos nomes por "_": hub.mode chega como hub_mode.
     */
    public static function desafio(string $modo, string $tokenRecebido, string $desafio, string $tokenEsperado): ?string
    {
        return $modo === 'subscribe' && $tokenEsperado !== '' && hash_equals($tokenEsperado, $tokenRecebido) ? $desafio : null;
    }

    /** X-Hub-Signature-256 = "sha256=" + HMAC-SHA256 do corpo CRU com o app secret. Sem app secret, nada é aceito. */
    public static function assinaturaValida(string $corpo, string $cabecalho, string $segredo): bool
    {
        return $segredo !== '' && str_starts_with($cabecalho, 'sha256=')
            && hash_equals(hash_hmac('sha256', $corpo, $segredo), substr($cabecalho, 7));
    }

    /**
     * Processa um POST já validado.
     * @return array{mensagens:int, repetidas:int, falhas_de_entrega:int}
     */
    public function processar(array $dados): array
    {
        $resultado = ['mensagens' => 0, 'repetidas' => 0, 'falhas_de_entrega' => 0];
        foreach ($dados['entry'] ?? [] as $entrada) {
            foreach ($entrada['changes'] ?? [] as $mudanca) {
                $valor = is_array($mudanca['value'] ?? null) ? $mudanca['value'] : [];
                $nomes = [];
                foreach ($valor['contacts'] ?? [] as $contato) {
                    $nomes[(string) ($contato['wa_id'] ?? '')] = (string) ($contato['profile']['name'] ?? '');
                }
                foreach ($valor['messages'] ?? [] as $mensagem) {
                    $normal = self::normalizar($mensagem, $nomes);
                    if ($normal === null) {
                        continue;
                    }
                    $this->bot->receber($normal) === 'repetida' ? $resultado['repetidas']++ : $resultado['mensagens']++;
                }
                // Status das mensagens que enviamos (sent, delivered, read, failed): guardamos só as falhas, para a tela.
                foreach ($valor['statuses'] ?? [] as $status) {
                    if (($status['status'] ?? '') !== 'failed') {
                        continue;
                    }
                    $erro = $status['errors'][0] ?? [];
                    $novo = $this->conversas->registrarEvento('status.' . ($status['id'] ?? '') . '.failed', (string) ($status['recipient_id'] ?? ''), 'out', 'falha_entrega', [
                        'texto' => '', 'erro' => 'Meta ' . ($erro['code'] ?? '?') . ': ' . mb_substr((string) ($erro['title'] ?? $erro['message'] ?? 'falha na entrega'), 0, 200),
                    ]);
                    if ($novo) {
                        $resultado['falhas_de_entrega']++;
                    }
                }
            }
        }
        return $resultado;
    }

    /**
     * Uma mensagem da Meta no formato que o bot entende.
     * @param array<string,string> $nomes wa_id => nome do perfil
     * @return array{wamid:string, telefone:string, tipo:string, texto:string, botao:?string, nome:?string}|null
     */
    public static function normalizar(array $m, array $nomes = []): ?array
    {
        $wamid = (string) ($m['id'] ?? '');
        $telefone = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
        if ($wamid === '' || $telefone === '') {
            return null;
        }
        $tipo = (string) ($m['type'] ?? 'desconhecido');
        $texto = '';
        $botao = null;
        if ($tipo === 'text') {
            $texto = (string) ($m['text']['body'] ?? '');
        } elseif ($tipo === 'interactive') {
            $resposta = $m['interactive']['button_reply'] ?? $m['interactive']['list_reply'] ?? [];
            $botao = isset($resposta['id']) ? (string) $resposta['id'] : null;
            $texto = (string) ($resposta['title'] ?? '');
        } elseif ($tipo === 'button') {   // resposta rápida de um template
            $texto = (string) ($m['button']['text'] ?? '');
        }
        $nome = $nomes[$telefone] ?? null;
        return ['wamid' => $wamid, 'telefone' => $telefone, 'tipo' => $tipo, 'texto' => $texto, 'botao' => $botao, 'nome' => $nome !== '' ? $nome : null];
    }
}
