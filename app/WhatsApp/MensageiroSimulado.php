<?php
declare(strict_types=1);

namespace App\WhatsApp;

/**
 * Modo simulado: nada sai para a Meta. A mensagem "enviada" fica só no registro (whatsapp_eventos), e o simulador da tela
 * WhatsApp do painel mostra a conversa. Serve para testar o bot inteiro sem conta Meta, inclusive na hospedagem.
 */
final class MensageiroSimulado implements Mensageiro
{
    public function enviar(string $telefone, array $mensagem): array
    {
        return ['ok' => true, 'wamid' => 'sim.' . bin2hex(random_bytes(10)), 'erro' => null, 'transitorio' => false, 'janela_fechada' => false];
    }
}
