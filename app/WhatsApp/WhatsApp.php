<?php
declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Env;

/**
 * Configuração e envio do WhatsApp (Cloud API oficial da Meta), e o formato das mensagens.
 *
 * Modo simulado: sem WHATSAPP_TOKEN ou sem WHATSAPP_PHONE_NUMBER_ID (ou com WHATSAPP_SIMULAR=1) nada sai para a Meta. As
 * mensagens ficam só registradas e o simulador da tela WhatsApp do painel mostra a conversa: dá para testar o bot inteiro
 * sem conta Meta. Com as duas credenciais preenchidas, o envio é de verdade.
 */
final class WhatsApp
{
    /** Os três botões do bot (a API aceita no máximo 3; título com até 20 caracteres). */
    public const BOTOES = ['pedir' => 'Fazer pedido', 'acompanhar' => 'Acompanhar pedido', 'atendente' => 'Falar com atendente'];

    private static ?Mensageiro $mensageiro = null;

    public static function simulado(): bool
    {
        return Env::get('WHATSAPP_SIMULAR') === '1' || Env::get('WHATSAPP_TOKEN') === '' || Env::get('WHATSAPP_PHONE_NUMBER_ID') === '';
    }

    public static function mensageiro(): Mensageiro
    {
        return self::$mensageiro ??= self::simulado()
            ? new MensageiroSimulado()
            : new CloudApi(Env::get('WHATSAPP_TOKEN'), Env::get('WHATSAPP_PHONE_NUMBER_ID'), Env::get('WHATSAPP_API_VERSAO', 'v23.0'));
    }

    /** Os testes trocam o mensageiro por um que só guarda o que seria enviado (null volta ao normal). */
    public static function usarMensageiro(?Mensageiro $mensageiro): void
    {
        self::$mensageiro = $mensageiro;
    }

    /** Nome do template "utility" aprovado na Meta para avisar status fora da janela de 24 h. "nenhum" desliga esses avisos. */
    public static function modeloDeStatus(): ?string
    {
        $nome = Env::get('WHATSAPP_MODELO_STATUS', 'atualizacao_pedido');
        return $nome === 'nenhum' ? null : $nome;
    }

    /** Endereço público do site (para os links que o bot manda): APP_URL ou, sem ela, o endereço desta requisição. */
    public static function urlDoSite(): string
    {
        $url = Env::get('APP_URL');
        if ($url !== '') {
            return rtrim($url, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    // ---------- Formato das mensagens (Cloud API) ----------

    /** @return array<string,mixed> */
    public static function texto(string $texto): array
    {
        return ['type' => 'text', 'text' => ['body' => $texto, 'preview_url' => false]];
    }

    /**
     * Mensagem com botões de resposta.
     * @param array<string,string> $botoes id => título
     * @return array<string,mixed>
     */
    public static function botoes(string $texto, array $botoes): array
    {
        $lista = [];
        foreach (array_slice($botoes, 0, 3, true) as $id => $titulo) {
            $lista[] = ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => mb_substr($titulo, 0, 20)]];
        }
        return ['type' => 'interactive', 'interactive' => ['type' => 'button', 'body' => ['text' => $texto], 'action' => ['buttons' => $lista]]];
    }

    /** Mensagem com um botão que abre um link ("CTA URL"). @return array<string,mixed> */
    public static function link(string $texto, string $rotulo, string $url): array
    {
        return ['type' => 'interactive', 'interactive' => [
            'type' => 'cta_url',
            'body' => ['text' => $texto],
            'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => mb_substr($rotulo, 0, 20), 'url' => $url]],
        ]];
    }

    /**
     * Template aprovado na Meta (o único tipo de mensagem permitido fora da janela de 24 h).
     * @param list<string> $parametros os {{1}}, {{2}}... do corpo do template
     * @return array<string,mixed>
     */
    public static function modelo(string $nome, array $parametros, string $idioma = 'pt_BR'): array
    {
        return ['type' => 'template', 'template' => [
            'name' => $nome,
            'language' => ['code' => $idioma],
            'components' => [['type' => 'body', 'parameters' => array_map(static fn (string $p): array => ['type' => 'text', 'text' => $p], $parametros)]],
        ]];
    }

    /**
     * O que a mensagem diz, em forma simples: é o que fica no registro e o que a caixa de entrada e o simulador mostram.
     * @param array<string,mixed> $mensagem
     * @return array{texto:string, botoes?:array<string,string>, link?:array{rotulo:string, url:string}, modelo?:string}
     */
    public static function resumo(array $mensagem): array
    {
        return match ($mensagem['type'] ?? '') {
            'text' => ['texto' => (string) ($mensagem['text']['body'] ?? '')],
            'interactive' => ($mensagem['interactive']['type'] ?? '') === 'cta_url'
                ? ['texto' => (string) $mensagem['interactive']['body']['text'], 'link' => ['rotulo' => (string) $mensagem['interactive']['action']['parameters']['display_text'], 'url' => (string) $mensagem['interactive']['action']['parameters']['url']]]
                : ['texto' => (string) $mensagem['interactive']['body']['text'], 'botoes' => array_column(array_column($mensagem['interactive']['action']['buttons'], 'reply'), 'title', 'id')],
            'template' => ['texto' => 'Template ' . $mensagem['template']['name'] . ': ' . implode(' · ', array_column($mensagem['template']['components'][0]['parameters'] ?? [], 'text')), 'modelo' => (string) $mensagem['template']['name']],
            default => ['texto' => ''],
        };
    }

    /**
     * Envia e registra o envio (ou a falha) em whatsapp_eventos.
     * @param array<string,mixed> $mensagem
     * @return array{ok:bool, wamid:?string, erro:?string, transitorio:bool, janela_fechada:bool}
     */
    public static function enviar(string $telefone, array $mensagem, string $motivo): array
    {
        $resultado = self::mensageiro()->enviar($telefone, $mensagem);
        $resumo = self::resumo($mensagem) + ['motivo' => $motivo];
        if ($resultado['ok']) {
            (new Conversas())->registrarEvento((string) $resultado['wamid'], $telefone, 'out', (string) $mensagem['type'], $resumo);
        } else {
            (new Conversas())->registrarEvento('falha.' . bin2hex(random_bytes(8)), $telefone, 'out', 'falha', $resumo + ['erro' => $resultado['erro']]);
            error_log('[whatsapp] falha ao enviar (' . $motivo . '): ' . $resultado['erro']);
        }
        return $resultado;
    }
}
