<?php
// Versão 8 do esquema: preço fixo de cada prato da casa por tamanho (D87).
//   * item_precos: um preço por prato da casa e tamanho (Meio, Inteiro...). O prato da casa deixa de ser montado pelo cliente.
//   * Os pratos que já existem começam com o preço base do tamanho + acréscimos da massa e do molho (o que o cliente pagava
//     sem ingredientes extras); o restaurante ajusta depois na aba "Preços" do cardápio.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE item_precos (
      item_id        INTEGER NOT NULL REFERENCES itens (id) ON DELETE CASCADE,
      tamanho_id     INTEGER NOT NULL REFERENCES tamanhos (id) ON DELETE CASCADE,
      preco_centavos INTEGER NOT NULL CHECK (preco_centavos >= 0),
      PRIMARY KEY (item_id, tamanho_id)
    )');
    $pdo->exec("INSERT INTO item_precos (item_id, tamanho_id, preco_centavos)
      SELECT i.id, t.id, t.preco_centavos + COALESCE(ma.acrescimo_centavos, 0) + COALESCE(mo.acrescimo_centavos, 0)
      FROM itens i
      CROSS JOIN tamanhos t
      LEFT JOIN massas ma ON ma.id = i.massa_id
      LEFT JOIN molhos mo ON mo.id = i.molho_id
      WHERE i.tipo = 'prato_casa'");
};
