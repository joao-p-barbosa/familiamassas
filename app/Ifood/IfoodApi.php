<?php
declare(strict_types=1);

namespace App\Ifood;

use App\Core\Env;
use App\Core\Segredo;
use App\Repositories\ConfigRepository;

/**
 * Cliente da Merchant API do iFood (https://developer.ifood.com.br), versão v1.0 dos módulos.
 *   * Autenticação: POST /authentication/v1.0/oauth/token (formulário). Aplicativo "centralizado" usa grantType=client_credentials;
 *     aplicativo "distribuído" (o modelo da Brendi: o dono autoriza a loja pelo Portal do Parceiro) usa o código de usuário
 *     (POST /authentication/v1.0/oauth/userCode) e depois grantType=authorization_code / refresh_token.
 *   * Eventos: GET /events/v1.0/events:polling (a cada 30 s; é também o que mantém a loja aberta no iFood) e
 *     POST /events/v1.0/events/acknowledgment.
 *   * Pedido: GET /order/v1.0/orders/{id} e as ações POST /order/v1.0/orders/{id}/{confirm|startPreparation|readyToPickup|dispatch|requestCancellation}.
 * Todos os caminhos estão em CAMINHOS: se o iFood mudar algum na homologação, muda só aqui.
 * O clientId e o clientSecret vêm das variáveis de ambiente IFOOD_CLIENT_ID e IFOOD_CLIENT_SECRET (se existirem, valem primeiro) ou dos
 * campos da tela iFood (D82): lá o secret fica cifrado no banco (App\Core\Segredo) e nunca volta para o navegador. Nunca entram em log
 * nem em mensagem de erro. O transporte HTTP é um parâmetro para os testes não precisarem de rede.
 */
final class IfoodApi
{
    public const URL = 'https://merchant-api.ifood.com.br';

    public const CAMINHOS = [
        'token' => '/authentication/v1.0/oauth/token',
        'codigo_usuario' => '/authentication/v1.0/oauth/userCode',
        'lojas' => '/merchant/v1.0/merchants',
        'polling' => '/events/v1.0/events:polling',
        'confirmar_eventos' => '/events/v1.0/events/acknowledgment',
        'pedido' => '/order/v1.0/orders/{id}',
        'acao' => '/order/v1.0/orders/{id}/{acao}',
        'motivos_cancelamento' => '/order/v1.0/orders/{id}/cancellationReasons',
    ];

    public const ACOES = ['confirm', 'startPreparation', 'readyToPickup', 'dispatch', 'requestCancellation'];

    /** @var (callable(string, string, list<string>, ?string): array{status:int, corpo:string, erro:?string})|null */
    private $transporte;

    /** Transporte de quem não passou um (PedidoService, painel): só os testes trocam, para nada sair para a internet. */
    private static $transportePadrao = null;

    public static function trocarTransportePadrao(?callable $transporte): void
    {
        self::$transportePadrao = $transporte;
    }

    /** @param (callable(string, string, list<string>, ?string): array{status:int, corpo:string, erro:?string})|null $transporte */
    public function __construct(?callable $transporte = null)
    {
        $this->transporte = $transporte;
    }

    /**
     * Credenciais do aplicativo: ambiente primeiro (os dois valores), depois os campos da tela iFood.
     * @return array{id:string, secret:string, origem:string, secret_ilegivel:bool} origem: ambiente | painel | nenhuma
     */
    public static function credenciais(): array
    {
        $id = Env::get('IFOOD_CLIENT_ID');
        $secret = Env::get('IFOOD_CLIENT_SECRET');
        if ($id !== '' && $secret !== '') {
            return ['id' => $id, 'secret' => $secret, 'origem' => 'ambiente', 'secret_ilegivel' => false];
        }
        try {
            $config = new ConfigRepository();
            $idSalvo = $config->get('ifood_client_id');
            $cifrado = $config->get('ifood_client_secret');
        } catch (\Throwable) {
            return ['id' => '', 'secret' => '', 'origem' => 'nenhuma', 'secret_ilegivel' => false];
        }
        $aberto = $cifrado === '' ? null : Segredo::decifrar($cifrado);
        return [
            'id' => $idSalvo,
            'secret' => (string) $aberto,
            'origem' => $idSalvo !== '' || $cifrado !== '' ? 'painel' : 'nenhuma',
            'secret_ilegivel' => $cifrado !== '' && $aberto === null,
        ];
    }

    public static function clientId(): string
    {
        return self::credenciais()['id'];
    }

    private static function clientSecret(): string
    {
        return self::credenciais()['secret'];
    }

    /** Com as duas credenciais o iFood é de verdade; sem elas, o painel fica no modo simulado. */
    public static function configurada(): bool
    {
        $c = self::credenciais();
        return $c['id'] !== '' && $c['secret'] !== '';
    }

    /** "distribuido" (padrão: o dono autoriza a loja com um código, como na Brendi) ou "centralizado" (credenciais da própria loja). */
    public static function tipoDeAplicativo(): string
    {
        $doAmbiente = Env::get('IFOOD_TIPO_APP');
        if (in_array($doAmbiente, ['centralizado', 'distribuido'], true)) {
            return $doAmbiente;
        }
        try {
            return (new ConfigRepository())->get('ifood_tipo_app') === 'centralizado' ? 'centralizado' : 'distribuido';
        } catch (\Throwable) {
            return 'distribuido';
        }
    }

    /** Endereço da API. Só muda por IFOOD_BASE_URL (testes com um servidor de mentira). */
    private static function base(): string
    {
        return rtrim(Env::get('IFOOD_BASE_URL', self::URL), '/');
    }

    // ---------- Autenticação ----------

    /** @return array{ok:bool, status:int, dados:array<string,mixed>, erro:?string} */
    public function pedirCodigoDeUsuario(): array
    {
        return $this->formulario(self::CAMINHOS['codigo_usuario'], ['clientId' => self::clientId()]);
    }

    /** Aplicativo centralizado: um token só com as credenciais. */
    public function tokenDoAplicativo(): array
    {
        return $this->formulario(self::CAMINHOS['token'], [
            'grantType' => 'client_credentials', 'clientId' => self::clientId(), 'clientSecret' => self::clientSecret(),
        ]);
    }

    /** Aplicativo distribuído: troca o código que o dono copiou do Portal do Parceiro por um token. */
    public function tokenDoCodigo(string $codigoDeAutorizacao, string $verificador): array
    {
        return $this->formulario(self::CAMINHOS['token'], [
            'grantType' => 'authorization_code', 'clientId' => self::clientId(), 'clientSecret' => self::clientSecret(),
            'authorizationCode' => $codigoDeAutorizacao, 'authorizationCodeVerifier' => $verificador,
        ]);
    }

    public function renovarToken(string $refreshToken): array
    {
        return $this->formulario(self::CAMINHOS['token'], [
            'grantType' => 'refresh_token', 'clientId' => self::clientId(), 'clientSecret' => self::clientSecret(),
            'refreshToken' => $refreshToken,
        ]);
    }

    // ---------- Loja, eventos e pedidos (com o token) ----------

    public function lojas(string $token): array
    {
        return $this->chamar('GET', self::CAMINHOS['lojas'], $token);
    }

    /** @return array{ok:bool, status:int, dados:array<mixed>, erro:?string} 204 = nenhum evento novo (dados vazio). */
    public function eventos(string $token, ?string $merchantId): array
    {
        $extra = $merchantId ? ['x-polling-merchants: ' . preg_replace('/[^A-Za-z0-9\-,]/', '', $merchantId)] : [];
        return $this->chamar('GET', self::CAMINHOS['polling'], $token, null, $extra);
    }

    /** @param list<string> $ids */
    public function confirmarEventos(string $token, array $ids): array
    {
        return $this->chamar('POST', self::CAMINHOS['confirmar_eventos'], $token, array_map(static fn (string $id): array => ['id' => $id], $ids));
    }

    public function pedido(string $token, string $pedidoId): array
    {
        return $this->chamar('GET', str_replace('{id}', rawurlencode($pedidoId), self::CAMINHOS['pedido']), $token);
    }

    /** Ações do pedido: o iFood responde 202 (aceito; o resultado chega depois, como evento). */
    public function acao(string $token, string $pedidoId, string $acao, ?array $corpo = null): array
    {
        if (!in_array($acao, self::ACOES, true)) {
            return ['ok' => false, 'status' => 0, 'dados' => [], 'erro' => 'Ação desconhecida: ' . $acao];
        }
        $caminho = str_replace(['{id}', '{acao}'], [rawurlencode($pedidoId), $acao], self::CAMINHOS['acao']);
        // Só o cancelamento tem corpo. As outras ações vão sem corpo: com um "[]" o readyToPickup responde 400 (visto no iFood real).
        return $this->chamar('POST', $caminho, $token, $corpo);
    }

    public function motivosDeCancelamento(string $token, string $pedidoId): array
    {
        return $this->chamar('GET', str_replace('{id}', rawurlencode($pedidoId), self::CAMINHOS['motivos_cancelamento']), $token);
    }

    // ---------- Transporte ----------

    /** @param array<string,string> $campos */
    private function formulario(string $caminho, array $campos): array
    {
        $r = $this->enviar('POST', self::base() . $caminho, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($campos));
        return self::resultado($r);
    }

    /** @param list<string> $extra */
    private function chamar(string $metodo, string $caminho, string $token, ?array $corpo = null, array $extra = []): array
    {
        $cabecalhos = array_merge(['Authorization: Bearer ' . $token, 'Accept: application/json'], $extra);
        if ($corpo !== null) {
            $cabecalhos[] = 'Content-Type: application/json';
        }
        $json = $corpo === null ? null : (string) json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return self::resultado($this->enviar($metodo, self::base() . $caminho, $cabecalhos, $json));
    }

    /** @param array{status:int, corpo:string, erro:?string} $r */
    private static function resultado(array $r): array
    {
        $dados = $r['corpo'] === '' ? [] : json_decode($r['corpo'], true);
        $dados = is_array($dados) ? $dados : [];
        if ($r['erro'] !== null) {
            return ['ok' => false, 'status' => 0, 'dados' => [], 'erro' => 'Sem conexão com o iFood: ' . mb_substr($r['erro'], 0, 150)];
        }
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        return ['ok' => $ok, 'status' => $r['status'], 'dados' => $dados, 'erro' => $ok ? null : self::erroLegivel($r['status'], $dados)];
    }

    /** Mensagem para o painel. Nunca inclui credencial nem token. */
    private static function erroLegivel(int $status, array $dados): string
    {
        $detalhe = (string) ($dados['error']['message'] ?? $dados['message'] ?? $dados['error_description'] ?? '');
        $detalhe = mb_substr(preg_replace('/\s+/', ' ', $detalhe) ?? '', 0, 200);
        return match (true) {
            $status === 401 => 'O iFood não aceitou as credenciais ou o token (401). Confira IFOOD_CLIENT_ID e IFOOD_CLIENT_SECRET, ou conecte a loja de novo.',
            $status === 403 => 'O iFood recusou o acesso (403). Confira se a loja autorizou o aplicativo (a permissão nova leva até 10 minutos). Se persistir com a loja autorizada, o iFood pode estar bloqueando o IP da hospedagem: abra um chamado no Portal do Desenvolvedor com o ID de referência da resposta.',
            $status === 404 => 'O iFood não encontrou o recurso (404)' . ($detalhe !== '' ? ": {$detalhe}" : '.'),
            $status === 429 => 'Muitas chamadas ao iFood em pouco tempo (429). Ele tenta de novo sozinho.',
            $status >= 500 => "O iFood está com instabilidade ({$status}). Ele tenta de novo sozinho.",
            $detalhe !== '' => "iFood {$status}: {$detalhe}",
            default => "O iFood respondeu {$status}.",
        };
    }

    /** @param list<string> $cabecalhos @return array{status:int, corpo:string, erro:?string} */
    private function enviar(string $metodo, string $url, array $cabecalhos, ?string $corpo): array
    {
        return ($this->transporte ?? self::$transportePadrao ?? self::curl(...))($metodo, $url, $cabecalhos, $corpo);
    }

    /** @param list<string> $cabecalhos @return array{status:int, corpo:string, erro:?string} */
    private static function curl(string $metodo, string $url, array $cabecalhos, ?string $corpo): array
    {
        $conexao = curl_init($url);
        $opcoes = [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $cabecalhos,
        ];
        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = $corpo;
        } elseif ($metodo === 'POST') {
            $opcoes[CURLOPT_POSTFIELDS] = '';   // POST sem corpo, com Content-Length: 0
        }
        curl_setopt_array($conexao, $opcoes);
        $resposta = curl_exec($conexao);
        return [
            'status' => (int) curl_getinfo($conexao, CURLINFO_RESPONSE_CODE),
            'corpo' => $resposta === false ? '' : (string) $resposta,
            'erro' => $resposta === false ? curl_error($conexao) : null,
        ];
    }
}
