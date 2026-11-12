<?php
// Dados iniciais para o banco novo: configurações, usuários e um cardápio de exemplo.
// Tudo aqui é editável depois pelo painel (Cardápio e Configurações).
declare(strict_types=1);

return static function (PDO $pdo): void {
    $agora = gmdate('Y-m-d H:i:s');
    $inserir = static function (string $tabela, array $linha) use ($pdo): int {
        $colunas = array_keys($linha);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $tabela, implode(', ', $colunas), implode(', ', array_fill(0, count($colunas), '?')));
        $pdo->prepare($sql)->execute(array_values($linha));
        return (int) $pdo->lastInsertId();
    };

    // ---------- Configurações da loja (suposições da Etapa 0: confirmar com o dono) ----------
    $todoDia = [['11:00', '23:00']];
    $config = [
        'nome_loja'             => 'Família Massas',
        'tema'                  => 'italia',
        'funcionamento'         => 'auto',                    // auto | aberta | fechada
        'horarios'              => json_encode(['0' => $todoDia, '1' => [], '2' => [], '3' => $todoDia, '4' => $todoDia, '5' => $todoDia, '6' => $todoDia]),
        'taxa_entrega_centavos' => '600',
        'area_entrega'          => 'Centro e bairros vizinhos',
        'tempo_retirada_min'    => '25',
        'tempo_entrega_min'     => '50',
        'whatsapp_loja'         => '5511900000000',
        'endereco_loja'         => 'Rua das Massas, Centro',
        'aceita_entrega'        => '1',
        'aceita_retirada'       => '1',
        'aceita_local'          => '1',
        'comanda_largura_mm'    => '80',
        'comanda_mostra_preco'  => '0',
        'polling_segundos'      => '5',
        'atraso_minutos'        => '20',                      // card do Kanban muda de cor depois disso
    ];
    foreach ($config as $chave => $valor) {
        $inserir('configuracoes', ['chave' => $chave, 'valor' => $valor]);
    }

    // ---------- Usuários do painel (senha inicial: trocar123) ----------
    foreach ([['Administrador', 'admin', 'admin'], ['Atendimento', 'atendente', 'atendente'], ['Cozinha', 'cozinha', 'cozinha']] as [$nome, $login, $perfil]) {
        $inserir('usuarios', [
            'nome' => $nome, 'login' => $login, 'senha_hash' => password_hash('trocar123', PASSWORD_DEFAULT),
            'perfil' => $perfil, 'ativo' => 1, 'criado_em' => $agora,
        ]);
    }

    // ---------- Monte seu prato ----------
    foreach ([
        ['Pequeno', 'P', '300 g', 2490, 3],
        ['Médio',   'M', '400 g', 3190, 4],
        ['Grande',  'G', '550 g', 3990, 6],
    ] as $i => [$nome, $sigla, $descricao, $preco, $inclusos]) {
        $inserir('tamanhos', compact('nome', 'sigla', 'descricao', 'inclusos') + ['preco_centavos' => $preco, 'ordem' => $i]);
    }

    $massas = [];
    foreach ([
        ['Penne',      'Tubinho que segura bem o molho', 0],
        ['Espaguete',  'Fio longo, o clássico', 0],
        ['Fusilli',    'Parafuso, bom com molhos encorpados', 0],
        ['Talharim',   'Fita larga de massa fresca', 0],
        ['Nhoque',     'De batata, feito na casa', 400],
        ['Sem glúten', 'Penne de arroz e milho', 500],
    ] as $i => [$nome, $descricao, $acrescimo]) {
        $massas[$nome] = $inserir('massas', compact('nome', 'descricao') + ['acrescimo_centavos' => $acrescimo, 'ordem' => $i]);
    }

    $molhos = [];
    foreach ([
        ['Sugo',           'Tomate, alho e manjericão', 0,   '#C2382B'],
        ['Bolonhesa',      'Sugo com carne moída', 300,      '#8E3B23'],
        ['Branco',         'Leite, manteiga e noz-moscada', 0, '#EFE3C4'],
        ['Pesto',          'Manjericão, parmesão e castanha', 400, '#5E8C3A'],
        ['Rosé',           'Sugo com creme de leite', 0,     '#E58A66'],
        ['Quatro queijos', 'Muçarela, parmesão, provolone e gorgonzola', 500, '#EBC96B'],
    ] as $i => [$nome, $descricao, $acrescimo, $cor]) {
        $molhos[$nome] = $inserir('molhos', compact('nome', 'descricao', 'cor') + ['acrescimo_centavos' => $acrescimo, 'ordem' => $i]);
    }

    $ingredientes = [];
    $ordem = 0;
    foreach ([
        'Proteínas' => [['Frango desfiado', 500], ['Bacon', 500], ['Calabresa', 400], ['Presunto', 400], ['Carne moída', 500], ['Camarão', 900]],
        'Vegetais'  => [['Brócolis', 300], ['Champignon', 400], ['Milho', 300], ['Ervilha', 300], ['Tomate-cereja', 300], ['Azeitona preta', 300], ['Palmito', 400], ['Cebola roxa', 300], ['Alho frito', 300], ['Manjericão', 300]],
        'Queijos'   => [['Muçarela', 400], ['Requeijão cremoso', 400], ['Parmesão', 400], ['Gorgonzola', 500]],
    ] as $grupo => $lista) {
        foreach ($lista as [$nome, $preco]) {
            $ingredientes[$nome] = $inserir('ingredientes', compact('nome', 'grupo') + ['preco_extra_centavos' => $preco, 'ordem' => $ordem++]);
        }
    }
    // Um ingrediente e uma massa já começam esgotados, para dar para ver o estado no cardápio.
    $pdo->exec("UPDATE ingredientes SET estado = 'esgotado' WHERE nome = 'Palmito'");

    // ---------- Categorias e itens ----------
    $casa      = $inserir('categorias', ['nome' => 'Pratos da casa', 'ordem' => 0]);
    $bebidas   = $inserir('categorias', ['nome' => 'Bebidas', 'ordem' => 1]);
    $sobremesa = $inserir('categorias', ['nome' => 'Sobremesas', 'ordem' => 2]);

    foreach ([
        ['Carbonara da casa',     'Espaguete, molho branco, bacon e parmesão', 'Espaguete', 'Branco',         ['Bacon', 'Parmesão']],
        ['Penne à bolonhesa',     'Penne, bolonhesa e muçarela',               'Penne',     'Bolonhesa',      ['Muçarela']],
        ['Talharim ao pesto',     'Talharim, pesto, tomate-cereja e parmesão', 'Talharim',  'Pesto',          ['Tomate-cereja', 'Parmesão']],
        ['Nhoque quatro queijos', 'Nhoque de batata, quatro queijos e alho frito', 'Nhoque', 'Quatro queijos', ['Alho frito']],
        ['Fusilli rosé com frango', 'Fusilli, rosé, frango desfiado, milho e requeijão', 'Fusilli', 'Rosé',   ['Frango desfiado', 'Milho', 'Requeijão cremoso']],
    ] as $i => [$nome, $descricao, $massa, $molho, $lista]) {
        $id = $inserir('itens', [
            'categoria_id' => $casa, 'tipo' => 'prato_casa', 'nome' => $nome, 'descricao' => $descricao,
            'massa_id' => $massas[$massa], 'molho_id' => $molhos[$molho], 'ordem' => $i,
        ]);
        foreach ($lista as $ingrediente) {
            $inserir('item_ingredientes', ['item_id' => $id, 'ingrediente_id' => $ingredientes[$ingrediente]]);
        }
    }

    foreach ([
        [$bebidas, 'Água mineral', 'Sem gás, 500 ml', 400, 'disponivel'],
        [$bebidas, 'Água com gás', '500 ml', 450, 'disponivel'],
        [$bebidas, 'Refrigerante lata', 'Cola, guaraná ou laranja, 350 ml', 650, 'disponivel'],
        [$bebidas, 'Suco de uva integral', 'Garrafa de 300 ml', 900, 'disponivel'],
        [$bebidas, 'Chá gelado de limão', 'Feito na casa, 400 ml', 800, 'esgotado'],
        [$sobremesa, 'Tiramisù', 'Mascarpone, café e cacau', 1690, 'disponivel'],
        [$sobremesa, 'Panna cotta', 'Com calda de frutas vermelhas', 1490, 'disponivel'],
        [$sobremesa, 'Cannoli', 'Dois, recheados com creme de ricota', 1290, 'disponivel'],
    ] as $i => [$categoria, $nome, $descricao, $preco, $estado]) {
        $inserir('itens', [
            'categoria_id' => $categoria, 'tipo' => 'simples', 'nome' => $nome, 'descricao' => $descricao,
            'preco_centavos' => $preco, 'estado' => $estado, 'ordem' => $i,
        ]);
    }
};
