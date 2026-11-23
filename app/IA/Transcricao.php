<?php
declare(strict_types=1);

namespace App\IA;

/**
 * Áudio do cliente no WhatsApp vira texto (D120). O conector baixa a mensagem de voz (ogg/opus) e manda junto; aqui ela vai ao
 * modelo de transcrição pelo OpenRouter (POST /audio/transcriptions) e o texto segue para o bot como se o cliente tivesse escrito.
 *
 * Só transcreve quando o assistente pode responder (ligado, com chave, com crédito, dentro do teto): sem IA, o áudio não teria
 * quem lesse. O custo entra no uso da IA com a origem "audio" (conta no crédito do cliente e no teto do mês, mas não no limite
 * de respostas por hora de cada cliente).
 */
final class Transcricao
{
    public const MODELO = 'openai/gpt-4o-mini-transcribe';
    /** US$ por minuto, para registrar o custo quando o OpenRouter não devolve o valor exato. */
    public const PRECO_MINUTO_USD = 0.003;
    /** Áudio maior que isto não é transcrito (o conector já corta antes; aqui é a segunda trava). */
    public const MAX_SEGUNDOS = 120;

    public function __construct(
        private readonly Assistente $assistente = new Assistente(),
        private readonly OpenRouter $api = new OpenRouter(),
        private readonly AssistenteRepository $repo = new AssistenteRepository(),
    ) {}

    /**
     * @param array{dados:string, formato:string, segundos:int} $audio dados em base64
     * @return array{texto:?string, motivo:?string} texto null = não transcreveu; motivo: indisponivel | longo | falhou
     */
    public function texto(array $audio, string $telefone): array
    {
        if ($this->assistente->indisponivel($telefone) !== null) {
            return ['texto' => null, 'motivo' => 'indisponivel'];
        }
        if ($audio['segundos'] > self::MAX_SEGUNDOS || $audio['dados'] === '') {
            return ['texto' => null, 'motivo' => 'longo'];
        }
        $r = $this->api->transcrever(self::MODELO, $audio['dados'], $audio['formato']);
        $custo = $r['custo_usd'] ?? round(max(1, $audio['segundos']) / 60 * self::PRECO_MINUTO_USD, 6);
        $this->repo->registrarUso('audio', $telefone, self::MODELO, 0, 0, $r['ok'] ? $custo : 0.0, $r['ms'], [], $r['ok'] ? null : $r['erro']);
        $texto = trim($r['texto']);
        if (!$r['ok'] || $texto === '') {
            error_log('[audio] transcrição falhou: ' . ($r['erro'] ?? 'texto vazio'));
            return ['texto' => null, 'motivo' => 'falhou'];
        }
        return ['texto' => mb_substr($texto, 0, 1000), 'motivo' => null];
    }
}
