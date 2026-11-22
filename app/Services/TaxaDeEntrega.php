<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfigRepository;

/**
 * Taxa de entrega por raio de distância (D98). O restaurante cadastra faixas ("até 1 km: R$ 3", "até 7 km: R$ 8") e o valor de
 * quem está além da última (ou "não entrega"). A distância é em linha reta, do ponto da loja ao ponto que o cliente marcou no
 * mapa ou mandou pelo WhatsApp. O navegador calcula igual só para mostrar (public/assets/js/shared/taxa.js); vale o do servidor.
 *
 * Sem faixas cadastradas ou sem o ponto da loja, a entrega sai sem taxa (não há mais taxa fixa: o valor vem só das faixas).
 * Com faixas, o cardápio digital exige o ponto do cliente. No WhatsApp o bot tenta achar o ponto pelo endereço escrito, pede a
 * localização uma vez e, sem ela, a taxa fica a confirmar na entrega (D114/D115).
 *
 * Configuração: entrega_faixas = {"faixas": [{"ate_km": 1, "taxa_centavos": 300}, ...], "alem_centavos": 1200 | null},
 * loja_lat e loja_lng.
 */
final class TaxaDeEntrega
{
    public function __construct(private readonly ConfigRepository $config = new ConfigRepository()) {}

    /**
     * O ponto de um endereço escrito, para a taxa por distância (D115). Busca na cidade da loja e descarta o que cair a mais de
     * 60 km dela (rua de mesmo nome em outra cidade). Devolve null se não achou.
     * @return array{lat: float, lng: float}|null
     */
    public function pontoDoEndereco(string $rua, string $numero, string $bairro): ?array
    {
        if (trim($rua) === '' || trim($bairro) === '') {
            return null;
        }
        $partes = array_map('trim', explode(',', $this->config->get('endereco_loja')));
        $ponto = Geocodificador::coordenadas("{$rua}, {$numero}, {$bairro}", implode(', ', array_slice($partes, -2)));
        if ($ponto === null) {
            return null;
        }
        $r = $this->regras();
        return $r['lat'] === null || self::km($r['lat'], $r['lng'], $ponto['lat'], $ponto['lng']) <= 60 ? $ponto : null;
    }

    /** @return array{faixas: list<array{ate_km: float, taxa_centavos: int}>, alem_centavos: ?int, lat: ?float, lng: ?float} */
    public function regras(): array
    {
        $dados = json_decode($this->config->get('entrega_faixas', ''), true);
        $faixas = [];
        foreach (is_array($dados['faixas'] ?? null) ? $dados['faixas'] : [] as $f) {
            if (is_numeric($f['ate_km'] ?? null) && is_numeric($f['taxa_centavos'] ?? null)) {
                $faixas[] = ['ate_km' => (float) $f['ate_km'], 'taxa_centavos' => (int) $f['taxa_centavos']];
            }
        }
        usort($faixas, static fn (array $a, array $b): int => $a['ate_km'] <=> $b['ate_km']);
        $alem = $dados['alem_centavos'] ?? null;
        $lat = $this->config->get('loja_lat');
        $lng = $this->config->get('loja_lng');
        return [
            'faixas' => $faixas,
            'alem_centavos' => is_numeric($alem) ? (int) $alem : null,
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lng' => is_numeric($lng) ? (float) $lng : null,
        ];
    }

    /** As faixas valem quando há pelo menos uma e o ponto da loja está marcado. */
    public function porRaio(): bool
    {
        $r = $this->regras();
        return $r['faixas'] !== [] && $r['lat'] !== null && $r['lng'] !== null;
    }

    /**
     * @return array{taxa_centavos: int, km: ?float, atende: bool}
     *   atende=false: o ponto está além da última faixa e a loja não entrega mais longe.
     */
    public function calcular(?float $lat, ?float $lng): array
    {
        $fixa = ['taxa_centavos' => 0, 'km' => null, 'atende' => true];
        $r = $this->regras();
        if ($lat === null || $lng === null || !$this->porRaio()) {
            return $fixa;
        }
        $km = round(self::km($r['lat'], $r['lng'], $lat, $lng), 2);
        foreach ($r['faixas'] as $faixa) {
            if ($km <= $faixa['ate_km']) {
                return ['taxa_centavos' => $faixa['taxa_centavos'], 'km' => $km, 'atende' => true];
            }
        }
        return $r['alem_centavos'] !== null
            ? ['taxa_centavos' => $r['alem_centavos'], 'km' => $km, 'atende' => true]
            : ['taxa_centavos' => 0, 'km' => $km, 'atende' => false];
    }

    /** Distância em linha reta (haversine), em km. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $raio = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $raio * asin(min(1.0, sqrt($a)));
    }

    /** "1,3 km" */
    public static function kmTexto(float $km): string
    {
        return number_format($km, 1, ',', '.') . ' km';
    }
}
