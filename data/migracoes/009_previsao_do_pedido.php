<?php
// Versão 9 do esquema: previsão de cada pedido guardada na hora em que ele entra (D99).
//   * pedidos.previsao_minutos: tempo fixo (entrega ou retirada) + o adicional pela fila da cozinha naquele momento.
//     Fica gravada para que os pedidos que chegam depois não mudem a previsão de quem já pediu.
//     Pedidos antigos ficam com NULL e seguem com o tempo fixo da configuração.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE pedidos ADD COLUMN previsao_minutos INTEGER');
};
