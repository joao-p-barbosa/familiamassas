<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Banco;
use App\Core\Env;
use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Repositories\ConfigRepository;
use App\Services\LojaService;
use App\Services\PedidoService;

// Configurações da loja e situação da integração com o WhatsApp.
final class AdminConfigController
{
    public const TEMAS = ['italia', 'linho', 'pedra', 'grafite'];

    public function ler(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $c = new ConfigRepository();
        Resposta::json([
            'nome_loja' => $c->get('nome_loja'),
            'tema' => $c->get('tema', 'italia'),
            'funcionamento' => $c->get('funcionamento', 'auto'),
            'horarios' => (new LojaService())->horarios(),
            'taxa_entrega_centavos' => $c->inteiro('taxa_entrega_centavos'),
            'area_entrega' => $c->get('area_entrega'),
            'endereco_loja' => $c->get('endereco_loja'),
            'tempo_retirada_min' => $c->inteiro('tempo_retirada_min', 25),
            'tempo_entrega_min' => $c->inteiro('tempo_entrega_min', 50),
            'whatsapp_loja' => $c->get('whatsapp_loja'),
            'aceita_entrega' => $c->get('aceita_entrega', '1') === '1',
            'aceita_retirada' => $c->get('aceita_retirada', '1') === '1',
            'aceita_local' => $c->get('aceita_local', '1') === '1',
            'comanda_largura_mm' => $c->inteiro('comanda_largura_mm', 80),
            'comanda_mostra_preco' => $c->get('comanda_mostra_preco', '0') === '1',
            'polling_segundos' => $c->inteiro('polling_segundos', 5),
            'atraso_minutos' => $c->inteiro('atraso_minutos', 20),
            'estado_agora' => (new LojaService())->estado(),
        ]);
    }

    public function salvar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $d = $req->json();
        $erros = [];
        $texto = static fn (string $campo, int $max): string => mb_substr(trim((string) ($d[$campo] ?? '')), 0, $max);
        $numero = static function (string $campo, int $min, int $max, string $mensagem) use ($d, &$erros): int {
            $valor = $d[$campo] ?? null;
            if (!is_numeric($valor) || (int) $valor < $min || (int) $valor > $max) {
                $erros[$campo] = $mensagem;
                return $min;
            }
            return (int) $valor;
        };

        $nome = $texto('nome_loja', 40);
        if ($nome === '') { $erros['nome_loja'] = 'Informe o nome da loja.'; }

        $tema = $texto('tema', 20);
        if (!in_array($tema, self::TEMAS, true)) { $erros['tema'] = 'Escolha um dos temas.'; }

        $funcionamento = $texto('funcionamento', 10);
        if (!in_array($funcionamento, ['auto', 'aberta', 'fechada'], true)) { $erros['funcionamento'] = 'Escolha como a loja abre.'; }

        // Horários: {"0": [["11:00","15:00"],["18:00","23:00"]], ...}  (0 = domingo)
        $horarios = [];
        $enviados = is_array($d['horarios'] ?? null) ? $d['horarios'] : [];
        for ($dia = 0; $dia <= 6; $dia++) {
            $horarios[(string) $dia] = [];
            $faixas = is_array($enviados[$dia] ?? null) ? $enviados[$dia] : [];
            foreach (array_slice($faixas, 0, 3) as $faixa) {
                [$abre, $fecha] = [(string) ($faixa[0] ?? ''), (string) ($faixa[1] ?? '')];
                $hora = '/^([01]\d|2[0-3]):[0-5]\d$/';
                if (!preg_match($hora, $abre) || !preg_match($hora, $fecha) || $abre >= $fecha) {
                    $erros['horarios'] = 'Em cada faixa, o horário de abrir precisa vir antes do de fechar (até 23:59).';
                    continue;
                }
                $horarios[(string) $dia][] = [$abre, $fecha];
            }
            usort($horarios[(string) $dia], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }

        $whatsapp = $texto('whatsapp_loja', 30);
        if ($whatsapp !== '') {
            $whatsapp = PedidoService::normalizarTelefone($whatsapp) ?? '';
            if ($whatsapp === '') { $erros['whatsapp_loja'] = 'Informe o número com DDD, por exemplo (11) 91234-5678.'; }
        }

        $aceita = ['entrega' => !empty($d['aceita_entrega']), 'retirada' => !empty($d['aceita_retirada']), 'local' => !empty($d['aceita_local'])];
        if (!in_array(true, $aceita, true)) { $erros['aceita'] = 'Deixe pelo menos um tipo de pedido ligado.'; }

        $largura = (int) ($d['comanda_largura_mm'] ?? 80);
        if (!in_array($largura, [58, 80], true)) { $erros['comanda_largura_mm'] = 'Escolha 58 ou 80 mm.'; }

        $valores = [
            'nome_loja' => $nome,
            'tema' => $tema,
            'funcionamento' => $funcionamento,
            'horarios' => json_encode($horarios),
            'taxa_entrega_centavos' => (string) $numero('taxa_entrega_centavos', 0, 100_000, 'Informe a taxa em reais (0 se for grátis).'),
            'area_entrega' => $texto('area_entrega', 120),
            'endereco_loja' => $texto('endereco_loja', 120),
            'tempo_retirada_min' => (string) $numero('tempo_retirada_min', 5, 240, 'Informe de 5 a 240 minutos.'),
            'tempo_entrega_min' => (string) $numero('tempo_entrega_min', 5, 240, 'Informe de 5 a 240 minutos.'),
            'whatsapp_loja' => $whatsapp,
            'aceita_entrega' => $aceita['entrega'] ? '1' : '0',
            'aceita_retirada' => $aceita['retirada'] ? '1' : '0',
            'aceita_local' => $aceita['local'] ? '1' : '0',
            'comanda_largura_mm' => (string) $largura,
            'comanda_mostra_preco' => !empty($d['comanda_mostra_preco']) ? '1' : '0',
            'polling_segundos' => (string) $numero('polling_segundos', 3, 60, 'Informe de 3 a 60 segundos.'),
            'atraso_minutos' => (string) $numero('atraso_minutos', 5, 180, 'Informe de 5 a 180 minutos.'),
        ];
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        (new ConfigRepository())->salvar($valores);
        $this->ler($req);
    }

    /**
     * POST /api/admin/whatsapp/link   corpo: {"telefone": "..."}
     * Gera o mesmo link que o bot vai mandar ("?t=<token>"), para testar o fluxo antes de o bot existir.
     */
    public function linkDeTeste(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $telefone = PedidoService::normalizarTelefone((string) ($req->json()['telefone'] ?? ''));
        if ($telefone === null) {
            throw ErroHttp::validacao(['telefone' => 'Informe o número com DDD, por exemplo (11) 91234-5678.']);
        }
        $esquema = $req->https() ? 'https' : 'http';
        $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        Resposta::json([
            'url' => "{$esquema}://{$host}/?t=" . (new \App\Services\TokenWhatsApp())->gerar($telefone),
            'validade_minutos' => intdiv(\App\Services\TokenWhatsApp::VALIDADE_SEGUNDOS, 60),
        ]);
    }

    /** Situação da integração com o WhatsApp. Nunca devolve o valor de nenhum segredo, só se está preenchido. */
    public function whatsapp(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $pdo = Banco::conexao();
        $ontem = gmdate('Y-m-d H:i:s', time() - 86400);
        $conta = static function (string $sql, array $parametros = []) use ($pdo): int {
            $consulta = $pdo->prepare($sql);
            $consulta->execute($parametros);
            return (int) $consulta->fetchColumn();
        };
        $chaves = [];
        foreach (['WHATSAPP_TOKEN' => 'Token permanente', 'WHATSAPP_PHONE_NUMBER_ID' => 'ID do número', 'WHATSAPP_APP_SECRET' => 'App secret', 'WHATSAPP_VERIFY_TOKEN' => 'Verify token'] as $chave => $rotulo) {
            $chaves[] = ['chave' => $chave, 'rotulo' => $rotulo, 'preenchida' => Env::get($chave) !== ''];
        }
        Resposta::json([
            'configurado' => !in_array(false, array_column($chaves, 'preenchida'), true),
            'chaves' => $chaves,
            'https' => $req->https(),
            'recebidas_24h' => $conta("SELECT COUNT(*) FROM whatsapp_eventos WHERE direcao = 'in' AND criado_em >= ?", [$ontem]),
            'enviadas_24h' => $conta("SELECT COUNT(*) FROM whatsapp_eventos WHERE direcao = 'out' AND criado_em >= ?", [$ontem]),
            'envios_pendentes' => $conta('SELECT COUNT(*) FROM whatsapp_envios_pendentes'),
            'pedidos_whatsapp_hoje' => $conta("SELECT COUNT(*) FROM pedidos WHERE origem = 'whatsapp' AND criado_em >= ?", [\App\Core\Relogio::inicioDoDiaDaLoja()]),
        ]);
    }
}
