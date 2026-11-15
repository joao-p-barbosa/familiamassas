<?php
// Versão 6 do esquema: o pedido pelo WhatsApp passo a passo (D74).
//   * whatsapp_sessoes: o pedido em andamento de cada cliente no chat (em que passo está e o que já escolheu), uma linha por
//     telefone. Some quando o pedido é confirmado ou cancelado, ou depois de 30 minutos parado.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE whatsapp_sessoes (
      telefone      TEXT PRIMARY KEY,
      etapa         TEXT NOT NULL,
      dados         TEXT NOT NULL,
      atualizado_em TEXT NOT NULL
    )');
};
