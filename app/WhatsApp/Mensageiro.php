<?php
declare(strict_types=1);

namespace App\WhatsApp;

/**
 * Quem entrega a mensagem: a Cloud API da Meta (CloudApi) ou o simulador (MensageiroSimulado), que só registra.
 * $mensagem é a parte da mensagem no formato da Cloud API (type + conteúdo), sem "messaging_product" e "to".
 */
interface Mensageiro
{
    /**
     * @param array<string,mixed> $mensagem
     * @return array{ok:bool, wamid:?string, erro:?string, transitorio:bool, janela_fechada:bool}
     *   transitorio: vale tentar de novo depois (rede, limite de envio, Meta fora do ar);
     *   janela_fechada: a Meta recusou mensagem livre porque a janela de 24 h do cliente fechou.
     */
    public function enviar(string $telefone, array $mensagem): array;
}
