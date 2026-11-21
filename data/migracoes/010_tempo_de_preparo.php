<?php
// Versão 10 do esquema: tempo de preparo de cada prato (D100).
//   * massas.preparo_min: minutos que um prato com esta massa leva na cozinha (Monte sua massa e prato da casa).
//   * itens.preparo_min: tempo próprio de um prato da casa (ex.: lasanha); NULL = usa o da massa dele.
// A previsão do pedido sai de uma linha do tempo da cozinha com esses tempos (TempoDePreparo::recalcularFila).
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE massas ADD COLUMN preparo_min INTEGER NOT NULL DEFAULT 15');
    $pdo->exec('ALTER TABLE itens ADD COLUMN preparo_min INTEGER');
};
