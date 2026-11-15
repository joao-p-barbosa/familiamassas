<?php
// Versão 3 do esquema: conector do WhatsApp por QR code (D66 a D69).
//   * whatsapp_conector: estado e sinal de vida do processo Node, e se a fila de avisos está retida depois de uma queda;
//   * whatsapp_envios_pendentes.criado_em: para descartar aviso velho demais.
// Roda sozinha, uma vez, em banco que já existe (SQLite e Postgres usam o mesmo SQL). Banco novo já nasce assim.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE whatsapp_conector (
      id           INTEGER PRIMARY KEY CHECK (id = 1),
      estado       TEXT NOT NULL,
      estado_desde TEXT NOT NULL,
      visto_em     TEXT,
      qr           TEXT,
      fila_retida  INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('ALTER TABLE whatsapp_envios_pendentes ADD COLUMN criado_em TEXT');
};
