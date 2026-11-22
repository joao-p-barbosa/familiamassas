<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Endereço de um ponto do mapa (D98): o pin que o cliente manda pelo WhatsApp vira rua, número e bairro, para ele não precisar
 * escrever. Usa o Nominatim (OpenStreetMap), o mesmo que o mapa do cardápio usa no navegador. Uma consulta por pin, com tempo
 * curto: se falhar, o bot volta a pedir o endereço escrito (nunca trava o pedido).
 */
final class Geocodificador
{
    /** Nos testes: fn(string $url): ?string devolve o JSON da resposta (null = falhou). */
    public static $transporte = null;

    /** @return array{rua: string, numero: string, bairro: string}|null null = não achou rua e bairro */
    public static function endereco(float $lat, float $lng): ?array
    {
        $url = sprintf('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=pt-BR&lat=%F&lon=%F', $lat, $lng);
        $corpo = self::$transporte !== null ? (self::$transporte)($url) : self::buscar($url);
        $dados = $corpo !== null ? json_decode($corpo, true) : null;
        $a = is_array($dados['address'] ?? null) ? $dados['address'] : [];
        $rua = (string) ($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? $a['path'] ?? $a['residential'] ?? '');
        $bairro = (string) ($a['suburb'] ?? $a['neighbourhood'] ?? $a['quarter'] ?? $a['city_district'] ?? $a['village'] ?? '');
        if ($rua === '' || $bairro === '') {
            return null;
        }
        return ['rua' => $rua, 'numero' => (string) ($a['house_number'] ?? ''), 'bairro' => $bairro];
    }

    /**
     * O ponto de um endereço escrito (D106): o cliente que conversa com a IA costuma escrever "Rua X, 120, Centro" em vez de mandar o pin.
     * A cidade da loja entra junto para não cair numa rua de mesmo nome em outro estado. É aproximado: a taxa sai pela faixa desse
     * ponto e o resumo avisa que foi calculada pelo endereço escrito.
     * @return array{lat: float, lng: float}|null
     */
    public static function coordenadas(string $endereco, string $cidade = ''): ?array
    {
        $busca = trim($endereco . ($cidade !== '' ? ", {$cidade}" : ''));
        if (mb_strlen($busca) < 6) {
            return null;
        }
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=br&accept-language=pt-BR&q=' . rawurlencode(mb_substr($busca, 0, 200));
        $corpo = self::$transporte !== null ? (self::$transporte)($url) : self::buscar($url);
        $lista = $corpo !== null ? json_decode($corpo, true) : null;
        $primeiro = is_array($lista) ? ($lista[0] ?? null) : null;
        if (!is_array($primeiro) || !is_numeric($primeiro['lat'] ?? null) || !is_numeric($primeiro['lon'] ?? null)) {
            return null;
        }
        return ['lat' => (float) $primeiro['lat'], 'lng' => (float) $primeiro['lon']];
    }

    private static function buscar(string $url): ?string
    {
        $contexto = stream_context_create(['http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            // A política do Nominatim pede um nome de aplicativo; não vai dado nenhum do restaurante nem do cliente.
            'header' => "User-Agent: PainelDePedidos/1.0\r\nAccept: application/json\r\n",
        ]]);
        $corpo = @file_get_contents($url, false, $contexto);
        if ($corpo === false) {
            error_log('[geocodificador] o Nominatim não respondeu');
            return null;
        }
        return $corpo;
    }
}
