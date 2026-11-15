<?php
declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Env;

/**
 * Envio pelo conector (Node + Baileys), que roda no mesmo contêiner: POST http://127.0.0.1:3001/enviar.
 * O Node coloca a mensagem numa fila própria (uma a cada ~2 s, para não parecer rajada de robô) e responde na hora (202):
 * esperar o envio terminar estouraria o tempo do PHP e a mesma mensagem sairia duas vezes na nova tentativa.
 */
final class MensageiroConector implements Mensageiro
{
    public function enviar(string $telefone, array $mensagem): array
    {
        $url = rtrim(Env::get('CONECTOR_URL', 'http://127.0.0.1:3001'), '/') . '/enviar';
        $corpo = (string) json_encode(['telefone' => $telefone, 'texto' => Conector::texto($mensagem)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $conexao = curl_init($url);
        curl_setopt_array($conexao, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['X-Conector-Segredo: ' . Env::get('CONECTOR_SEGREDO'), 'Content-Type: application/json'],
        ]);
        $resposta = curl_exec($conexao);
        $status = (int) curl_getinfo($conexao, CURLINFO_RESPONSE_CODE);
        $erroDeRede = curl_error($conexao);
        if ($resposta === false) {
            return self::falha('Conector fora do ar: ' . $erroDeRede, true);
        }
        $dados = json_decode((string) $resposta, true);
        $dados = is_array($dados) ? $dados : [];
        if ($status === 202 && isset($dados['id'])) {
            return ['ok' => true, 'wamid' => 'qr.out.' . $dados['id'], 'erro' => null, 'transitorio' => false, 'janela_fechada' => false];
        }
        // 503 = WhatsApp desconectado; 429 = fila do conector cheia: tentar de novo mais tarde resolve. O resto não.
        return self::falha("Conector {$status}: " . mb_substr((string) ($dados['erro'] ?? 'resposta inesperada'), 0, 200), in_array($status, [429, 503], true));
    }

    /** @return array{ok:bool, wamid:?string, erro:?string, transitorio:bool, janela_fechada:bool} */
    private static function falha(string $erro, bool $transitorio): array
    {
        return ['ok' => false, 'wamid' => null, 'erro' => $erro, 'transitorio' => $transitorio, 'janela_fechada' => false];
    }
}
