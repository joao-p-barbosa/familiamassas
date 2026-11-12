<?php
// Versão 2 do esquema (rodada de 12/11, tarde):
//   * atendimentos do salão: a conta de um cliente, que reúne vários pedidos e só fecha com o pagamento;
//   * ingredientes em três grupos: Proteínas, Acompanhamentos e Queijos (a rodada da manhã tinha juntado as proteínas
//     nos acompanhamentos, o que estava errado).
// Roda sozinha, uma vez, em banco que já existe. Banco novo já nasce assim (data/schema.sqlite.sql).
declare(strict_types=1);

return static function (PDO $pdo): void {
    // ---------- 1) Atendimentos ----------
    $pdo->exec('CREATE TABLE atendimentos (
      id             INTEGER PRIMARY KEY AUTOINCREMENT,
      cliente_nome   TEXT NOT NULL,
      modalidade     TEXT NOT NULL CHECK (modalidade IN (\'mesa\', \'viagem\')),
      mesa           TEXT,
      status         TEXT NOT NULL DEFAULT \'aberto\' CHECK (status IN (\'aberto\', \'encerrado\')),
      pagamento      TEXT CHECK (pagamento IN (\'pix\', \'cartao\', \'dinheiro\')),
      total_centavos INTEGER,
      aberto_por     INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,
      encerrado_por  INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,
      aberto_em      TEXT NOT NULL,
      encerrado_em   TEXT,
      atualizado_em  TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX idx_atendimentos_status ON atendimentos (status)');
    $pdo->exec('CREATE INDEX idx_atendimentos_encerrado ON atendimentos (encerrado_em)');
    $pdo->exec('ALTER TABLE pedidos ADD COLUMN atendimento_id INTEGER REFERENCES atendimentos (id)');
    $pdo->exec('CREATE INDEX idx_pedidos_atendimento ON pedidos (atendimento_id)');

    // Pedidos do salão que já existiam (feitos antes de haver atendimento): cada um vira o seu próprio atendimento.
    // Pedido concluído ou cancelado vira atendimento encerrado; o que ainda anda fica aberto.
    $insere = $pdo->prepare('INSERT INTO atendimentos (cliente_nome, modalidade, mesa, status, pagamento, total_centavos, aberto_por, encerrado_em, atualizado_em, aberto_em)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $liga = $pdo->prepare('UPDATE pedidos SET atendimento_id = ? WHERE id = ?');
    $antigos = $pdo->query("SELECT id, cliente_nome, tipo, mesa, status, pagamento, total_centavos, criado_por, status_atualizado_em, criado_em
                            FROM pedidos WHERE origem = 'balcao' AND atendimento_id IS NULL ORDER BY id")->fetchAll();
    foreach ($antigos as $p) {
        $encerrado = in_array($p['status'], ['concluido', 'cancelado'], true);
        $insere->execute([
            $p['cliente_nome'],
            $p['tipo'] === 'local' ? 'mesa' : 'viagem',
            $p['tipo'] === 'local' ? $p['mesa'] : null,
            $encerrado ? 'encerrado' : 'aberto',
            $encerrado && in_array($p['pagamento'], ['pix', 'cartao', 'dinheiro'], true) ? $p['pagamento'] : null,
            $encerrado ? ($p['status'] === 'concluido' ? (int) $p['total_centavos'] : 0) : null,
            $p['criado_por'],
            $encerrado ? $p['status_atualizado_em'] : null,
            $p['status_atualizado_em'],
            $p['criado_em'],
        ]);
        $liga->execute([(int) $pdo->lastInsertId(), $p['id']]);
    }

    // ---------- 2) Ingredientes: Proteínas, Acompanhamentos e Queijos ----------
    $existe = static function (string $nome) use ($pdo): bool {
        $consulta = $pdo->prepare('SELECT COUNT(*) FROM ingredientes WHERE nome = ?');
        $consulta->execute([$nome]);
        return (int) $consulta->fetchColumn() > 0;
    };
    // "Frango desfiado" passa a se chamar "Picadinho de frango" (mesmo ingrediente: preço, estado e pratos continuam).
    if (!$existe('Picadinho de frango') && $existe('Frango desfiado')) {
        $pdo->exec("UPDATE ingredientes SET nome = 'Picadinho de frango' WHERE nome = 'Frango desfiado'");
    }
    // Carne seca entra como a quarta proteína (preço de exemplo, editável no cadastro).
    if (!$existe('Carne seca')) {
        $pdo->exec("INSERT INTO ingredientes (nome, grupo, preco_extra_centavos, estado, ordem) VALUES ('Carne seca', 'Proteínas', 700, 'disponivel', 0)");
    }
    $proteinas = ['Carne moída', 'Picadinho de frango', 'Carne seca', 'Camarão'];
    $marcas = implode(', ', array_fill(0, count($proteinas), '?'));
    $pdo->prepare("UPDATE ingredientes SET grupo = 'Proteínas' WHERE nome IN ({$marcas})")->execute($proteinas);
    // Bacon, calabresa, presunto e tudo o que era "Vegetais" ficam em Acompanhamentos.
    $pdo->exec("UPDATE ingredientes SET grupo = 'Acompanhamentos' WHERE nome IN ('Bacon', 'Calabresa', 'Presunto') OR grupo = 'Vegetais'");

    // A lista passa a seguir a ordem Proteínas → Acompanhamentos → Queijos (o que for de outro grupo vem depois),
    // mantendo a ordem de antes dentro de cada grupo. As proteínas seguem a ordem pedida.
    $prioridade = ['Proteínas' => 0, 'Acompanhamentos' => 1, 'Queijos' => 2];
    $todos = $pdo->query('SELECT id, nome, grupo, ordem FROM ingredientes')->fetchAll();
    usort($todos, static function (array $a, array $b) use ($prioridade, $proteinas): int {
        $chave = static fn (array $i): array => [
            $prioridade[$i['grupo']] ?? 3,
            $i['grupo'] === 'Proteínas' ? (array_search($i['nome'], $proteinas, true) === false ? 99 : array_search($i['nome'], $proteinas, true)) : 0,
            (int) $i['ordem'],
            (int) $i['id'],
        ];
        return $chave($a) <=> $chave($b);
    });
    $reordena = $pdo->prepare('UPDATE ingredientes SET ordem = ? WHERE id = ?');
    foreach ($todos as $posicao => $ingrediente) {
        $reordena->execute([$posicao, $ingrediente['id']]);
    }
};
