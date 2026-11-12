<?php
declare(strict_types=1);

namespace App\WhatsApp;

/**
 * Envio pela Cloud API oficial da Meta: POST https://graph.facebook.com/<versão>/<id do número>/messages.
 * O token vai só no cabeçalho Authorization e nunca é registrado em log nem devolvido na mensagem de erro.
 */
final class CloudApi implements Mensageiro
{
    /** Códigos de erro da Meta em que vale tentar de novo mais tarde (limite de envio, instabilidade). */
    private const TRANSITORIOS = [1, 2, 4, 17, 80007, 130429, 131000, 131016, 131048, 131056];
    /** "Re-engagement message": fora da janela de 24 h só vale template. */
    private const JANELA_FECHADA = 131047;

    public function __construct(
        private readonly string $token,
        private readonly string $numeroId,
        private readonly string $versao = 'v23.0',
    ) {}

    public function enviar(string $telefone, array $mensagem): array
    {
        $url = sprintf('https://graph.facebook.com/%s/%s/messages', rawurlencode($this->versao), rawurlencode($this->numeroId));
        $corpo = (string) json_encode(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $telefone] + $mensagem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $conexao = curl_init($url);
        curl_setopt_array($conexao, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,   // o webhook precisa responder à Meta em poucos segundos
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
        ]);
        $resposta = curl_exec($conexao);
        $status = (int) curl_getinfo($conexao, CURLINFO_RESPONSE_CODE);
        $erroDeRede = curl_error($conexao);

        if ($resposta === false) {
            return self::falha('Sem conexão com a Meta: ' . $erroDeRede, true);
        }
        $dados = json_decode((string) $resposta, true);
        $dados = is_array($dados) ? $dados : [];
        if ($status >= 200 && $status < 300 && isset($dados['messages'][0]['id'])) {
            return ['ok' => true, 'wamid' => (string) $dados['messages'][0]['id'], 'erro' => null, 'transitorio' => false, 'janela_fechada' => false];
        }
        $codigo = (int) ($dados['error']['code'] ?? 0);
        $texto = mb_substr((string) ($dados['error']['message'] ?? 'resposta inesperada'), 0, 200);
        return self::falha("Meta {$status}/{$codigo}: {$texto}", $status >= 500 || $status === 429 || in_array($codigo, self::TRANSITORIOS, true), $codigo === self::JANELA_FECHADA);
    }

    /** @return array{ok:bool, wamid:?string, erro:?string, transitorio:bool, janela_fechada:bool} */
    private static function falha(string $erro, bool $transitorio, bool $janelaFechada = false): array
    {
        return ['ok' => false, 'wamid' => null, 'erro' => $erro, 'transitorio' => $transitorio, 'janela_fechada' => $janelaFechada];
    }
}
