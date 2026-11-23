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
    /** Nos testes: fn(string $url, ?string $corpo = null): ?string devolve o JSON da resposta (null = falhou). */
    public static $transporte = null;

    /** @return array{rua: string, numero: string, bairro: string}|null null = não achou rua e bairro */
    public static function endereco(float $lat, float $lng): ?array
    {
        $url = sprintf('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=pt-BR&lat=%F&lon=%F', $lat, $lng);
        $corpo = self::buscar($url);
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
        $corpo = self::buscar($url);
        $lista = $corpo !== null ? json_decode($corpo, true) : null;
        $primeiro = is_array($lista) ? ($lista[0] ?? null) : null;
        if (!is_array($primeiro) || !is_numeric($primeiro['lat'] ?? null) || !is_numeric($primeiro['lon'] ?? null)) {
            return null;
        }
        return ['lat' => (float) $primeiro['lat'], 'lng' => (float) $primeiro['lon']];
    }

    // ---------- Busca de endereço no mapa (D119) ----------
    // Como nos apps de entrega: a pessoa digita, aparecem sugestões, ela escolhe e o mapa vai para o ponto, onde confirma o pino.
    // Com GOOGLE_MAPS_API_KEY (variável de ambiente, nunca no navegador): Google Places (New), com a mesma sessão do autocomplete
    // ao detalhe (o Google cobra por sessão) e preferência pelos 15 km em volta da loja. Sem a chave: Photon (OpenStreetMap,
    // feito para autocompletar, sem chave), menos preciso no número da casa.

    public const RAIO_DE_PREFERENCIA_M = 15000;

    // D121: teto de uso do Google, no próprio servidor (a tela de cotas do Google Cloud não abria). Passou do teto, a busca segue
    // pelo Photon (grátis) até virar o dia ou o mês, no fuso da loja. O mês fica abaixo dos 10 mil eventos grátis de cada SKU.
    public const LIMITES_GOOGLE = [
        'sugestoes' => ['dia' => 1000, 'mes' => 9000],
        'detalhes' => ['dia' => 300, 'mes' => 9000],
    ];

    public static function usaGoogle(): bool
    {
        return \App\Core\Env::get('GOOGLE_MAPS_API_KEY') !== '';
    }

    /**
     * Sugestões para o texto digitado, as mais perto da loja primeiro.
     * @return list<array{id: string, principal: string, secundario: string}>
     */
    public static function sugestoes(string $texto, ?float $lat, ?float $lng, string $sessao): array
    {
        $texto = trim(mb_substr($texto, 0, 120));
        if (mb_strlen($texto) < 3) {
            return [];
        }
        return self::usaGoogle() && self::cabeNoLimite('sugestoes') ? self::sugestoesGoogle($texto, $lat, $lng, $sessao) : self::sugestoesPhoton($texto, $lat, $lng);
    }

    /**
     * O ponto e o endereço de uma sugestão escolhida.
     * @return array{lat: float, lng: float, rua: string, numero: string, bairro: string}|null
     */
    public static function detalhe(string $id, string $sessao): ?array
    {
        if (str_starts_with($id, 'osm:')) {   // Photon: a sugestão já traz o ponto e o endereço (só serve para posicionar o mapa)
            $d = json_decode((string) base64_decode(substr($id, 4), true), true);
            if (!is_array($d) || !is_numeric($d['lat'] ?? null) || !is_numeric($d['lng'] ?? null)) {
                return null;
            }
            return ['lat' => (float) $d['lat'], 'lng' => (float) $d['lng'], 'rua' => (string) ($d['rua'] ?? ''), 'numero' => (string) ($d['numero'] ?? ''), 'bairro' => (string) ($d['bairro'] ?? '')];
        }
        if (!self::usaGoogle() || preg_match('/^[A-Za-z0-9_-]{10,300}$/', $id) !== 1 || !self::cabeNoLimite('detalhes')) {
            return null;
        }
        $url = 'https://places.googleapis.com/v1/places/' . $id . '?languageCode=pt-BR&regionCode=BR&sessionToken=' . rawurlencode(self::sessaoValida($sessao));
        $dados = self::json(self::http($url, null, ['X-Goog-FieldMask: addressComponents,location']));
        if (!is_numeric($dados['location']['latitude'] ?? null) || !is_numeric($dados['location']['longitude'] ?? null)) {
            return null;
        }
        $parte = static function (array $tipos) use ($dados): string {
            foreach ($dados['addressComponents'] ?? [] as $c) {
                if (array_intersect($tipos, $c['types'] ?? []) !== []) {
                    return (string) ($c['longText'] ?? '');
                }
            }
            return '';
        };
        return [
            'lat' => (float) $dados['location']['latitude'], 'lng' => (float) $dados['location']['longitude'],
            'rua' => $parte(['route']), 'numero' => $parte(['street_number']),
            'bairro' => $parte(['sublocality_level_1', 'sublocality', 'neighborhood']),
        ];
    }

    private static function sugestoesGoogle(string $texto, ?float $lat, ?float $lng, string $sessao): array
    {
        $corpo = ['input' => $texto, 'languageCode' => 'pt-BR', 'includedRegionCodes' => ['br'], 'sessionToken' => self::sessaoValida($sessao)];
        if ($lat !== null && $lng !== null) {
            $corpo['locationBias'] = ['circle' => ['center' => ['latitude' => $lat, 'longitude' => $lng], 'radius' => (float) self::RAIO_DE_PREFERENCIA_M]];
        }
        $dados = self::json(self::http('https://places.googleapis.com/v1/places:autocomplete', (string) json_encode($corpo, JSON_UNESCAPED_UNICODE)));
        $lista = [];
        foreach ($dados['suggestions'] ?? [] as $s) {
            $p = $s['placePrediction'] ?? null;
            if (!is_array($p) || !isset($p['placeId'])) {
                continue;
            }
            $lista[] = [
                'id' => (string) $p['placeId'],
                'principal' => (string) ($p['structuredFormat']['mainText']['text'] ?? $p['text']['text'] ?? ''),
                'secundario' => (string) ($p['structuredFormat']['secondaryText']['text'] ?? ''),
            ];
        }
        return array_slice($lista, 0, 6);
    }

    private static function sugestoesPhoton(string $texto, ?float $lat, ?float $lng): array
    {
        $url = 'https://photon.komoot.io/api/?limit=8&q=' . rawurlencode($texto) . ($lat !== null && $lng !== null ? sprintf('&lat=%F&lon=%F', $lat, $lng) : '');
        $dados = self::json(self::http($url));
        $lista = [];
        foreach ($dados['features'] ?? [] as $f) {
            $p = $f['properties'] ?? [];
            $c = $f['geometry']['coordinates'] ?? null;
            if (($p['countrycode'] ?? '') !== 'BR' || !is_array($c) || !is_numeric($c[0] ?? null) || !is_numeric($c[1] ?? null)) {
                continue;
            }
            $rua = (string) ($p['street'] ?? (($p['osm_value'] ?? '') !== '' && ($p['osm_key'] ?? '') === 'highway' ? ($p['name'] ?? '') : ''));
            $numero = (string) ($p['housenumber'] ?? '');
            $bairro = (string) ($p['district'] ?? $p['locality'] ?? '');
            $principal = $rua !== '' ? $rua . ($numero !== '' ? ", {$numero}" : '') : (string) ($p['name'] ?? '');
            if ($principal === '') {
                continue;
            }
            $endereco = ['lat' => (float) $c[1], 'lng' => (float) $c[0], 'rua' => $rua, 'numero' => $numero, 'bairro' => $bairro];
            $lista[] = [
                'id' => 'osm:' . base64_encode((string) json_encode($endereco, JSON_UNESCAPED_UNICODE)),
                'principal' => $principal,
                'secundario' => implode(', ', array_filter([$bairro, (string) ($p['city'] ?? ''), (string) ($p['state'] ?? '')])),
            ];
        }
        return array_slice($lista, 0, 6);
    }

    /**
     * Conta mais uma chamada ao Google e diz se ela ainda cabe no teto do dia e do mês (D121). Os contadores ficam na tabela de
     * configurações ("google_uso:dia:2026-11-23:sugestoes"); os de dias e meses passados são apagados na virada.
     */
    public static function cabeNoLimite(string $tipo): bool
    {
        $agora = new \DateTimeImmutable('now', \App\Core\Relogio::fusoDaLoja());
        $chaves = ['dia' => 'google_uso:dia:' . $agora->format('Y-m-d') . ':' . $tipo, 'mes' => 'google_uso:mes:' . $agora->format('Y-m') . ':' . $tipo];
        $pdo = \App\Core\Banco::conexao();
        $usos = [];
        foreach ($chaves as $periodo => $chave) {
            $antes = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
            $antes->execute([$chave]);
            $atual = $antes->fetchColumn();
            if ($atual === false) {   // primeiro uso do período: apaga os contadores velhos deste tipo
                $pdo->prepare("DELETE FROM configuracoes WHERE chave LIKE ? AND chave <> ?")->execute(["google_uso:{$periodo}:%:{$tipo}", $chave]);
            }
            $usos[$periodo] = (int) $atual;
        }
        foreach (self::LIMITES_GOOGLE[$tipo] as $periodo => $limite) {
            if ($usos[$periodo] >= $limite) {
                return false;
            }
        }
        foreach ($chaves as $periodo => $chave) {
            $pdo->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON CONFLICT (chave) DO UPDATE SET valor = ?')
                ->execute([$chave, (string) ($usos[$periodo] + 1), (string) ($usos[$periodo] + 1)]);
        }
        return true;
    }

    /** O Google pede um identificador por sessão de busca; o navegador manda o dele e aqui só se confere o formato. */
    private static function sessaoValida(string $sessao): string
    {
        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $sessao) === 1 ? $sessao : bin2hex(random_bytes(16));
    }

    private static function json(?string $corpo): array
    {
        $dados = $corpo !== null ? json_decode($corpo, true) : null;
        return is_array($dados) ? $dados : [];
    }

    /** GET, ou POST com corpo JSON. A chave do Google vai no cabeçalho, só nas chamadas ao Google. */
    private static function http(string $url, ?string $corpo = null, array $cabecalhos = []): ?string
    {
        if (self::$transporte !== null) {
            return (self::$transporte)($url, $corpo);
        }
        // O Nominatim e o Photon pedem um nome de aplicativo; não vai dado nenhum do restaurante nem do cliente.
        $cabecalhos = ['User-Agent: PainelDePedidos/1.0', 'Accept: application/json', ...$cabecalhos];
        if (str_starts_with($url, 'https://places.googleapis.com/')) {
            $cabecalhos[] = 'X-Goog-Api-Key: ' . \App\Core\Env::get('GOOGLE_MAPS_API_KEY');
        }
        if ($corpo !== null) {
            $cabecalhos[] = 'Content-Type: application/json';
            $cabecalhos[] = 'X-Goog-FieldMask: suggestions.placePrediction.placeId,suggestions.placePrediction.structuredFormat,suggestions.placePrediction.text';
        }
        $opcoes = ['method' => $corpo !== null ? 'POST' : 'GET', 'timeout' => 5, 'ignore_errors' => true, 'header' => implode("\r\n", $cabecalhos) . "\r\n"];
        if ($corpo !== null) {
            $opcoes['content'] = $corpo;
        }
        $resposta = @file_get_contents($url, false, stream_context_create(['http' => $opcoes]));
        if ($resposta === false) {
            error_log('[geocodificador] sem resposta de ' . (string) parse_url($url, PHP_URL_HOST));
            return null;
        }
        return $resposta;
    }

    private static function buscar(string $url): ?string
    {
        return self::http($url);
    }
}
