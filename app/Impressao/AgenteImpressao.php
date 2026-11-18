<?php
declare(strict_types=1);

namespace App\Impressao;

use App\Core\Banco;
use App\Core\Relogio;
use App\Repositories\ConfigRepository;
use App\Repositories\PedidoRepository;

/**
 * Agente de impressão (D79 a D81): um programa pequeno no computador da loja (impressora/agente.ps1) que pergunta ao servidor,
 * a cada poucos segundos, se há comanda para imprimir, e imprime direto na impressora USB pelo driver do Windows.
 * Funciona com o painel fechado, sem janela de impressão e com qualquer térmica que tenha driver (cupom de 58/80 mm ou etiqueta).
 *
 *   * O agente se identifica com um código gerado no painel (só o hash fica no banco).
 *   * Toda a parametrização (impressora, papel, letra, vias, o que imprimir) fica no painel; o agente só recebe e obedece.
 *   * A comanda sai uma vez só: o pedido é "pego" com o mesmo claim atômico da estação do navegador (D22).
 *   * Se a impressão falhar, o agente avisa e o trabalho volta para a fila (até 3 vezes); depois disso o painel mostra a falha.
 */
final class AgenteImpressao
{
    public const VERSAO_MINIMA = 1;
    public const ORIGENS = ['cardapio', 'whatsapp', 'ifood', 'balcao'];
    public const LARGURAS = [58, 72, 80, 100, 104];
    public const FONTES = ['p', 'm', 'g'];
    /**
     * Como a comanda chega à impressora (a comanda sempre vira imagem, então acentos e letra grande saem em qualquer uma):
     *   escpos = impressora de cupom, ESC/POS direto na fila do Windows (não depende do driver instalado);
     *   tspl   = impressora de etiqueta, TSPL direto (serve também com rolo contínuo, que o driver de etiqueta não entende);
     *   driver = pelo driver do Windows.
     */
    public const LINGUAGENS = ['escpos', 'tspl', 'driver'];
    public const OFFLINE_S = 45;
    private const TENTATIVAS = 3;
    private const SEM_RESPOSTA_S = 180;
    /** Estados da fila do Windows (Get-Printer) que impedem a impressão. */
    private const PROBLEMAS = ['Paused', 'Error', 'PaperJam', 'PaperOut', 'Offline', 'NotAvailable', 'UserIntervention', 'DoorOpen', 'PaperProblem', 'NoToner'];
    /** Impressoras do Windows que não são papel (PDF, OneNote, XPS, fax): a tela não oferece para comanda. */
    private const PORTAS_VIRTUAIS = ['PORTPROMPT:', 'NUL:', 'SHRFAX:', 'XPSPORT:', 'FILE:'];
    private const PROBLEMAS_PT = [
        'Paused' => 'pausada no Windows', 'Error' => 'com erro', 'PaperJam' => 'com papel enroscado', 'PaperOut' => 'sem papel',
        'Offline' => 'desligada ou desconectada', 'NotAvailable' => 'indisponível', 'UserIntervention' => 'esperando alguém mexer nela',
        'DoorOpen' => 'com a tampa aberta', 'PaperProblem' => 'com problema no papel', 'NoToner' => 'sem tinta',
    ];

    public function __construct(
        private readonly ConfigRepository $config = new ConfigRepository(),
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
    ) {}

    // ---------- Ajustes ----------

    /** Parametrização da impressão (tela Impressora). */
    public function ajustes(): array
    {
        $c = $this->config;
        $origens = json_decode($c->get('impressao_origens', ''), true);
        return [
            'modo' => $c->get('impressao_modo', 'navegador') === 'agente' ? 'agente' : 'navegador',
            'impressora' => $c->get('impressora_nome'),
            'linguagem' => in_array($c->get('impressora_linguagem', 'escpos'), self::LINGUAGENS, true) ? $c->get('impressora_linguagem', 'escpos') : 'escpos',
            'largura_mm' => $c->inteiro('comanda_largura_mm', 80),
            'altura_mm' => $c->inteiro('comanda_altura_mm', 0),
            'margem_mm' => $c->inteiro('comanda_margem_mm', 3),
            'fonte' => in_array($c->get('comanda_fonte', 'm'), self::FONTES, true) ? $c->get('comanda_fonte', 'm') : 'm',
            'vias' => max(1, min(3, $c->inteiro('comanda_vias', 1))),
            'com_preco' => $c->get('comanda_mostra_preco', '0') === '1',
            'origens' => is_array($origens) ? array_values(array_intersect(self::ORIGENS, $origens)) : self::ORIGENS,
        ];
    }

    /**
     * Valida e grava a parametrização. @param array<string,mixed> $d
     * @return array<string,string> erros por campo (vazio = gravou)
     */
    public function salvarAjustes(array $d): array
    {
        $erros = [];
        $modo = (string) ($d['modo'] ?? 'navegador');
        if (!in_array($modo, ['navegador', 'agente'], true)) { $erros['modo'] = 'Escolha onde a comanda é impressa.'; }
        $linguagem = (string) ($d['linguagem'] ?? 'escpos');
        if (!in_array($linguagem, self::LINGUAGENS, true)) { $erros['linguagem'] = 'Escolha como a comanda vai para a impressora.'; }
        $largura = (int) ($d['largura_mm'] ?? 80);
        if (!in_array($largura, self::LARGURAS, true)) { $erros['largura_mm'] = 'Escolha a largura do papel.'; }
        $altura = (int) ($d['altura_mm'] ?? 0);
        if ($altura !== 0 && ($altura < 30 || $altura > 300)) { $erros['altura_mm'] = 'Use 0 para rolo contínuo ou a altura da etiqueta (30 a 300 mm).'; }
        $margem = (int) ($d['margem_mm'] ?? 3);
        if ($margem < 0 || $margem > 15) { $erros['margem_mm'] = 'A margem vai de 0 a 15 mm.'; }
        $fonte = (string) ($d['fonte'] ?? 'm');
        if (!in_array($fonte, self::FONTES, true)) { $erros['fonte'] = 'Escolha o tamanho da letra.'; }
        $vias = (int) ($d['vias'] ?? 1);
        if ($vias < 1 || $vias > 3) { $erros['vias'] = 'De 1 a 3 vias.'; }
        $origens = array_values(array_intersect(self::ORIGENS, is_array($d['origens'] ?? null) ? $d['origens'] : []));
        $impressora = mb_substr(trim((string) ($d['impressora'] ?? '')), 0, 120);
        if ($erros !== []) {
            return $erros;
        }
        // Trocou a impressora, o jeito de enviar ou a largura: o "saiu certinho" do teste não vale mais.
        $antes = $this->ajustes();
        if ([$antes['impressora'], $antes['linguagem'], $antes['largura_mm']] !== [$impressora, $linguagem, $largura]) {
            $this->config->salvar(['impressora_confirmada_em' => '']);
        }
        $this->config->salvar([
            'impressao_modo' => $modo,
            'impressora_nome' => $impressora,
            'impressora_linguagem' => $linguagem,
            'comanda_largura_mm' => (string) $largura,
            'comanda_altura_mm' => (string) $altura,
            'comanda_margem_mm' => (string) $margem,
            'comanda_fonte' => $fonte,
            'comanda_vias' => (string) $vias,
            'comanda_mostra_preco' => !empty($d['com_preco']) ? '1' : '0',
            'impressao_origens' => json_encode($origens),
        ]);
        return [];
    }

    // ---------- Código do agente ----------

    /** Gera um código novo (o anterior para de valer) e liga o modo agente. O código aparece uma vez só. */
    public function gerarCodigo(): string
    {
        $codigo = 'fm-' . bin2hex(random_bytes(16));
        $this->config->salvar([
            'impressora_agente_hash' => hash('sha256', $codigo),
            'impressora_agente_desde' => Relogio::agora(),   // só pedidos daqui em diante: o agente novo não imprime o passado
            'impressao_modo' => 'agente',
            'impressora_confirmada_em' => '',
            'impressora_agente_contato_em' => '',   // o sinal do programa antigo não vale para o novo (a tela espera o novo ligar)
        ]);
        return $codigo;
    }

    /** O dono viu a comanda de teste sair certinha (passo 3 da tela Impressora). */
    public function confirmar(): void
    {
        $this->config->salvar(['impressora_confirmada_em' => Relogio::agora()]);
    }

    public function codigoValido(string $codigo): bool
    {
        $hash = $this->config->get('impressora_agente_hash');
        return $hash !== '' && $codigo !== '' && hash_equals($hash, hash('sha256', $codigo));
    }

    public function revogar(): void
    {
        $this->config->salvar(['impressora_agente_hash' => '', 'impressora_agente_contato_em' => '', 'impressao_modo' => 'navegador', 'impressora_confirmada_em' => '']);
    }

    // ---------- Ciclo do agente ----------

    /**
     * O agente chegou (a cada poucos segundos): registra que está vivo, cria as comandas automáticas e entrega o que houver.
     * @param array<string,mixed> $info {computador, versao, impressoras: [{nome, status, padrao}]}
     * @return array{ajustes:array, trabalhos:list<array>, intervalo_s:int}
     */
    public function ciclo(array $info): array
    {
        $impressoras = [];
        foreach (array_slice(is_array($info['impressoras'] ?? null) ? $info['impressoras'] : [], 0, 30) as $i) {
            if (is_array($i) && trim((string) ($i['nome'] ?? '')) !== '') {
                $impressoras[] = [
                    'nome' => mb_substr(trim((string) $i['nome']), 0, 120),
                    'status' => mb_substr((string) ($i['status'] ?? ''), 0, 40),
                    'padrao' => !empty($i['padrao']),
                    'porta' => mb_substr((string) ($i['porta'] ?? ''), 0, 80),
                    'driver' => mb_substr((string) ($i['driver'] ?? ''), 0, 120),
                ];
            }
        }
        $this->config->salvar([
            'impressora_agente_contato_em' => Relogio::agora(),
            'impressora_agente_info' => json_encode([
                'computador' => mb_substr((string) ($info['computador'] ?? ''), 0, 60),
                'versao' => (int) ($info['versao'] ?? 0),
                'impressoras' => $impressoras,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $ajustes = $this->ajustes();
        if ($ajustes['modo'] === 'agente') {
            $this->criarAutomaticos($ajustes['origens']);
        }
        $this->expirarSemResposta();
        // Impressora pausada, sem papel ou com erro no Windows: as comandas esperam na fila (sem gastar tentativas) até ela voltar.
        $pronta = ($info['pronta'] ?? true) !== false;
        return ['ajustes' => $this->paraOAgente($ajustes), 'trabalhos' => $pronta ? $this->entregar($ajustes) : [], 'intervalo_s' => 3];
    }

    private function paraOAgente(array $a): array
    {
        return ['impressora' => $a['impressora'], 'linguagem' => $a['linguagem'], 'largura_mm' => $a['largura_mm'], 'altura_mm' => $a['altura_mm'],
                'margem_mm' => $a['margem_mm'], 'fonte' => $a['fonte'], 'vias' => $a['vias']];
    }

    /** Pedidos novos ainda sem comanda viram trabalhos da fila (cada pedido uma vez só, pelo claim atômico). */
    private function criarAutomaticos(array $origens): void
    {
        if ($origens === []) {
            return;
        }
        $desde = max($this->config->get('impressora_agente_desde', '2000-01-01 00:00:00'), gmdate('Y-m-d H:i:s', time() - 12 * 3600));
        $marcas = implode(', ', array_fill(0, count($origens), '?'));
        $sql = Banco::conexao()->prepare("SELECT id FROM pedidos WHERE impresso_em IS NULL AND status IN ('novo', 'em_preparo')
                                          AND origem IN ({$marcas}) AND criado_em >= ? ORDER BY id LIMIT 10");
        $sql->execute([...$origens, $desde]);
        foreach ($sql->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if ($this->pedidos->reivindicarImpressao((int) $id, 'agente')) {
                $this->novoTrabalho((int) $id, 'auto');
            }
        }
    }

    private function novoTrabalho(?int $pedidoId, string $tipo): int
    {
        return Banco::inserir(Banco::conexao(), 'INSERT INTO impressao_trabalhos (pedido_id, tipo, estado, tentativas, criado_em) VALUES (?, ?, ?, 0, ?)',
            [$pedidoId, $tipo, 'pendente', Relogio::agora()]);
    }

    /** Agente que pegou um trabalho e sumiu: depois de 3 min, o trabalho falha (não reimprime sozinho, para não sair em dobro). */
    private function expirarSemResposta(): void
    {
        Banco::conexao()->prepare("UPDATE impressao_trabalhos SET estado = 'falhou', erro = ? WHERE estado = 'enviado' AND enviado_em < ?")
            ->execute(['O agente não respondeu se imprimiu.', gmdate('Y-m-d H:i:s', time() - self::SEM_RESPOSTA_S)]);
    }

    /** Marca os pendentes como enviados (um agente por vez) e monta as linhas de cada comanda. */
    private function entregar(array $ajustes): array
    {
        $pdo = Banco::conexao();
        $pendentes = $pdo->query("SELECT * FROM impressao_trabalhos WHERE estado = 'pendente' ORDER BY id LIMIT 5")->fetchAll();
        $trabalhos = [];
        $nomeLoja = $this->config->get('nome_loja', 'Restaurante');
        foreach ($pendentes as $t) {
            $pega = $pdo->prepare("UPDATE impressao_trabalhos SET estado = 'enviado', enviado_em = ?, tentativas = tentativas + 1 WHERE id = ? AND estado = 'pendente'");
            $pega->execute([Relogio::agora(), $t['id']]);
            if ($pega->rowCount() !== 1) {
                continue;
            }
            $opcoes = ['nome_loja' => $nomeLoja, 'com_preco' => $ajustes['com_preco'], 'reimpressao' => $t['tipo'] === 'reimpressao'];
            if ($t['tipo'] === 'teste') {
                $linhas = Comanda::teste($opcoes);
            } else {
                $pedido = $this->pedidos->porId((int) $t['pedido_id']);
                if ($pedido === null) {
                    $this->resultado((int) $t['id'], false, 'O pedido não existe mais.', true);
                    continue;
                }
                $linhas = Comanda::linhas($pedido, $opcoes);
            }
            $trabalhos[] = ['id' => (int) $t['id'], 'pedido_id' => $t['pedido_id'] === null ? null : (int) $t['pedido_id'], 'tipo' => $t['tipo'], 'linhas' => $linhas];
        }
        return $trabalhos;
    }

    /** O agente contou como foi. Falha volta para a fila até 3 tentativas. */
    public function resultado(int $trabalhoId, bool $ok, ?string $erro, bool $definitivo = false): void
    {
        $pdo = Banco::conexao();
        $sql = $pdo->prepare('SELECT * FROM impressao_trabalhos WHERE id = ?');
        $sql->execute([$trabalhoId]);
        $t = $sql->fetch();
        if (!$t || !in_array($t['estado'], ['enviado', 'pendente'], true)) {
            return;
        }
        if ($ok) {
            $pdo->prepare("UPDATE impressao_trabalhos SET estado = 'feito', feito_em = ?, erro = NULL WHERE id = ?")->execute([Relogio::agora(), $trabalhoId]);
            return;
        }
        $desiste = $definitivo || (int) $t['tentativas'] >= self::TENTATIVAS;
        $pdo->prepare('UPDATE impressao_trabalhos SET estado = ?, erro = ? WHERE id = ?')
            ->execute([$desiste ? 'falhou' : 'pendente', mb_substr((string) $erro, 0, 300), $trabalhoId]);
        if ($t['pedido_id'] !== null) {
            // O cartão do Kanban passa a mostrar a falha (o painel só busca o que mudou).
            $pdo->prepare('UPDATE pedidos SET atualizado_em = ? WHERE id = ?')->execute([Relogio::agora(), $t['pedido_id']]);
        }
    }

    // ---------- Pedidos do painel ----------

    public function online(): bool
    {
        $contato = $this->config->get('impressora_agente_contato_em');
        return $contato !== '' && $contato >= gmdate('Y-m-d H:i:s', time() - self::OFFLINE_S);
    }

    /** Botão Reimprimir com o modo agente: a comanda vai para a fila do agente. */
    public function pedirReimpressao(int $pedidoId): void
    {
        $this->novoTrabalho($pedidoId, 'reimpressao');
    }

    public function pedirTeste(): void
    {
        $this->novoTrabalho(null, 'teste');
    }

    /** Tela Impressora: o que o agente contou, a parametrização e os últimos trabalhos (sem o código). */
    public function situacao(): array
    {
        $info = json_decode($this->config->get('impressora_agente_info', ''), true);
        $info = is_array($info) ? $info : ['computador' => '', 'versao' => 0, 'impressoras' => []];
        $trabalhos = Banco::conexao()->query('SELECT t.*, p.cliente_nome FROM impressao_trabalhos t LEFT JOIN pedidos p ON p.id = t.pedido_id ORDER BY t.id DESC LIMIT 12')->fetchAll();
        return [
            'ajustes' => $this->ajustes(),
            'agente' => [
                'tem_codigo' => $this->config->get('impressora_agente_hash') !== '',
                'online' => $this->online(),
                'contato_em' => Relogio::iso($this->config->get('impressora_agente_contato_em') ?: null),
                'computador' => $info['computador'] ?? '',
                'versao' => (int) ($info['versao'] ?? 0),
                'impressoras' => array_map(static fn (array $i): array => $i + ['virtual' => self::virtual($i)], $info['impressoras'] ?? []),
                'confirmada_em' => Relogio::iso($this->config->get('impressora_confirmada_em') ?: null),
            ],
            'alerta' => $this->alertaParaOPainel(),
            'trabalhos' => array_map(static fn (array $t): array => [
                'id' => (int) $t['id'], 'pedido_id' => $t['pedido_id'] === null ? null : (int) $t['pedido_id'], 'cliente_nome' => $t['cliente_nome'],
                'tipo' => $t['tipo'], 'estado' => $t['estado'], 'tentativas' => (int) $t['tentativas'], 'erro' => $t['erro'],
                'criado_em' => Relogio::iso($t['criado_em']), 'feito_em' => Relogio::iso($t['feito_em']),
            ], $trabalhos),
        ];
    }

    /** PDF, OneNote, XPS, fax e afins: existem no Windows, mas não saem em papel. */
    public static function virtual(array $impressora): bool
    {
        if (in_array(strtoupper((string) ($impressora['porta'] ?? '')), self::PORTAS_VIRTUAIS, true)) {
            return true;
        }
        return preg_match('/\b(pdf|onenote|xps|fax)\b|enviar para|send to/i', (string) ($impressora['nome'] ?? '')) === 1;
    }

    /**
     * Faixa vermelha do painel (qualquer tela), só no modo agente: agente fora do ar, impressora com problema no Windows ou
     * comanda que não saiu. null = tudo certo.
     * @return array{estado:string, texto:string, pedidos?:list<int>}|null
     */
    public function alertaParaOPainel(): ?array
    {
        $a = $this->ajustes();
        if ($a['modo'] !== 'agente' || $this->config->get('impressora_agente_hash') === '') {
            return null;
        }
        if (!$this->online()) {
            $contato = $this->config->get('impressora_agente_contato_em');
            return ['estado' => 'offline', 'texto' => $contato === ''
                ? 'O programa da impressora ainda não foi aberto no computador da impressora. As comandas não estão saindo.'
                : 'O computador da impressora está desligado ou sem internet. As comandas não estão saindo.'];
        }
        $info = json_decode($this->config->get('impressora_agente_info', ''), true);
        $impressoras = is_array($info['impressoras'] ?? null) ? $info['impressoras'] : [];
        $escolhida = null;
        foreach ($impressoras as $i) {
            if ($a['impressora'] !== '' ? $i['nome'] === $a['impressora'] : !empty($i['padrao'])) {
                $escolhida = $i;
            }
        }
        if ($a['impressora'] !== '' && $escolhida === null) {
            return ['estado' => 'impressora', 'texto' => "A impressora \"{$a['impressora']}\" não foi encontrada no computador da impressora."];
        }
        if ($escolhida !== null && in_array($escolhida['status'], self::PROBLEMAS, true)) {
            return ['estado' => 'impressora', 'texto' => "A impressora \"{$escolhida['nome']}\" está " . self::PROBLEMAS_PT[$escolhida['status']] . '. As comandas ficam na fila até ela voltar.'];
        }
        // Comandas que falharam nas últimas 2 h e não foram reimpressas depois.
        $sql = Banco::conexao()->prepare("SELECT DISTINCT t.pedido_id FROM impressao_trabalhos t WHERE t.estado = 'falhou' AND t.pedido_id IS NOT NULL AND t.criado_em >= ?
                                          AND NOT EXISTS (SELECT 1 FROM impressao_trabalhos d WHERE d.pedido_id = t.pedido_id AND d.id > t.id AND d.estado IN ('feito', 'pendente', 'enviado'))
                                          ORDER BY t.pedido_id");
        $sql->execute([gmdate('Y-m-d H:i:s', time() - 7200)]);
        $falhas = array_map('intval', $sql->fetchAll(\PDO::FETCH_COLUMN));
        if ($falhas !== []) {
            return ['estado' => 'falhas', 'pedidos' => $falhas, 'texto' => (count($falhas) === 1 ? 'A comanda do pedido #' . $falhas[0] . ' não saiu.' : 'As comandas dos pedidos #' . implode(', #', $falhas) . ' não saíram.') . ' Use "Reimprimir" no pedido.'];
        }
        return null;
    }
}
