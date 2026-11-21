<?php
// Versão 12 do esquema: índices achados no teste de carga e na revisão (D102).
//   * pedidos.criado_em: o resumo de hoje do quadro (polling a cada poucos segundos), a tela Vendas e o calendário filtram por ela.
//   * fechamentos_caixa: um fechamento ativo por dia, garantido pelo banco (dois "Fechar o caixa" ao mesmo tempo não passam juntos).
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('CREATE INDEX idx_pedidos_criado ON pedidos (criado_em)');
    $pdo->exec('CREATE UNIQUE INDEX idx_fechamentos_um_por_dia ON fechamentos_caixa (data) WHERE cancelado_em IS NULL');
};
