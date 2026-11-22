<?php
// Versão 13 do esquema: acréscimo da massa, do molho e de cada ingrediente por tamanho do prato (D108).
//   * acrescimo_precos: o valor de uma opção num tamanho. Sem linha = vale o valor do cadastro (acrescimo_centavos ou
//     preco_extra_centavos), então nada muda até o restaurante preencher a tabela na aba "Preços dos pratos".
//   * opcao_id aponta para massas, molhos ou ingredientes conforme o tipo; a linha sai junto quando a opção é apagada (CardapioRepository::apagar).
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE acrescimo_precos (
      tipo           TEXT NOT NULL CHECK (tipo IN ('massas', 'molhos', 'ingredientes')),
      opcao_id       INTEGER NOT NULL,
      tamanho_id     INTEGER NOT NULL REFERENCES tamanhos (id) ON DELETE CASCADE,
      preco_centavos INTEGER NOT NULL CHECK (preco_centavos >= 0),
      PRIMARY KEY (tipo, opcao_id, tamanho_id)
    )");
};
