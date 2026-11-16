<?php
declare(strict_types=1);

namespace App\Ifood;

use App\Core\Banco;
use App\Core\Relogio;
use App\Repositories\ConfigRepository;
use App\Repositories\PedidoRepository;
use App\Services\PedidoService;

/**
 * Integração com o iFood, no modelo das plataformas de cardápio (como a Brendi): o pedido feito no app do iFood entra no
 * mesmo Kanban dos outros (origem "ifood"), é impresso como os outros e cada avanço no quadro volta para o iFood.
 *
 *   1. Conexão (D76): aplicativo distribuído = o dono pede um código no painel, digita no Portal do Parceiro do iFood e cola
 *      de volta o código de autorização; centralizado = basta IFOOD_CLIENT_ID e IFOOD_CLIENT_SECRET. Sem credenciais, o painel
 *      fica no modo simulado (um botão cria um pedido no formato do iFood, que passa pelo mesmo caminho de um real).
 *   2. Ciclo (D77): a cada 30 s um processo faz o polling de eventos, grava cada evento uma vez só, busca o pedido novo,
 *      confirma (se a confirmação automática estiver ligada), confirma o recebimento dos eventos e tenta as ações pendentes.
 *      Quem roda o ciclo: o processo bin/ifood.php no contêiner e, de reserva, a consulta de pedidos do painel.
 *   3. Etapas (D78): Em preparo → startPreparation; Pronto/Aguardando retirada → readyToPickup; Saiu para entrega → dispatch
 *      (só quando quem entrega é a loja); Cancelar → requestCancellation. Cancelado ou concluído no iFood muda o nosso quadro.
 */
final class Ifood
{
    public const INTERVALO_POLLING_S = 30;
    private const TENTATIVAS = 5;
    /** Código que o app prefere ao cancelar, se o iFood o oferecer para aquele pedido. */
    private const CODIGO_CANCELAMENTO = '509';
    /** Pedido que o iFood ainda não deixa ler logo depois do evento (404): a documentação manda repetir por até 10 minutos. */
    private const PRAZO_BUSCA_S = 600;
    /** Sem confirmar em 8 minutos o iFood cancela o pedido sozinho (SLA da homologação). */
    public const PRAZO_CONFIRMACAO_MIN = 8;

    /** Etapas do iFood para o painel (fullCode dos eventos). */
    public const ROTULOS_STATUS = [
        'PLACED' => 'Novo no iFood', 'CONFIRMED' => 'Confirmado', 'PREPARATION_STARTED' => 'Em preparo', 'SEPARATION_STARTED' => 'Em separação',
        'SEPARATION_ENDED' => 'Separado', 'READY_TO_PICKUP' => 'Pronto para retirar', 'DISPATCHED' => 'Saiu para entrega', 'CONCLUDED' => 'Concluído',
        'CANCELLED' => 'Cancelado', 'CANCELLATION_REQUESTED' => 'Cancelamento pedido', 'CANCELLATION_REQUEST_FAILED' => 'Cancelamento recusado',
        'CONSUMER_CANCELLATION_REQUESTED' => 'Cliente pediu cancelamento', 'ASSIGN_DRIVER' => 'Entregador atribuído',
        'GOING_TO_ORIGIN' => 'Entregador a caminho da loja', 'ARRIVED_AT_ORIGIN' => 'Entregador na loja', 'COLLECTED' => 'Pedido coletado',
        'ARRIVED_AT_DESTINATION' => 'Entregador no destino', 'DELIVERY_RETURNING_TO_ORIGIN' => 'Voltando para a loja',
        'DELIVERY_RETURNED_TO_ORIGIN' => 'Devolvido à loja', 'DELIVERY_RETURN_CODE_REQUESTED' => 'Código de devolução pedido',
        'HANDSHAKE_DISPUTE' => 'Disputa aberta', 'HANDSHAKE_SETTLEMENT' => 'Disputa resolvida', 'ORDER_PATCHED' => 'Pedido modificado',
        'DELIVERY_ADDRESS_CHANGE_REQUESTED' => 'Endereço alterado', 'RECOMMENDED_PREPARATION_START' => 'Início de preparo sugerido',
        'DELIVERY_GROUP_ASSOCIATED' => 'Entrega agrupada', 'DELIVERY_DROP_CODE_REQUESTED' => 'Código de entrega pedido',
    ];

    /** Códigos curtos que o iFood também usa no campo "code" (Eventos de pedido, referência rápida). */
    private const CODIGOS_CURTOS = [
        'PLC' => 'PLACED', 'CFM' => 'CONFIRMED', 'PRS' => 'PREPARATION_STARTED', 'SPS' => 'SEPARATION_STARTED', 'SPE' => 'SEPARATION_ENDED',
        'RTP' => 'READY_TO_PICKUP', 'DSP' => 'DISPATCHED', 'CON' => 'CONCLUDED', 'CAN' => 'CANCELLED', 'CAR' => 'CANCELLATION_REQUESTED',
        'CARF' => 'CANCELLATION_REQUEST_FAILED', 'ADR' => 'ASSIGN_DRIVER', 'GTO' => 'GOING_TO_ORIGIN', 'AAO' => 'ARRIVED_AT_ORIGIN',
        'CLT' => 'COLLECTED', 'AAD' => 'ARRIVED_AT_DESTINATION', 'DRGO' => 'DELIVERY_RETURNING_TO_ORIGIN', 'DRDO' => 'DELIVERY_RETURNED_TO_ORIGIN',
        'DRCR' => 'DELIVERY_RETURN_CODE_REQUESTED', 'HSD' => 'HANDSHAKE_DISPUTE', 'HSS' => 'HANDSHAKE_SETTLEMENT', 'OPA' => 'ORDER_PATCHED',
        'DAR' => 'DELIVERY_ADDRESS_CHANGE_REQUESTED', 'RPS' => 'RECOMMENDED_PREPARATION_START', 'DGAC' => 'DELIVERY_GROUP_ASSOCIATED',
    ];

    /** O que o app não resolve sozinho: o cartão e o detalhe avisam que a loja precisa agir no Gestor de Pedidos do iFood. */
    private const ALERTAS_STATUS = [
        'HANDSHAKE_DISPUTE' => 'O cliente abriu uma disputa. Responda no Gestor de Pedidos do iFood antes de expirar.',
        'ORDER_PATCHED' => 'O cliente mudou os itens do pedido. Confira o pedido no Gestor de Pedidos do iFood.',
        'DELIVERY_ADDRESS_CHANGE_REQUESTED' => 'O cliente pediu para mudar o endereço. Confira no Gestor de Pedidos do iFood.',
        'CANCELLATION_REQUESTED' => 'Há um pedido de cancelamento em análise no iFood.',
        'CANCELLATION_REQUEST_FAILED' => 'O iFood recusou o cancelamento. Confira no Gestor de Pedidos do iFood.',
        'DELIVERY_RETURNING_TO_ORIGIN' => 'O entregador está devolvendo o pedido à loja.',
        'DELIVERY_RETURNED_TO_ORIGIN' => 'O pedido voltou para a loja. Receba e confira.',
        'DELIVERY_RETURN_CODE_REQUESTED' => 'O entregador pediu o código de devolução.',
        'DELIVERY_DROP_CODE_REQUESTED' => 'O iFood pediu o código de entrega (confirmação com o cliente). Informe no Gestor de Pedidos do iFood.',
    ];

    /** Etapas que OUTRO sistema (ex.: Gestor de Pedidos do iFood) fez e o nosso quadro acompanha, só para frente. */
    private const AVANCOS = ['PREPARATION_STARTED' => 'em_preparo', 'READY_TO_PICKUP' => 'pronto', 'DISPATCHED' => 'saiu_entrega'];

    /** Lista de motivos do pedido de teste (o iFood de verdade devolve a lista própria de cada pedido). */
    private const MOTIVOS_SIMULADOS = [
        ['codigo' => '501', 'descricao' => 'Problemas no sistema da loja'], ['codigo' => '503', 'descricao' => 'Item indisponível'],
        ['codigo' => '505', 'descricao' => 'Cardápio desatualizado'], ['codigo' => '509', 'descricao' => 'Dificuldades internas da loja'],
    ];

    private const FORMAS = [
        'CREDIT' => 'Crédito', 'DEBIT' => 'Débito', 'MEAL_VOUCHER' => 'Vale-refeição', 'FOOD_VOUCHER' => 'Vale-alimentação',
        'PIX' => 'Pix', 'CASH' => 'Dinheiro', 'DIGITAL_WALLET' => 'Carteira digital', 'GIFT_CARD' => 'Cartão-presente', 'OTHER' => 'Outro',
    ];

    public function __construct(
        private readonly IfoodApi $api = new IfoodApi(),
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
        private readonly ConfigRepository $config = new ConfigRepository(),
    ) {}

    // =====================================================================================================================
    // Conexão
    // =====================================================================================================================

    /** @return array<string,mixed> a linha única de ifood_conexao (criada na primeira vez) */
    private function conexao(): array
    {
        $pdo = Banco::conexao();
        $linha = $pdo->query('SELECT * FROM ifood_conexao WHERE id = 1')->fetch();
        if ($linha) {
            return $linha;
        }
        try {
            $pdo->prepare("INSERT INTO ifood_conexao (id, estado, atualizado_em) VALUES (1, 'desconectado', ?)")->execute([Relogio::agora()]);
        } catch (\PDOException) {
            // outro processo criou ao mesmo tempo
        }
        return $pdo->query('SELECT * FROM ifood_conexao WHERE id = 1')->fetch();
    }

    /** @param array<string,?string> $campos */
    private function gravarConexao(array $campos): void
    {
        $this->conexao();
        $campos['atualizado_em'] = Relogio::agora();
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($campos)));
        Banco::conexao()->prepare("UPDATE ifood_conexao SET {$sets} WHERE id = 1")->execute(array_values($campos));
    }

    private function registrarErro(string $erro): void
    {
        error_log('[ifood] ' . $erro);
        $this->gravarConexao(['ultimo_erro' => mb_substr($erro, 0, 300), 'ultimo_erro_em' => Relogio::agora()]);
    }

    /** A integração está ligada de verdade (credenciais + loja conectada)? */
    public function conectado(): bool
    {
        return IfoodApi::configurada() && $this->conexao()['estado'] === 'conectado';
    }

    /**
     * Centralizado: pega o token e a loja na hora. Distribuído: pede o código que o dono vai digitar no Portal do Parceiro.
     * @return array<string,mixed> a situação atualizada
     */
    public function conectar(): array
    {
        if (!IfoodApi::configurada()) {
            throw new \App\Core\ErroHttp(409, 'ifood_sem_credenciais', 'Faltam IFOOD_CLIENT_ID e IFOOD_CLIENT_SECRET nas variáveis de ambiente.');
        }
        if (IfoodApi::tipoDeAplicativo() === 'centralizado') {
            $r = $this->api->tokenDoAplicativo();
            if (!$r['ok']) {
                $this->registrarErro($r['erro']);
                throw new \App\Core\ErroHttp(502, 'ifood_token', $r['erro']);
            }
            $this->guardarToken($r['dados']);
            $this->descobrirLoja();
            return $this->situacao();
        }
        $r = $this->api->pedirCodigoDeUsuario();
        if (!$r['ok'] || empty($r['dados']['userCode'])) {
            $erro = $r['erro'] ?? 'O iFood não devolveu o código.';
            $this->registrarErro($erro);
            throw new \App\Core\ErroHttp(502, 'ifood_codigo', $erro);
        }
        $d = $r['dados'];
        $this->gravarConexao([
            'estado' => 'aguardando_codigo',
            'user_code' => (string) $d['userCode'],
            'verificador' => (string) ($d['authorizationCodeVerifier'] ?? ''),
            'url_autorizacao' => (string) ($d['verificationUrlComplete'] ?? $d['verificationUrl'] ?? 'https://portal.ifood.com.br/apps/code'),
            'codigo_expira_em' => gmdate('Y-m-d H:i:s', time() + max(60, (int) ($d['expiresIn'] ?? 600))),
            'ultimo_erro' => null,
        ]);
        return $this->situacao();
    }

    /** Distribuído: o dono colou o código de autorização que o Portal do Parceiro mostrou. */
    public function autorizar(string $codigo): array
    {
        $codigo = trim($codigo);
        $c = $this->conexao();
        if ($c['estado'] !== 'aguardando_codigo' || ($c['verificador'] ?? '') === '') {
            throw new \App\Core\ErroHttp(409, 'ifood_sem_codigo', 'Peça um código novo em "Conectar loja" antes de autorizar.');
        }
        if ($codigo === '') {
            throw \App\Core\ErroHttp::validacao(['codigo' => 'Cole o código de autorização que o iFood mostrou.']);
        }
        $r = $this->api->tokenDoCodigo($codigo, (string) $c['verificador']);
        if (!$r['ok']) {
            $this->registrarErro($r['erro']);
            throw new \App\Core\ErroHttp(502, 'ifood_autorizacao', $r['erro']);
        }
        $this->guardarToken($r['dados']);
        $this->gravarConexao(['user_code' => null, 'verificador' => null, 'url_autorizacao' => null, 'codigo_expira_em' => null]);
        $this->descobrirLoja();
        return $this->situacao();
    }

    public function desconectar(): array
    {
        $this->gravarConexao([
            'estado' => 'desconectado', 'access_token' => null, 'refresh_token' => null, 'token_expira_em' => null,
            'user_code' => null, 'verificador' => null, 'url_autorizacao' => null, 'codigo_expira_em' => null,
        ]);
        return $this->situacao();
    }

    /**
     * Grava as credenciais digitadas na tela iFood (D82). O Client Secret é cifrado (App\Core\Segredo) e nunca volta para o
     * navegador; em branco, mantém o que já estava salvo. Mudar o Client ID, o secret ou o tipo do aplicativo desconecta a loja:
     * os tokens e a loja autorizada pertencem às credenciais antigas.
     * @return array<string,mixed> a situação atualizada
     */
    public function salvarCredenciais(string $clientId, string $secret, string $tipo): array
    {
        $atual = IfoodApi::credenciais();
        if ($atual['origem'] === 'ambiente') {
            throw new \App\Core\ErroHttp(409, 'ifood_credenciais_no_ambiente', 'As credenciais vêm das variáveis de ambiente do servidor (IFOOD_CLIENT_ID e IFOOD_CLIENT_SECRET). Para trocar por aqui, apague essas variáveis na Render.');
        }
        if (!\App\Core\Segredo::disponivel()) {
            throw new \App\Core\ErroHttp(409, 'ifood_sem_app_key', 'O servidor está sem APP_KEY, que protege o Client Secret. Defina APP_KEY nas variáveis de ambiente.');
        }
        $clientId = trim($clientId);
        $secret = trim($secret);
        $erros = [];
        if (preg_match('/^[A-Za-z0-9._\-]{6,120}$/', $clientId) !== 1) {
            $erros['client_id'] = 'Cole o Client ID do aplicativo (6 a 120 letras, números, ponto, hífen ou sublinhado, sem espaços).';
        }
        if ($secret === '' && $atual['secret'] === '') {
            $erros['client_secret'] = 'Cole o Client Secret do aplicativo.';
        } elseif ($secret !== '' && preg_match('/^\S{6,300}$/', $secret) !== 1) {
            $erros['client_secret'] = 'O Client Secret não pode ter espaços nem quebras de linha (6 a 300 caracteres).';
        }
        if (!in_array($tipo, ['distribuido', 'centralizado'], true)) {
            $erros['tipo_app'] = 'Escolha o tipo do aplicativo.';
        }
        if ($erros !== []) {
            throw \App\Core\ErroHttp::validacao($erros);
        }
        $mudou = $clientId !== $atual['id'] || $secret !== '' || $tipo !== IfoodApi::tipoDeAplicativo();
        $valores = ['ifood_client_id' => $clientId, 'ifood_tipo_app' => $tipo];
        if ($secret !== '') {
            $valores['ifood_client_secret'] = \App\Core\Segredo::cifrar($secret);
        }
        $this->config->salvar($valores);
        if ($mudou) {
            $this->esquecerLoja();
        }
        return $this->situacao();
    }

    /** Apaga as credenciais guardadas pela tela (as do ambiente não são tocadas) e desconecta. */
    public function removerCredenciais(): array
    {
        if (IfoodApi::credenciais()['origem'] === 'ambiente') {
            throw new \App\Core\ErroHttp(409, 'ifood_credenciais_no_ambiente', 'As credenciais vêm das variáveis de ambiente do servidor: só dá para tirá-las na Render.');
        }
        $this->config->salvar(['ifood_client_id' => '', 'ifood_client_secret' => '', 'ifood_tipo_app' => '']);
        $this->esquecerLoja();
        return $this->situacao();
    }

    /** Credenciais novas = outro aplicativo: tokens e loja antigos não valem mais. */
    private function esquecerLoja(): void
    {
        $this->gravarConexao([
            'estado' => 'desconectado', 'access_token' => null, 'refresh_token' => null, 'token_expira_em' => null,
            'user_code' => null, 'verificador' => null, 'url_autorizacao' => null, 'codigo_expira_em' => null,
            'merchant_id' => null, 'merchant_nome' => null, 'ultimo_erro' => null,
        ]);
    }

    /** @param array<string,mixed> $dados resposta do /oauth/token */
    private function guardarToken(array $dados): void
    {
        $token = (string) ($dados['accessToken'] ?? '');
        if ($token === '') {
            throw new \App\Core\ErroHttp(502, 'ifood_token', 'O iFood não devolveu o token de acesso.');
        }
        $campos = [
            'estado' => 'conectado',
            'access_token' => $token,
            // A validade vem na resposta (expiresIn); a documentação pede para nunca depender de um tempo fixo.
            'token_expira_em' => gmdate('Y-m-d H:i:s', time() + max(60, (int) ($dados['expiresIn'] ?? 21600))),
            'ultimo_erro' => null,
        ];
        if (!empty($dados['refreshToken'])) {
            $campos['refresh_token'] = (string) $dados['refreshToken'];
        }
        $this->gravarConexao($campos);
        $this->config->salvar(['ifood_token_renovado_em' => (string) time(), 'ifood_token_espera_ate' => '0']);
    }

    /** A primeira loja que o token enxerga (o piloto tem uma loja só). */
    private function descobrirLoja(): void
    {
        $token = $this->token();
        if ($token === null) {
            return;
        }
        $r = $this->api->lojas($token);
        $loja = $r['ok'] ? ($r['dados'][0] ?? null) : null;
        if (is_array($loja) && !empty($loja['id'])) {
            $this->gravarConexao(['merchant_id' => (string) $loja['id'], 'merchant_nome' => mb_substr((string) ($loja['name'] ?? ''), 0, 120)]);
        } elseif (!$r['ok']) {
            $this->registrarErro('Não deu para ler a loja: ' . $r['erro']);
        }
    }

    /**
     * Conectado mas sem loja: o lojista acabou de aprovar e a permissão leva até 10 minutos para valer em toda a plataforma.
     * Procura de novo, no máximo uma vez por minuto (e, no aplicativo centralizado, só com um token novo, que é o que traz a permissão nova).
     */
    private function procurarLojaSeFalta(): void
    {
        $c = $this->conexao();
        if ($c['estado'] !== 'conectado' || !empty($c['merchant_id'])) {
            return;
        }
        if (time() - (int) $this->config->get('ifood_busca_loja_em', '0') < 60) {
            return;
        }
        $this->config->salvar(['ifood_busca_loja_em' => (string) time()]);
        if (IfoodApi::tipoDeAplicativo() === 'centralizado' && time() - (int) $this->config->get('ifood_token_renovado_em', '0') >= 120) {
            $this->gravarConexao(['token_expira_em' => '2000-01-01 00:00:00']);
        }
        $this->descobrirLoja();
    }

    /**
     * Token válido. Segue a documentação: renova SÓ quando venceu (nunca antes) e, se a renovação falha, espera 2 minutos para
     * tentar de novo (pedir token demais bloqueia o aplicativo). null = não conectado.
     */
    private function token(): ?string
    {
        $c = $this->conexao();
        if ($c['estado'] !== 'conectado' || !IfoodApi::configurada()) {
            return null;
        }
        if (($c['access_token'] ?? '') !== '' && ($c['token_expira_em'] ?? '') > Relogio::agora()) {
            return (string) $c['access_token'];
        }
        if (time() < (int) $this->config->get('ifood_token_espera_ate', '0')) {
            return null;
        }
        $centralizado = IfoodApi::tipoDeAplicativo() === 'centralizado';
        if (!$centralizado && ($c['refresh_token'] ?? '') === '') {
            $this->gravarConexao(['estado' => 'erro']);
            $this->registrarErro('O acesso ao iFood venceu. Conecte a loja de novo.');
            return null;
        }
        $r = $centralizado ? $this->api->tokenDoAplicativo() : $this->api->renovarToken((string) $c['refresh_token']);
        if (!$r['ok']) {
            $this->config->salvar(['ifood_token_espera_ate' => (string) (time() + 120)]);
            $this->registrarErro('A renovação do token falhou: ' . $r['erro']);
            if (!$centralizado && in_array($r['status'], [400, 401, 403], true)) {
                $this->gravarConexao(['estado' => 'erro']);   // o refresh token venceu (168 h): o dono precisa autorizar a loja de novo
            }
            return null;
        }
        $this->guardarToken($r['dados']);
        return (string) $this->conexao()['access_token'];
    }

    /** O iFood respondeu 401: o token não vale mais. Renova na próxima volta, mas só se a última renovação foi há mais de 2 minutos. */
    private function tokenRecusado(): void
    {
        if (time() - (int) $this->config->get('ifood_token_renovado_em', '0') < 120) {
            return;
        }
        $this->gravarConexao(['token_expira_em' => '2000-01-01 00:00:00']);
    }

    // =====================================================================================================================
    // Ciclo: polling, eventos e ações pendentes
    // =====================================================================================================================

    /**
     * Roda o ciclo se já deu a hora (no máximo um a cada 30 s, em qualquer número de processos: a trava é no banco).
     * Nunca lança erro: o painel e o processo de fundo seguem mesmo com o iFood fora do ar.
     * @return array{rodou:bool, eventos:int, pedidos:int, acoes:int}
     */
    public function cicloSeHora(): array
    {
        $nada = ['rodou' => false, 'eventos' => 0, 'pedidos' => 0, 'acoes' => 0];
        try {
            if (!IfoodApi::configurada() && !$this->temAcoesPendentes()) {
                return $nada;
            }
            $this->conexao();
            $agora = Relogio::agora();
            $sql = Banco::conexao()->prepare('UPDATE ifood_conexao SET proximo_polling_em = ? WHERE id = 1 AND (proximo_polling_em IS NULL OR proximo_polling_em <= ?)');
            $sql->execute([gmdate('Y-m-d H:i:s', time() + self::INTERVALO_POLLING_S - 2), $agora]);
            if ($sql->rowCount() !== 1) {
                return $nada;
            }
            return ['rodou' => true] + $this->ciclo();
        } catch (\Throwable $e) {
            error_log('[ifood] ciclo: ' . $e->getMessage());
            return $nada;
        }
    }

    /** @return array{eventos:int, pedidos:int, acoes:int} */
    public function ciclo(): array
    {
        $eventos = 0;
        $novos = 0;
        $token = $this->token();
        if ($token !== null) {
            $this->procurarLojaSeFalta();
            $c = $this->conexao();
            $r = $this->api->eventos($token, $c['merchant_id'] ?: null);
            if (!$r['ok']) {
                $this->registrarErro('Polling: ' . $r['erro']);
                if ($r['status'] === 401) {
                    $this->tokenRecusado();
                }
            } else {
                $this->gravarConexao(['ultimo_polling_em' => Relogio::agora()]);
                $lista = array_values(array_filter($r['dados'], 'is_array'));
                // O iFood pode entregar os eventos fora de ordem: processa pela hora em que cada um foi criado.
                usort($lista, static fn (array $a, array $b): int => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));
                $confirmar = [];
                foreach ($lista as $evento) {
                    $id = (string) ($evento['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $resultado = $this->receberEvento($evento, $token);
                    if ($resultado !== null) {   // null = o pedido ainda não pôde ser lido: sem confirmar, o iFood manda o evento de novo
                        $confirmar[] = $id;
                        $eventos++;
                        $novos += $resultado === 'pedido_criado' ? 1 : 0;
                    }
                }
                // Só depois de gravar cada evento é que ele é confirmado (acknowledgment): receber → guardar → confirmar.
                if ($confirmar !== []) {
                    $ack = $this->api->confirmarEventos($token, array_values(array_unique($confirmar)));
                    if (!$ack['ok']) {
                        $this->registrarErro('Confirmação de eventos: ' . $ack['erro']);
                    }
                }
            }
        }
        return ['eventos' => $eventos, 'pedidos' => $novos, 'acoes' => $this->processarAcoes(10)];
    }

    /**
     * Grava e processa um evento. Devolve o resultado (texto curto) ou null se ele precisa voltar no próximo polling.
     * Pedido que dá erro (404 inclusive) ao ser lido logo depois do evento: tenta de novo a cada polling por até 10 minutos
     * (o iFood pede esse retry) e só então desiste, bem antes de a plataforma contar "strike" por evento sem confirmação (50 entregas).
     * @param array<string,mixed> $evento
     */
    public function receberEvento(array $evento, ?string $token): ?string
    {
        $pdo = Banco::conexao();
        $id = (string) $evento['id'];
        $codigo = (string) ($evento['fullCode'] ?? self::CODIGOS_CURTOS[$evento['code'] ?? ''] ?? ($evento['code'] ?? ''));
        $pedidoIfood = (string) ($evento['orderId'] ?? '');

        $ja = $pdo->prepare('SELECT resultado FROM ifood_eventos WHERE id = ?');
        $ja->execute([$id]);
        $anterior = $ja->fetchColumn();
        if ($anterior !== false && !str_starts_with((string) $anterior, 'buscando:')) {
            return 'repetido';
        }

        $resultado = $this->processarEvento($codigo, $pedidoIfood, $token);
        if ($anterior === false) {
            if ($resultado === null) {
                $this->gravarEvento($id, $codigo, $pedidoIfood, 'buscando:' . time());
                return null;
            }
            return $this->gravarEvento($id, $codigo, $pedidoIfood, $resultado);
        }
        // Já tentamos antes e o pedido não saía.
        if ($resultado === null) {
            if (time() - (int) substr((string) $anterior, 9) <= self::PRAZO_BUSCA_S) {
                return null;
            }
            error_log("[ifood] pedido {$pedidoIfood}: desisti de ler depois de 10 minutos (evento {$id})");
            $resultado = 'desistiu_busca';
        }
        $pdo->prepare('UPDATE ifood_eventos SET resultado = ? WHERE id = ?')->execute([$resultado, $id]);
        return $resultado;
    }

    private function gravarEvento(string $id, string $codigo, string $pedidoIfood, string $resultado): string
    {
        try {
            Banco::conexao()->prepare('INSERT INTO ifood_eventos (id, codigo, ifood_pedido, recebido_em, resultado) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, mb_substr($codigo, 0, 60), mb_substr($pedidoIfood, 0, 80), Relogio::agora(), $resultado]);
        } catch (\PDOException) {
            return 'repetido';   // outro processo gravou o mesmo evento ao mesmo tempo
        }
        return $resultado;
    }

    private function processarEvento(string $codigo, string $pedidoIfood, ?string $token): ?string
    {
        if ($pedidoIfood === '') {
            return 'sem_pedido';
        }
        $local = $this->localPorIfoodId($pedidoIfood);
        if ($codigo === 'PLACED') {
            if ($local !== null) {
                return 'ja_importado';
            }
            if ($token === null) {
                return null;
            }
            // O pedido é consultado antes de qualquer confirmação (o confirm sem consulta é ignorado pelo iFood).
            $r = $this->api->pedido($token, $pedidoIfood);
            if (!$r['ok']) {
                $this->registrarErro("Pedido {$pedidoIfood}: " . $r['erro']);
                if ($r['status'] === 401) {
                    $this->tokenRecusado();
                }
                return null;
            }
            $this->importar($r['dados']);
            return 'pedido_criado';
        }
        if ($local === null) {
            return 'pedido_desconhecido';   // evento de um pedido anterior à integração
        }
        $this->atualizarIfood((int) $local['pedido_id'], ['status_ifood' => $codigo]
            + ($codigo === 'CONFIRMED' && $local['confirmado_em'] === null ? ['confirmado_em' => Relogio::agora()] : []));
        $pedido = $this->pedidos->porId((int) $local['pedido_id']);
        if ($pedido === null || in_array($pedido['status'], PedidoRepository::ENCERRADOS, true)) {
            return 'status_' . strtolower($codigo);
        }
        if ($codigo === 'CANCELLED') {
            $this->pedidos->mudarStatus($pedido['id'], $pedido['status'], 'cancelado', 'Cancelado no iFood', null);
            return 'cancelado';
        }
        if ($codigo === 'CONCLUDED') {
            $this->pedidos->mudarStatus($pedido['id'], $pedido['status'], 'concluido', null, null);
            return 'concluido';
        }
        if (isset(self::AVANCOS[$codigo])) {
            $this->acompanhar($pedido, self::AVANCOS[$codigo]);
        }
        $this->tocarPedido($pedido['id']);
        return 'status_' . strtolower($codigo);
    }

    /**
     * Outro sistema (ex.: o Gestor de Pedidos do iFood) mudou a etapa: o quadro acompanha, só para frente e sem avisar o iFood de
     * volta (a mudança já veio dele). Retirada com "despachado" = cliente retirou; quem conclui é o evento CONCLUDED.
     * @param array<string,mixed> $pedido
     */
    private function acompanhar(array $pedido, string $alvo): void
    {
        $fluxo = PedidoService::FLUXOS[$pedido['tipo']] ?? [];
        $de = array_search($pedido['status'], $fluxo, true);
        $para = array_search($alvo, $fluxo, true);
        if ($de === false || $para === false || $para <= $de) {
            return;
        }
        $this->pedidos->mudarStatus((int) $pedido['id'], $pedido['status'], $alvo, null, null);
    }

    /** O cartão do Kanban mostra a etapa do iFood: muda atualizado_em para o painel buscar de novo. */
    private function tocarPedido(int $pedidoId): void
    {
        Banco::conexao()->prepare('UPDATE pedidos SET atualizado_em = ? WHERE id = ?')->execute([Relogio::agora(), $pedidoId]);
    }

    /** @return array<string,mixed>|null */
    private function localPorIfoodId(string $ifoodId): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT * FROM ifood_pedidos WHERE ifood_id = ?');
        $sql->execute([$ifoodId]);
        $linha = $sql->fetch();
        return $linha ?: null;
    }

    /** @return array<string,mixed>|null */
    private function ifoodDoPedido(int $pedidoId): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT * FROM ifood_pedidos WHERE pedido_id = ?');
        $sql->execute([$pedidoId]);
        $linha = $sql->fetch();
        return $linha ?: null;
    }

    /** @param array<string,mixed> $campos */
    private function atualizarIfood(int $pedidoId, array $campos): void
    {
        $campos['atualizado_em'] = Relogio::agora();
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($campos)));
        Banco::conexao()->prepare("UPDATE ifood_pedidos SET {$sets} WHERE pedido_id = ?")->execute([...array_values($campos), $pedidoId]);
    }

    // =====================================================================================================================
    // Pedido do iFood → pedido do Kanban
    // =====================================================================================================================

    /**
     * Converte o pedido do iFood (JSON do GET /orders/{id}) num pedido nosso, na mesma transação do registro do iFood.
     * Os valores do iFood estão em reais (decimais); aqui viram centavos. O preço que vale é o do iFood: foi o que o cliente pagou.
     * @param array<string,mixed> $o
     * @return array{id:int, codigo:string, novo:bool}
     */
    public function importar(array $o, bool $simulado = false): array
    {
        $ifoodId = mb_substr((string) ($o['id'] ?? ''), 0, 80);
        if ($ifoodId === '') {
            throw new \InvalidArgumentException('Pedido do iFood sem id.');
        }
        $existente = $this->localPorIfoodId($ifoodId);
        if ($existente !== null) {
            return ['id' => (int) $existente['pedido_id'], 'codigo' => '', 'novo' => false];
        }
        [$pedido, $itens, $extra] = self::converter($o);
        $agora = Relogio::agora();
        try {
            $criado = Banco::transacao(function (\PDO $pdo) use ($pedido, $itens, $extra, $o, $ifoodId, $simulado, $agora): array {
                $criado = $this->pedidos->inserir($pdo, $pedido, $itens);
                $pdo->prepare('INSERT INTO ifood_pedidos (pedido_id, ifood_id, display_id, merchant_id, tipo, agendado_para, entregue_por, pago_online,
                                 pagamento_texto, telefone, localizador, codigo_coleta, status_ifood, confirmado_em, simulado, dados, criado_em, atualizado_em)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)')
                    ->execute([
                        $criado['id'], $ifoodId, $extra['display_id'], $extra['merchant_id'], $extra['tipo'], $extra['agendado_para'], $extra['entregue_por'],
                        $extra['pago_online'] ? 1 : 0, $extra['pagamento_texto'], $extra['telefone'], $extra['localizador'], $extra['codigo_coleta'],
                        'PLACED', $simulado ? 1 : 0, json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $agora, $agora,
                    ]);
                return $criado;
            });
        } catch (\PDOException $e) {
            $existente = $this->localPorIfoodId($ifoodId);   // corrida com outro processo: o pedido já entrou
            if ($existente !== null) {
                return ['id' => (int) $existente['pedido_id'], 'codigo' => '', 'novo' => false];
            }
            throw $e;
        }
        if ($this->config->get('ifood_confirmar_auto', '1') === '1') {
            $this->enfileirar($criado['id'], 'confirm');
            $this->processarAcoes(3);
        }
        return $criado + ['novo' => true];
    }

    /**
     * @param array<string,mixed> $o
     * @return array{0: array<string,mixed>, 1: list<array<string,mixed>>, 2: array<string,mixed>}
     */
    public static function converter(array $o): array
    {
        $centavos = static fn (mixed $reais): int => (int) round(((float) (is_numeric($reais) ? $reais : 0)) * 100);
        $texto = static fn (mixed $v, int $max): string => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);

        $entrega = ($o['orderType'] ?? 'DELIVERY') === 'DELIVERY';
        $endereco = is_array($o['delivery']['deliveryAddress'] ?? null) ? $o['delivery']['deliveryAddress'] : [];
        $lat = $endereco['coordinates']['latitude'] ?? null;
        $lng = $endereco['coordinates']['longitude'] ?? null;
        $coordenadas = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;

        // ---- Pagamento: payments.pending é o que ainda se cobra na entrega; methods[].type ONLINE já foi pago no app ----
        $pagamentos = is_array($o['payments'] ?? null) ? $o['payments'] : [];
        $metodos = array_values(array_filter(is_array($pagamentos['methods'] ?? null) ? $pagamentos['methods'] : [], 'is_array'));
        $pagoOnline = self::aCobrarCentavos($o) === 0;
        $cobrados = array_values(array_filter($metodos, static fn (array $m): bool => ($m['type'] ?? '') === 'OFFLINE'));
        $principal = $pagoOnline ? ($metodos[0] ?? []) : ($cobrados[0] ?? $metodos[0] ?? []);
        $forma = match (trim((string) ($principal['method'] ?? ''))) {
            'CASH' => 'dinheiro',
            'PIX' => 'pix',
            default => 'cartao',
        };
        $descreve = static function (array $m) use ($centavos): string {
            $metodo = trim((string) ($m['method'] ?? ''));
            $nome = self::FORMAS[$metodo] ?? ($metodo !== '' ? $metodo : 'Outro');
            $carteira = trim((string) ($m['wallet']['name'] ?? ''));
            $bandeira = trim((string) ($m['card']['brand'] ?? ''));
            $detalhe = $carteira !== '' ? $carteira : ($bandeira !== '' && !in_array($metodo, ['CASH', 'PIX'], true) ? $bandeira : '');   // a bandeira do cartão aparece
            return $nome . ($detalhe !== '' ? " {$detalhe}" : '') . ' R$ ' . number_format($centavos($m['value'] ?? 0) / 100, 2, ',', '.');
        };
        $online = array_map($descreve, array_values(array_filter($metodos, static fn (array $m): bool => ($m['type'] ?? '') !== 'OFFLINE')));
        $naEntrega = array_map($descreve, $cobrados);
        $pagamentoTexto = implode(' · ', array_filter([
            $online !== [] ? 'Pago online no iFood: ' . implode(' + ', $online) : '',
            $naEntrega !== [] ? 'Cobrar na entrega: ' . implode(' + ', $naEntrega) : '',
        ]));
        if ($pagamentoTexto === '') {
            $pagamentoTexto = $pagoOnline ? 'Pago online no iFood' : 'Cobrar na entrega';
        }
        // Troco: o cliente diz com que nota vai pagar (cash.changeFor); a loja leva a diferença para o valor cobrado em dinheiro.
        $troco = null;
        if (($principal['method'] ?? '') === 'CASH' && (float) ($principal['cash']['changeFor'] ?? 0) > 0) {
            $troco = $centavos($principal['cash']['changeFor']);
            $levar = max(0, $troco - $centavos($principal['value'] ?? 0));
            $pagamentoTexto .= ' · troco para R$ ' . number_format($troco / 100, 2, ',', '.') . ' (levar R$ ' . number_format($levar / 100, 2, ',', '.') . ')';
        }

        // ---- Itens: o preço de cada um é o que o iFood cobrou (com os complementos) ----
        $itens = [];
        foreach (array_filter(is_array($o['items'] ?? null) ? $o['items'] : [], 'is_array') as $item) {
            $quantidade = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $totalItem = $item['totalPrice'] ?? ((float) ($item['unitPrice'] ?? $item['price'] ?? 0) + (float) ($item['optionsPrice'] ?? 0)) * $quantidade;
            $opcoes = [];
            foreach (array_filter(is_array($item['options'] ?? null) ? $item['options'] : [], 'is_array') as $opcao) {
                $linha = ((int) ($opcao['quantity'] ?? 1)) . 'x ' . $texto($opcao['name'] ?? '', 80);
                $detalhes = array_map(static fn (array $c): string => trim((string) ($c['name'] ?? '')),
                    array_filter(is_array($opcao['customizations'] ?? $opcao['customization'] ?? null) ? ($opcao['customizations'] ?? $opcao['customization']) : [], 'is_array'));
                if (array_filter($detalhes) !== []) {
                    $linha .= ' (' . implode(', ', array_filter($detalhes)) . ')';
                }
                $opcoes[] = $linha;
            }
            $observacao = $texto($item['observations'] ?? '', 200);
            $partes = array_filter([$opcoes !== [] ? implode(', ', $opcoes) : '', $observacao !== '' ? 'Obs: ' . $observacao : '']);
            $itens[] = [
                'item_id' => null,
                'tipo' => 'simples',
                'nome' => $texto($item['name'] ?? 'Item', 120) ?: 'Item',
                'quantidade' => $quantidade,
                'preco_unit_centavos' => (int) round($centavos($totalItem) / $quantidade),
                'montagem' => null,
                'observacoes' => mb_substr(implode(' · ', $partes), 0, 500),
            ];
        }
        if ($itens === []) {
            $itens[] = ['item_id' => null, 'tipo' => 'simples', 'nome' => 'Pedido do iFood (sem itens)', 'quantidade' => 1, 'preco_unit_centavos' => 0, 'montagem' => null, 'observacoes' => ''];
        }

        $total = is_array($o['total'] ?? null) ? $o['total'] : [];
        $subtotal = $centavos($total['subTotal'] ?? array_sum(array_map(static fn (array $i): float => $i['preco_unit_centavos'] * $i['quantidade'] / 100, $itens)));
        $taxa = $centavos($total['deliveryFee'] ?? 0);
        $valorFinal = $centavos($total['orderAmount'] ?? ($subtotal + $taxa) / 100);

        $agendado = ($o['orderTiming'] ?? '') === 'SCHEDULED'
            ? Relogio::doIso((string) ($o['schedule']['deliveryDateTimeStart'] ?? $o['delivery']['deliveryDateTime'] ?? $o['takeout']['takeoutDateTime'] ?? ''))
            : null;
        $observacoes = array_filter([
            $texto($o['delivery']['observations'] ?? '', 200),
            $texto($o['takeout']['observations'] ?? '', 200),
            $texto($o['extraInfo'] ?? '', 200),
        ]);

        $pedido = [
            'cliente_nome' => $texto($o['customer']['name'] ?? '', 80) ?: 'Cliente iFood',
            // O telefone do iFood é um 0800 com localizador (o mesmo para todos): fica em ifood_pedidos, não vira cadastro de cliente.
            'cliente_telefone' => '',
            'tipo' => $entrega ? 'entrega' : 'retirada',
            'mesa' => null,
            'endereco_rua' => $entrega ? ($texto($endereco['streetName'] ?? $endereco['formattedAddress'] ?? '', 120) ?: 'Endereço no iFood') : null,
            'endereco_numero' => $entrega ? ($texto($endereco['streetNumber'] ?? '', 20) ?: 's/n') : null,
            'endereco_bairro' => $entrega ? $texto($endereco['neighborhood'] ?? '', 80) : null,
            'endereco_complemento' => $entrega ? $texto($endereco['complement'] ?? '', 80) : null,
            'endereco_referencia' => $entrega ? $texto($endereco['reference'] ?? '', 120) : null,
            'entrega_lat' => $entrega && $coordenadas ? round((float) $lat, 6) : null,
            'entrega_lng' => $entrega && $coordenadas ? round((float) $lng, 6) : null,
            'pagamento' => $forma,
            'troco_para_centavos' => $troco,
            'observacoes' => mb_substr(implode(' · ', $observacoes), 0, 300),
            'subtotal_centavos' => $subtotal,
            'taxa_entrega_centavos' => $taxa,
            'total_centavos' => $valorFinal,
            'origem' => 'ifood',
        ];
        $telefone = is_array($o['customer']['phone'] ?? null) ? $o['customer']['phone'] : [];
        $extra = [
            'display_id' => $texto($o['displayId'] ?? '', 20) ?: mb_substr((string) ($o['id'] ?? ''), 0, 8),
            'merchant_id' => $texto($o['merchant']['id'] ?? '', 80) ?: null,
            'tipo' => $texto($o['orderType'] ?? 'DELIVERY', 20),
            'agendado_para' => $agendado,
            'entregue_por' => $entrega ? ($texto($o['delivery']['deliveredBy'] ?? '', 20) ?: null) : null,
            'pago_online' => $pagoOnline,
            'pagamento_texto' => mb_substr($pagamentoTexto, 0, 200),
            'telefone' => $texto($telefone['number'] ?? '', 30),
            'localizador' => $texto($telefone['localizer'] ?? '', 20),
            'codigo_coleta' => $texto($o['delivery']['pickupCode'] ?? $o['takeout']['pickupCode'] ?? '', 20),
        ];
        return [$pedido, $itens, $extra];
    }

    /** Quanto a loja ainda cobra na entrega (payments.pending; sem ele, a soma dos métodos OFFLINE). 0 = pago online, não cobrar. */
    public static function aCobrarCentavos(array $o): int
    {
        $centavos = static fn (mixed $v): int => (int) round(((float) (is_numeric($v) ? $v : 0)) * 100);
        $pagamentos = is_array($o['payments'] ?? null) ? $o['payments'] : [];
        if (isset($pagamentos['pending']) && is_numeric($pagamentos['pending'])) {
            return max(0, $centavos($pagamentos['pending']));
        }
        $soma = 0;
        foreach (is_array($pagamentos['methods'] ?? null) ? $pagamentos['methods'] : [] as $m) {
            if (is_array($m) && ($m['type'] ?? '') === 'OFFLINE') {
                $soma += $centavos($m['value'] ?? 0);
            }
        }
        return $soma;
    }

    /**
     * O que a homologação manda exibir e o pedido guarda no JSON original: cupons (com quem paga: iFood ou loja), CPF/CNPJ da nota
     * e o valor a cobrar.
     * @return array{cupons: list<string>, documento: string, a_cobrar_centavos: int, taxas_adicionais_centavos: int}
     */
    public static function detalhesDoPedido(array $o): array
    {
        $reais = static fn (mixed $v): string => 'R$ ' . number_format((float) (is_numeric($v) ? $v : 0), 2, ',', '.');
        $alvos = ['CART' => 'carrinho', 'DELIVERY_FEE' => 'taxa de entrega', 'ITEM' => 'item', 'PROGRESSIVE_DISCOUNT_ITEM' => 'itens iguais'];
        $quemPaga = ['IFOOD' => 'iFood', 'EXTERNAL' => 'parceiro do iFood', 'MERCHANT' => 'Loja', 'CHAIN' => 'Rede'];
        $cupons = [];
        foreach (is_array($o['benefits'] ?? null) ? $o['benefits'] : [] as $b) {
            if (!is_array($b)) {
                continue;
            }
            $quem = [];
            foreach (is_array($b['sponsorshipValues'] ?? null) ? $b['sponsorshipValues'] : [] as $p) {
                if (is_array($p) && (float) ($p['value'] ?? 0) > 0) {
                    $quem[] = ($quemPaga[$p['name'] ?? ''] ?? (string) ($p['name'] ?? '?')) . ' ' . $reais($p['value']);
                }
            }
            $alvo = $alvos[$b['target'] ?? ''] ?? '';
            $cupons[] = 'Cupom de ' . $reais($b['value'] ?? 0) . ($alvo !== '' ? " ({$alvo})" : '') . ($quem !== [] ? ' · quem paga: ' . implode(', ', $quem) : '');
        }
        if ($cupons === [] && (float) ($o['total']['benefits'] ?? 0) > 0) {
            $cupons[] = 'Cupom de ' . $reais($o['total']['benefits']) . ' (veja quem paga no Gestor de Pedidos do iFood)';
        }
        $numero = trim((string) ($o['customer']['documentNumber'] ?? ''));
        $tipo = trim((string) ($o['customer']['documentType'] ?? ''));
        return [
            'cupons' => $cupons,
            'documento' => $numero === '' ? '' : ($tipo !== '' ? "{$tipo} " : '') . $numero,
            'a_cobrar_centavos' => self::aCobrarCentavos($o),
            'taxas_adicionais_centavos' => (int) round(((float) ($o['total']['additionalFees'] ?? 0)) * 100),
        ];
    }

    // =====================================================================================================================
    // Kanban → iFood
    // =====================================================================================================================

    /**
     * O pedido mudou de etapa no painel: enfileira o que o iFood precisa saber e já tenta enviar. Nunca derruba o Kanban.
     * $codigoCancelamento: o motivo que a pessoa escolheu na lista do iFood (GET cancellationReasons), só no cancelamento.
     */
    public function statusMudou(array $pedido, string $para, ?string $motivo, ?string $codigoCancelamento = null): void
    {
        if (($pedido['origem'] ?? '') !== 'ifood') {
            return;
        }
        try {
            $ifood = $this->ifoodDoPedido((int) $pedido['id']);
            if ($ifood === null) {
                return;
            }
            $entregaDaLoja = $ifood['tipo'] === 'DELIVERY' && ($ifood['entregue_por'] ?? '') !== 'IFOOD';
            $acoes = match ($para) {
                'em_preparo' => ['confirm', 'startPreparation'],
                // Pronto avisa o iFood também na entrega própria: o dispatch só vale depois do readyToPickup.
                'pronto', 'aguardando_retirada' => ['confirm', 'readyToPickup'],
                'saiu_entrega' => $entregaDaLoja ? ['confirm', 'readyToPickup', 'dispatch'] : ['confirm'],
                'cancelado' => ['requestCancellation'],
                default => [],
            };
            foreach ($acoes as $acao) {
                $corpo = $acao === 'requestCancellation'
                    ? ['reason' => mb_substr((string) ($motivo ?: 'Cancelado pela loja'), 0, 250), 'cancellationCode' => (string) $codigoCancelamento]
                    : null;
                $this->enfileirar((int) $pedido['id'], $acao, $corpo);
            }
            $this->processarAcoes(5);
        } catch (\Throwable $e) {
            error_log('[ifood] etapa do pedido ' . $pedido['id'] . ': ' . $e->getMessage());
        }
    }

    /**
     * Motivos de cancelamento que o iFood aceita para este pedido (GET cancellationReasons): a tela os mostra à pessoa, como
     * a homologação exige. Pedido de teste do simulador usa uma lista fixa.
     * @return array{origem:string, motivos:list<array{codigo:string, descricao:string}>, erro?:string}
     */
    public function motivosDeCancelamento(int $pedidoId): array
    {
        $i = $this->ifoodDoPedido($pedidoId);
        if ($i === null) {
            return ['origem' => 'nenhum', 'motivos' => []];
        }
        if ((int) $i['simulado'] === 1) {
            return ['origem' => 'simulado', 'motivos' => self::MOTIVOS_SIMULADOS];
        }
        $token = $this->token();
        if ($token === null) {
            return ['origem' => 'ifood', 'motivos' => [], 'erro' => 'O iFood não está conectado.'];
        }
        return ['origem' => 'ifood', 'motivos' => $this->codigosDeCancelamento($token, (string) $i['ifood_id'])];
    }

    /** @return list<array{codigo:string, descricao:string}> */
    private function codigosDeCancelamento(string $token, string $ifoodId): array
    {
        $r = $this->api->motivosDeCancelamento($token, $ifoodId);
        $lista = [];
        if ($r['ok']) {
            foreach ($r['dados'] as $m) {
                $codigo = is_array($m) ? (string) ($m['cancelCodeId'] ?? $m['cancellationCode'] ?? $m['code'] ?? '') : '';
                if ($codigo !== '') {
                    $lista[] = ['codigo' => $codigo, 'descricao' => mb_substr((string) ($m['description'] ?? $codigo), 0, 120)];
                }
            }
        }
        return $lista;
    }

    /** O iFood só aceita um código da lista daquele pedido: usa o escolhido na tela ou, faltando, o melhor disponível. */
    private function corpoDoCancelamento(string $token, string $ifoodId, array $corpo): array
    {
        $codigos = array_column($this->codigosDeCancelamento($token, $ifoodId), 'codigo');
        $escolhido = (string) ($corpo['cancellationCode'] ?? '');
        if ($codigos !== [] && !in_array($escolhido, $codigos, true)) {
            $escolhido = in_array(self::CODIGO_CANCELAMENTO, $codigos, true) ? self::CODIGO_CANCELAMENTO : $codigos[0];
        } elseif ($codigos === [] && $escolhido === '') {
            $escolhido = self::CODIGO_CANCELAMENTO;
        }
        return ['reason' => (string) ($corpo['reason'] ?? 'Cancelado pela loja'), 'cancellationCode' => $escolhido];
    }

    /** Enfileira uma ação (uma vez só por pedido; o cancelamento substitui o que ainda não saiu). */
    private function enfileirar(int $pedidoId, string $acao, ?array $corpo = null): void
    {
        $pdo = Banco::conexao();
        $ja = $pdo->prepare("SELECT COUNT(*) FROM ifood_acoes WHERE pedido_id = ? AND acao = ? AND estado IN ('pendente', 'feita')");
        $ja->execute([$pedidoId, $acao]);
        if ((int) $ja->fetchColumn() > 0) {
            return;
        }
        $agora = Relogio::agora();
        if ($acao === 'requestCancellation') {
            $pdo->prepare("UPDATE ifood_acoes SET estado = 'falhou', erro = 'Pedido cancelado antes do envio' WHERE pedido_id = ? AND estado = 'pendente'")->execute([$pedidoId]);
        }
        Banco::inserir($pdo, 'INSERT INTO ifood_acoes (pedido_id, acao, corpo, estado, tentativas, proxima_em, criado_em) VALUES (?, ?, ?, ?, 0, ?, ?)',
            [$pedidoId, $acao, $corpo === null ? null : json_encode($corpo, JSON_UNESCAPED_UNICODE), 'pendente', $agora, $agora]);
    }

    private function temAcoesPendentes(): bool
    {
        return (int) Banco::conexao()->query("SELECT COUNT(*) FROM ifood_acoes WHERE estado = 'pendente'")->fetchColumn() > 0;
    }

    /** Envia as ações na ordem em que foram pedidas. Pedido simulado: a ação vale sem chamar o iFood. @return int quantas saíram */
    public function processarAcoes(int $limite): int
    {
        $pdo = Banco::conexao();
        $sql = $pdo->prepare("SELECT a.*, i.ifood_id, i.simulado FROM ifood_acoes a JOIN ifood_pedidos i ON i.pedido_id = a.pedido_id
                              WHERE a.estado = 'pendente' AND a.proxima_em <= ? ORDER BY a.id LIMIT " . max(1, $limite));
        $sql->execute([Relogio::agora()]);
        $feitas = 0;
        $token = null;
        $bloqueados = [];   // pedido cuja ação anterior falhou agora: as seguintes esperam (a ordem importa para o iFood)
        foreach ($sql->fetchAll() as $acao) {
            $pedidoId = (int) $acao['pedido_id'];
            if (isset($bloqueados[$pedidoId])) {
                continue;
            }
            // Trava a linha: só um processo envia cada ação.
            $trava = $pdo->prepare("UPDATE ifood_acoes SET tentativas = tentativas + 1, proxima_em = ? WHERE id = ? AND estado = 'pendente' AND tentativas = ?");
            $trava->execute([gmdate('Y-m-d H:i:s', time() + 60), $acao['id'], $acao['tentativas']]);
            if ($trava->rowCount() !== 1) {
                continue;
            }
            if ((int) $acao['simulado'] === 1) {
                $r = ['ok' => true, 'status' => 202, 'erro' => null];
            } else {
                $token ??= $this->token();
                if ($token === null) {
                    $r = ['ok' => false, 'status' => 0, 'erro' => 'iFood não conectado.'];
                } else {
                    $corpo = $acao['corpo'] === null ? null : json_decode((string) $acao['corpo'], true);
                    if ($acao['acao'] === 'requestCancellation') {
                        $corpo = $this->corpoDoCancelamento($token, (string) $acao['ifood_id'], is_array($corpo) ? $corpo : []);
                    }
                    $r = $this->api->acao($token, (string) $acao['ifood_id'], (string) $acao['acao'], $corpo);
                    if ($r['status'] === 401) {
                        $this->tokenRecusado();
                        $token = null;
                    }
                }
            }
            $tentativas = (int) $acao['tentativas'] + 1;
            if ($r['ok']) {
                $pdo->prepare("UPDATE ifood_acoes SET estado = 'feita', feita_em = ?, erro = NULL WHERE id = ?")->execute([Relogio::agora(), $acao['id']]);
                if ($acao['acao'] === 'confirm') {
                    $this->atualizarIfood($pedidoId, ['confirmado_em' => Relogio::agora(), 'status_ifood' => 'CONFIRMED']);
                    $this->tocarPedido($pedidoId);
                }
                $feitas++;
                continue;
            }
            $bloqueados[$pedidoId] = true;
            $desiste = $tentativas >= self::TENTATIVAS || in_array($r['status'], [400, 404, 409, 422], true);
            $pdo->prepare('UPDATE ifood_acoes SET estado = ?, erro = ?, proxima_em = ? WHERE id = ?')->execute([
                $desiste ? 'falhou' : 'pendente', mb_substr((string) $r['erro'], 0, 300), gmdate('Y-m-d H:i:s', time() + 30 * $tentativas), $acao['id'],
            ]);
            $this->registrarErro("Pedido #{$pedidoId}, ação {$acao['acao']}: {$r['erro']}" . ($desiste ? ' (desistiu; faça no Gestor de Pedidos do iFood)' : ''));
            $this->tocarPedido($pedidoId);
        }
        return $feitas;
    }

    // =====================================================================================================================
    // Simulador (sem credenciais) e situação para o painel
    // =====================================================================================================================

    /**
     * Cria um pedido no formato do iFood com itens do cardápio atual e o faz passar pelo mesmo caminho de um pedido real
     * (evento PLACED → importar → confirmar). As ações dele não chamam o iFood.
     * @return array{id:int, codigo:string, novo:bool}
     */
    public function simularPedido(string $tipo = 'DELIVERY'): array
    {
        $catalogo = (new \App\Repositories\CardapioRepository())->publico();
        $simples = array_values(array_filter($catalogo['itens'], static fn (array $i): bool => $i['estado'] === 'disponivel'));
        $prato = $simples[0] ?? ['id' => 0, 'nome' => 'Prato de teste', 'preco_centavos' => 3490];
        $bebida = null;
        foreach ($simples as $i) {
            if ($i['tipo'] === 'simples' && $i['id'] !== $prato['id']) {
                $bebida = $i;
                break;
            }
        }
        $preco = static fn (array $i): float => ((int) ($i['preco_centavos'] ?? $i['preco_base_centavos'] ?? 2990) ?: 2990) / 100;
        $itens = [[
            'index' => 1, 'id' => bin2hex(random_bytes(6)), 'name' => $prato['nome'], 'externalCode' => (string) $prato['id'], 'unit' => 'UN', 'quantity' => 1,
            'unitPrice' => $preco($prato), 'optionsPrice' => 4.0, 'totalPrice' => $preco($prato) + 4.0, 'observations' => 'Sem cebola, por favor',
            'options' => [['index' => 1, 'name' => 'Bacon extra', 'quantity' => 1, 'unitPrice' => 4.0, 'price' => 4.0]],
        ]];
        if ($bebida !== null) {
            $itens[] = ['index' => 2, 'id' => bin2hex(random_bytes(6)), 'name' => $bebida['nome'], 'externalCode' => (string) $bebida['id'], 'unit' => 'UN',
                'quantity' => 2, 'unitPrice' => $preco($bebida), 'optionsPrice' => 0, 'totalPrice' => $preco($bebida) * 2, 'options' => []];
        }
        $subtotal = array_sum(array_column($itens, 'totalPrice'));
        $entrega = $tipo === 'DELIVERY';
        $taxa = $entrega ? 7.99 : 0;
        $pedido = [
            'id' => 'simulado-' . bin2hex(random_bytes(8)),
            'displayId' => (string) random_int(1000, 9999),
            'orderType' => $entrega ? 'DELIVERY' : 'TAKEOUT',
            'orderTiming' => 'IMMEDIATE',
            'salesChannel' => 'IFOOD',
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'isTest' => true,
            'merchant' => ['id' => $this->conexao()['merchant_id'] ?? 'loja-simulada', 'name' => $this->config->get('nome_loja', 'Loja')],
            'customer' => ['id' => 'c1', 'name' => 'Cliente iFood (teste)', 'documentNumber' => '12345678909', 'documentType' => 'CPF',
                'phone' => ['number' => '0800 705 0050', 'localizer' => (string) random_int(10000000, 99999999)]],
            'items' => $itens,
            'benefits' => [['value' => 5.0, 'target' => 'CART', 'sponsorshipValues' => [
                ['name' => 'IFOOD', 'value' => 3.0, 'description' => 'Incentivo do iFood'], ['name' => 'MERCHANT', 'value' => 2.0, 'description' => 'Incentivo da Loja'],
            ]]],
            'total' => ['subTotal' => $subtotal, 'deliveryFee' => $taxa, 'benefits' => 5.0, 'orderAmount' => $subtotal + $taxa - 5.0, 'additionalFees' => 0],
            'payments' => ['prepaid' => $subtotal + $taxa - 5.0, 'pending' => 0, 'methods' => [
                ['value' => $subtotal + $taxa - 5.0, 'currency' => 'BRL', 'method' => 'CREDIT', 'type' => 'ONLINE', 'prepaid' => true, 'card' => ['brand' => 'VISA']],
            ]],
            'delivery' => $entrega ? [
                'mode' => 'DEFAULT', 'deliveredBy' => 'IFOOD', 'deliveryDateTime' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 2700), 'observations' => 'Tocar o interfone',
                'pickupCode' => (string) random_int(1000, 9999),
                'deliveryAddress' => ['streetName' => 'Rua das Laranjeiras', 'streetNumber' => '250', 'neighborhood' => 'Centro', 'complement' => 'Apto 31',
                    'reference' => 'Perto da padaria', 'city' => 'São Paulo', 'state' => 'SP', 'postalCode' => '01000000',
                    'formattedAddress' => 'Rua das Laranjeiras, 250', 'coordinates' => ['latitude' => -23.5505, 'longitude' => -46.6333]],
            ] : null,
            'takeout' => $entrega ? null : ['mode' => 'DEFAULT', 'takeoutDateTime' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 1500)],
        ];
        $resultado = $this->importar(array_filter($pedido, static fn ($v): bool => $v !== null), true);
        Banco::conexao()->prepare('INSERT INTO ifood_eventos (id, codigo, ifood_pedido, recebido_em, resultado) VALUES (?, ?, ?, ?, ?)')
            ->execute(['simulado-' . bin2hex(random_bytes(8)), 'PLACED', $pedido['id'], Relogio::agora(), 'pedido_criado']);
        return $resultado;
    }

    /** O que o painel mostra (nenhum token, nenhuma credencial). */
    public function situacao(): array
    {
        $c = $this->conexao();
        $pdo = Banco::conexao();
        $ultimos = $pdo->query("SELECT i.pedido_id, i.display_id, i.status_ifood, i.simulado, i.pago_online, i.criado_em, p.status, p.cliente_nome, p.total_centavos
                                FROM ifood_pedidos i JOIN pedidos p ON p.id = i.pedido_id ORDER BY i.pedido_id DESC LIMIT 10")->fetchAll();
        $falhas = $pdo->query("SELECT a.pedido_id, a.acao, a.erro, a.criado_em FROM ifood_acoes a WHERE a.estado = 'falhou' ORDER BY a.id DESC LIMIT 5")->fetchAll();
        $pendentes = (int) $pdo->query("SELECT COUNT(*) FROM ifood_acoes WHERE estado = 'pendente'")->fetchColumn();
        $aguardando = $c['estado'] === 'aguardando_codigo' && ($c['codigo_expira_em'] ?? '') > Relogio::agora();
        $polling = $c['ultimo_polling_em'] ?? null;
        $cred = IfoodApi::credenciais();
        return [
            'configurado' => IfoodApi::configurada(),
            // O Client Secret nunca sai do servidor: o painel só sabe SE existe um salvo.
            'credenciais' => [
                'origem' => $cred['origem'],
                'client_id' => $cred['id'],
                'secret_salvo' => $cred['secret'] !== '' || $cred['secret_ilegivel'],
                'secret_ilegivel' => $cred['secret_ilegivel'],
                'editavel' => $cred['origem'] !== 'ambiente',
                'app_key_ok' => \App\Core\Segredo::disponivel(),
            ],
            'tipo_app' => IfoodApi::tipoDeAplicativo(),
            'modo' => IfoodApi::configurada() ? 'real' : 'simulado',
            'estado' => $c['estado'] === 'aguardando_codigo' && !$aguardando ? 'desconectado' : $c['estado'],
            'loja' => $c['merchant_id'] ? ['id' => $c['merchant_id'], 'nome' => $c['merchant_nome']] : null,
            // Conectado mas sem loja: o lojista ainda não aprovou ou a permissão ainda não propagou (até 10 min).
            'sem_loja' => $c['estado'] === 'conectado' && empty($c['merchant_id']),
            'prazo_confirmacao_min' => self::PRAZO_CONFIRMACAO_MIN,
            'codigo' => $aguardando ? ['user_code' => $c['user_code'], 'url' => $c['url_autorizacao'], 'expira_em' => Relogio::iso($c['codigo_expira_em'])] : null,
            'ultimo_polling_em' => Relogio::iso($polling),
            // Sem polling há mais de 2 min com a loja conectada: o iFood pode fechar a loja no app.
            'polling_atrasado' => $c['estado'] === 'conectado' && ($polling === null || $polling < gmdate('Y-m-d H:i:s', time() - 120)),
            'ultimo_erro' => $c['ultimo_erro'] ? ['mensagem' => $c['ultimo_erro'], 'em' => Relogio::iso($c['ultimo_erro_em'])] : null,
            'confirmar_auto' => $this->config->get('ifood_confirmar_auto', '1') === '1',
            'acoes_pendentes' => $pendentes,
            'acoes_com_falha' => array_map(static fn (array $f): array => ['pedido_id' => (int) $f['pedido_id'], 'acao' => $f['acao'], 'erro' => $f['erro'], 'em' => Relogio::iso($f['criado_em'])], $falhas),
            'ultimos' => array_map(static fn (array $u): array => [
                'pedido_id' => (int) $u['pedido_id'], 'display_id' => $u['display_id'], 'status_ifood' => $u['status_ifood'],
                'rotulo_ifood' => self::ROTULOS_STATUS[$u['status_ifood']] ?? $u['status_ifood'], 'simulado' => (int) $u['simulado'] === 1,
                'pago_online' => (int) $u['pago_online'] === 1, 'status' => $u['status'], 'cliente_nome' => $u['cliente_nome'],
                'total_centavos' => (int) $u['total_centavos'], 'criado_em' => Relogio::iso($u['criado_em']),
            ], $ultimos),
        ];
    }

    /** Campos do iFood que vão junto com cada pedido de origem "ifood" (cartão, detalhe e comanda). @param list<int> $ids */
    public static function dadosDosPedidos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $sql = Banco::conexao()->prepare("SELECT i.*, (SELECT COUNT(*) FROM ifood_acoes a WHERE a.pedido_id = i.pedido_id AND a.estado = 'falhou') AS falhas
                                          FROM ifood_pedidos i WHERE i.pedido_id IN ({$marcas})");
        $sql->execute($ids);
        $dados = [];
        foreach ($sql as $i) {
            $json = json_decode((string) $i['dados'], true);
            $detalhes = self::detalhesDoPedido(is_array($json) ? $json : []);
            $dados[(int) $i['pedido_id']] = [
                'display_id' => $i['display_id'],
                'status' => $i['status_ifood'],
                'rotulo' => self::ROTULOS_STATUS[$i['status_ifood']] ?? $i['status_ifood'],
                'pago_online' => (int) $i['pago_online'] === 1,
                'pagamento_texto' => $i['pagamento_texto'],
                'telefone' => $i['telefone'],
                'localizador' => $i['localizador'],
                'codigo_coleta' => $i['codigo_coleta'],
                'entregue_por' => $i['entregue_por'],
                'agendado_para' => Relogio::iso($i['agendado_para']),
                'confirmado' => $i['confirmado_em'] !== null,
                'simulado' => (int) $i['simulado'] === 1,
                'falhas' => (int) $i['falhas'],
                'cupons' => $detalhes['cupons'],
                'documento' => $detalhes['documento'],
                'a_cobrar_centavos' => $detalhes['a_cobrar_centavos'],
                'taxas_adicionais_centavos' => $detalhes['taxas_adicionais_centavos'],
                'alerta' => self::ALERTAS_STATUS[$i['status_ifood']] ?? null,
            ];
        }
        return $dados;
    }
}
