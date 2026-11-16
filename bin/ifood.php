<?php
// Processo de fundo do iFood (D77): faz o polling de eventos a cada 30 s, mesmo com o painel fechado.
// O polling é também o que mantém a loja aberta no app do iFood, por isso não pode depender de alguém estar com o painel aberto.
// No contêiner ele sobe sozinho quando IFOOD_CLIENT_ID está definido (docker/entrypoint.sh). No computador:
//   php -d extension=pdo_sqlite bin/ifood.php            (fica rodando; Ctrl+C para parar)
//   php -d extension=pdo_sqlite bin/ifood.php --uma-vez  (um ciclo só)
// A trava no banco (ifood_conexao.proximo_polling_em) garante um polling por vez, mesmo com o painel ajudando ao mesmo tempo.
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Banco;
use App\Ifood\Ifood;
use App\Repositories\ConfigRepository;

$umaVez = in_array('--uma-vez', $argv ?? [], true);
fwrite(STDERR, "[ifood] processo de fundo ligado\n");

while (true) {
    try {
        ConfigRepository::esquecer();
        $r = (new Ifood())->cicloSeHora();
        if ($r['rodou'] && ($r['eventos'] > 0 || $r['acoes'] > 0)) {
            fwrite(STDERR, sprintf("[ifood] %s: %d evento(s), %d pedido(s) novo(s), %d etapa(s) enviada(s)\n", gmdate('H:i:s'), $r['eventos'], $r['pedidos'], $r['acoes']));
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[ifood] erro: ' . $e->getMessage() . "\n");
        Banco::esquecerConexao();   // conexão derrubada: abre outra na próxima volta
    }
    if ($umaVez) {
        break;
    }
    sleep(5);
}
