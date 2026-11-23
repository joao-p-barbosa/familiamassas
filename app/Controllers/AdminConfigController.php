<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Repositories\ConfigRepository;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Services\TaxaDeEntrega;

// Configurações da loja e situação da integração com o WhatsApp. (Impressora e comanda: IntegracoesController, tela Impressora.)
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
            'area_entrega' => $c->get('area_entrega'),
            'endereco_loja' => $c->get('endereco_loja'),
            'logo_loja' => $c->get('logo_loja'),
            'cozinha_capacidade' => $c->inteiro('cozinha_capacidade', 0),
            'whatsapp_loja' => $c->get('whatsapp_loja'),
            'aceita_entrega' => $c->get('aceita_entrega', '1') === '1',
            'aceita_retirada' => $c->get('aceita_retirada', '1') === '1',
            'aceita_mesa' => $c->get('aceita_mesa', '1') === '1',
            'polling_segundos' => $c->inteiro('polling_segundos', 5),
            'entrega_raio' => (new TaxaDeEntrega($c))->regras(),
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

        // Logo (D99): só um caminho que o upload do painel gerou.
        $logo = $texto('logo_loja', 60);
        if ($logo !== '' && !preg_match('#^/uploads/[a-f0-9]{24}\.(webp|jpg|png)$#', $logo)) {
            $erros['logo_loja'] = 'Envie a logo de novo.';
        }

        $aceita = ['entrega' => !empty($d['aceita_entrega']), 'retirada' => !empty($d['aceita_retirada']), 'mesa' => !empty($d['aceita_mesa'])];
        if (!in_array(true, $aceita, true)) { $erros['aceita'] = 'Deixe pelo menos um tipo de pedido ligado.'; }

        $valores = [
            'nome_loja' => $nome,
            'tema' => $tema,
            'funcionamento' => $funcionamento,
            'horarios' => json_encode($horarios),
            'area_entrega' => $texto('area_entrega', 120),
            'endereco_loja' => $texto('endereco_loja', 120),
            'cozinha_capacidade' => (string) $numero('cozinha_capacidade', 0, 100, 'Informe de 0 a 100 pratos (0 = sem limite).'),
            'logo_loja' => $logo,
            'whatsapp_loja' => $whatsapp,
            'aceita_entrega' => $aceita['entrega'] ? '1' : '0',
            'aceita_retirada' => $aceita['retirada'] ? '1' : '0',
            'aceita_mesa' => $aceita['mesa'] ? '1' : '0',
            'polling_segundos' => (string) $numero('polling_segundos', 3, 60, 'Informe de 3 a 60 segundos.'),
        ];
        if (is_array($d['entrega_raio'] ?? null)) {
            $valores += $this->validarRaio($d['entrega_raio'], $erros);
        }
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        (new ConfigRepository())->salvar($valores);
        \App\Services\TempoDePreparo::recalcularFila(\App\Core\Banco::conexao());   // capacidade ou tempos mudaram (D99)
        $this->ler($req);
    }

    /**
     * Taxa por raio (D98): {lat, lng, faixas: [{ate_km, taxa_centavos}], alem_centavos: int|null}.
     * Faixas vazias = volta a taxa fixa. Com faixas, o ponto da loja é obrigatório (é dele que a distância é medida).
     * @return array<string,string>
     */
    private function validarRaio(array $r, array &$erros): array
    {
        $faixas = [];
        foreach (array_slice(is_array($r['faixas'] ?? null) ? $r['faixas'] : [], 0, 12) as $f) {
            $km = $f['ate_km'] ?? null;
            $taxa = $f['taxa_centavos'] ?? null;
            if (!is_numeric($km) || (float) $km <= 0 || (float) $km > 100 || !is_numeric($taxa) || (int) $taxa < 0 || (int) $taxa > 100_000) {
                $erros['entrega_raio'] = 'Cada faixa precisa de uma distância (até 100 km) e de uma taxa em reais.';
                continue;
            }
            $faixas[] = ['ate_km' => round((float) $km, 2), 'taxa_centavos' => (int) $taxa];
        }
        usort($faixas, static fn (array $a, array $b): int => $a['ate_km'] <=> $b['ate_km']);
        if (count(array_unique(array_column($faixas, 'ate_km'))) !== count($faixas)) {
            $erros['entrega_raio'] = 'Duas faixas com a mesma distância: deixe uma só.';
        }
        $alem = $r['alem_centavos'] ?? null;
        if ($alem !== null && (!is_numeric($alem) || (int) $alem < 0 || (int) $alem > 100_000)) {
            $erros['entrega_raio'] = 'Informe a taxa de quem está mais longe, ou marque que não entrega.';
        }
        $lat = $r['lat'] ?? null;
        $lng = $r['lng'] ?? null;
        $temPonto = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180 && ((float) $lat !== 0.0 || (float) $lng !== 0.0);
        if ($faixas !== [] && !$temPonto) {
            $erros['entrega_raio'] = 'Marque o local da loja: a distância é medida a partir dele.';
        }
        return [
            'entrega_faixas' => json_encode(['faixas' => $faixas, 'alem_centavos' => $alem === null ? null : (int) $alem]),
            'loja_lat' => $temPonto ? (string) round((float) $lat, 6) : '',
            'loja_lng' => $temPonto ? (string) round((float) $lng, 6) : '',
        ];
    }

    // A situação da integração com o WhatsApp (GET /api/admin/whatsapp) fica em WhatsAppController::situacao.
}
