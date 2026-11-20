<?php
declare(strict_types=1);

namespace App\IA;

use App\Core\Banco;
use App\Core\Relogio;
use App\Repositories\ConfigRepository;

/**
 * Tudo que o assistente guarda: configuração (tabela configuracoes, chaves ia_*), perguntas e respostas, fluxos em texto
 * e o registro de uso (uma linha por resposta, com o custo real devolvido pelo OpenRouter, em micro-dólares).
 * A chave da API NÃO fica aqui: só na variável de ambiente (D70).
 */
final class AssistenteRepository
{
    public const MODELO_PADRAO = 'anthropic/claude-haiku-5.5';
    public const TONS = ['amigavel' => 'Amigável', 'neutro' => 'Neutro', 'profissional' => 'Profissional', 'bem_humorado' => 'Bem-humorado', 'personalizado' => 'Personalizado'];
    public const TAMANHOS = ['curta' => 'Curta', 'padrao' => 'Padrão', 'detalhada' => 'Detalhada'];

    /** Modelos para a lista do seletor (o campo também aceita qualquer outro identificador do OpenRouter). Preço por milhão de tokens, conferido em 15/11/2026. */
    public const MODELOS_SUGERIDOS = [
        ['id' => 'anthropic/claude-haiku-5.5', 'nome' => 'Claude Haiku 5.5', 'preco' => 'US$ 0,10 entrada · US$ 0,50 saída', 'gratis' => false],
        ['id' => 'openai/gpt-oss-20b', 'nome' => 'GPT-OSS 20B', 'preco' => 'US$ 0,03 entrada · US$ 0,13 saída', 'gratis' => false],
        ['id' => 'meta-llama/llama-3.1-8b-instruct', 'nome' => 'Llama 3.1 8B', 'preco' => 'US$ 0,02 entrada · US$ 0,04 saída', 'gratis' => false],
        ['id' => 'deepseek/deepseek-v4.1-flash', 'nome' => 'DeepSeek V4.1 Flash', 'preco' => 'US$ 0,05 entrada · US$ 0,20 saída', 'gratis' => false],
        ['id' => 'google/gemma-4-31b-it:free', 'nome' => 'Gemma 4 31B (gratuito)', 'preco' => 'grátis · só para teste: 20 por minuto, 50 por dia', 'gratis' => true],
    ];

    /** @var array<string,string> */
    public const PADROES = [
        'ia_ativo' => '0',
        'whatsapp_palavras_cardapio' => 'Cardápio',   // abre o pedido pelo chat (D74); não é da IA, mas mora na mesma tela
        'whatsapp_enquetes' => '1',                    // escolhas como enquete no canal QR (D85); desligado: lista numerada (D97)
        'ia_modelo' => self::MODELO_PADRAO,
        'ia_nome' => 'Assistente',
        'ia_papel' => 'Você atende os clientes da loja pelo WhatsApp: tira dúvidas sobre o cardápio, os horários e a entrega, e leva o cliente a fazer o pedido pelo link do cardápio.',
        'ia_tom' => 'amigavel',
        'ia_tom_texto' => '',
        'ia_tamanho' => 'curta',
        'ia_saudacao' => 'Olá! Tudo bem?',
        'ia_avisar_ia' => '1',
        'ia_regras' => 'Nunca invente preço, ingrediente, horário, promoção ou prazo. Não fale de assuntos que não sejam da loja.',
        'ia_escalonar' => 'Passe para uma pessoa quando o cliente reclamar, pedir reembolso, falar de alergia ou intolerância grave, ou pedir algo que você não sabe responder.',
        'ia_texto_livre' => '',
        'ia_incluir_cardapio' => '1',
        'ia_incluir_horarios' => '1',
        'ia_incluir_entrega' => '1',
        'ia_teto_usd' => '5',
        'ia_alerta_saldo_usd' => '1',
        'ia_privacidade' => 'deny',
        'ia_limite_hora' => '15',
        'ia_memoria' => '8',
    ];

    public function __construct(private readonly ConfigRepository $config = new ConfigRepository()) {}

    /** @return array<string,string> toda a configuração, com os padrões onde nada foi salvo */
    public function config(): array
    {
        $r = [];
        foreach (self::PADROES as $chave => $padrao) {
            $r[$chave] = $this->config->get($chave, $padrao);
        }
        return $r;
    }

    /** @param array<string,string> $valores só chaves de PADROES */
    public function salvarConfig(array $valores): void
    {
        $this->config->salvar(array_intersect_key($valores, self::PADROES));
    }

    public function ligada(): bool
    {
        return $this->config->get('ia_ativo', '0') === '1';
    }

    /** @return list<array{id:int, pergunta:string, resposta:string, ativo:bool}> */
    public function faqs(bool $soAtivas = false): array
    {
        $linhas = Banco::conexao()->query('SELECT id, pergunta, resposta, ativo FROM assistente_faq ORDER BY ordem, id')->fetchAll();
        $lista = array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'pergunta' => $l['pergunta'], 'resposta' => $l['resposta'], 'ativo' => (int) $l['ativo'] === 1], $linhas);
        return $soAtivas ? array_values(array_filter($lista, static fn (array $f): bool => $f['ativo'])) : $lista;
    }

    /** @return list<array{id:int, titulo:string, quando:string, passos:string, ativo:bool}> */
    public function fluxos(bool $soAtivos = false): array
    {
        $linhas = Banco::conexao()->query('SELECT id, titulo, quando, passos, ativo FROM assistente_fluxos ORDER BY ordem, id')->fetchAll();
        $lista = array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'titulo' => $l['titulo'], 'quando' => $l['quando'], 'passos' => $l['passos'], 'ativo' => (int) $l['ativo'] === 1], $linhas);
        return $soAtivos ? array_values(array_filter($lista, static fn (array $f): bool => $f['ativo'])) : $lista;
    }

    /**
     * Troca as duas listas inteiras de uma vez (a tela manda tudo ao salvar). Ou entra tudo, ou nada.
     * @param list<array{pergunta:string, resposta:string, ativo:bool}> $faqs
     * @param list<array{titulo:string, quando:string, passos:string, ativo:bool}> $fluxos
     */
    public function salvarListas(array $faqs, array $fluxos): void
    {
        Banco::transacao(static function (\PDO $pdo) use ($faqs, $fluxos): void {
            $pdo->exec('DELETE FROM assistente_faq');
            $pdo->exec('DELETE FROM assistente_fluxos');
            $f = $pdo->prepare('INSERT INTO assistente_faq (pergunta, resposta, ativo, ordem) VALUES (?, ?, ?, ?)');
            foreach (array_values($faqs) as $i => $item) {
                $f->execute([$item['pergunta'], $item['resposta'], $item['ativo'] ? 1 : 0, $i]);
            }
            $x = $pdo->prepare('INSERT INTO assistente_fluxos (titulo, quando, passos, ativo, ordem) VALUES (?, ?, ?, ?, ?)');
            foreach (array_values($fluxos) as $i => $item) {
                $x->execute([$item['titulo'], $item['quando'], $item['passos'], $item['ativo'] ? 1 : 0, $i]);
            }
        });
    }

    // ---------- Uso e custo ----------

    /**
     * Uma resposta da IA (ou uma tentativa que falhou).
     * @param list<string> $ferramentas
     */
    public function registrarUso(string $origem, ?string $telefone, string $modelo, int $tokensIn, int $tokensOut, float $custoUsd, int $ms, array $ferramentas, ?string $erro): void
    {
        Banco::conexao()->prepare(
            'INSERT INTO ia_uso (criado_em, origem, telefone, modelo, tokens_in, tokens_out, custo_micro, duracao_ms, ferramentas, erro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Relogio::agora(), $origem, $telefone, mb_substr($modelo, 0, 100), $tokensIn, $tokensOut, (int) round($custoUsd * 1_000_000), $ms,
            $ferramentas === [] ? null : implode(',', $ferramentas), $erro === null ? null : mb_substr($erro, 0, 300),
        ]);
    }

    /** Início do mês em UTC: é a mesma janela que o OpenRouter usa no saldo mensal da chave. */
    public static function inicioDoMes(): string
    {
        return gmdate('Y-m-01 00:00:00');
    }

    /** Gasto do mês, em dólares. Inclui os testes do painel: eles gastam o mesmo crédito e entram no teto. */
    public function gastoDoMes(): float
    {
        $consulta = Banco::conexao()->prepare('SELECT COALESCE(SUM(custo_micro), 0) FROM ia_uso WHERE criado_em >= ?');
        $consulta->execute([self::inicioDoMes()]);
        return (int) $consulta->fetchColumn() / 1_000_000.0;
    }

    /** Quantas respostas da IA este telefone já recebeu na última hora (limite contra quem spamma o número e gasta o crédito). */
    public function respostasNaUltimaHora(string $telefone): int
    {
        $consulta = Banco::conexao()->prepare("SELECT COUNT(*) FROM ia_uso WHERE telefone = ? AND origem = 'whatsapp' AND erro IS NULL AND criado_em >= ?");
        $consulta->execute([$telefone, gmdate('Y-m-d H:i:s', time() - 3600)]);
        return (int) $consulta->fetchColumn();
    }

    /**
     * Números para a tela "Modelo e custo".
     * @return array{mes_usd:float, hoje_usd:float, mes_respostas:int, mes_erros:int, por_modelo:list<array{modelo:string, respostas:int, custo_usd:float, tokens_in:int, tokens_out:int}>, recentes:list<array<string,mixed>>}
     */
    public function resumoDeUso(): array
    {
        $pdo = Banco::conexao();
        $mes = self::inicioDoMes();
        $hoje = gmdate('Y-m-d 00:00:00');
        $soma = static function (string $sql, array $p) use ($pdo): int {
            $c = $pdo->prepare($sql);
            $c->execute($p);
            return (int) $c->fetchColumn();
        };
        $porModelo = $pdo->prepare('SELECT modelo, COUNT(*) AS respostas, SUM(custo_micro) AS custo, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout FROM ia_uso WHERE criado_em >= ? AND erro IS NULL GROUP BY modelo ORDER BY custo DESC');
        $porModelo->execute([$mes]);
        $recentes = $pdo->query('SELECT criado_em, origem, modelo, tokens_in, tokens_out, custo_micro, duracao_ms, ferramentas, erro FROM ia_uso ORDER BY id DESC LIMIT 15')->fetchAll();
        return [
            'mes_usd' => $soma('SELECT COALESCE(SUM(custo_micro), 0) FROM ia_uso WHERE criado_em >= ?', [$mes]) / 1_000_000.0,
            'hoje_usd' => $soma('SELECT COALESCE(SUM(custo_micro), 0) FROM ia_uso WHERE criado_em >= ?', [$hoje]) / 1_000_000.0,
            'mes_respostas' => $soma('SELECT COUNT(*) FROM ia_uso WHERE criado_em >= ? AND erro IS NULL', [$mes]),
            'mes_erros' => $soma('SELECT COUNT(*) FROM ia_uso WHERE criado_em >= ? AND erro IS NOT NULL', [$mes]),
            'por_modelo' => array_map(static fn (array $l): array => [
                'modelo' => $l['modelo'], 'respostas' => (int) $l['respostas'], 'custo_usd' => (int) $l['custo'] / 1_000_000.0, 'tokens_in' => (int) $l['tin'], 'tokens_out' => (int) $l['tout'],
            ], $porModelo->fetchAll()),
            'recentes' => array_map(static fn (array $l): array => [
                'em' => Relogio::iso($l['criado_em']), 'origem' => $l['origem'], 'modelo' => $l['modelo'], 'tokens_in' => (int) $l['tokens_in'], 'tokens_out' => (int) $l['tokens_out'],
                'custo_usd' => (int) $l['custo_micro'] / 1_000_000.0, 'ms' => (int) $l['duracao_ms'], 'ferramentas' => $l['ferramentas'], 'erro' => $l['erro'],
            ], $recentes),
        ];
    }

    // ---------- Crédito do cliente (D72) ----------

    /**
     * Saldo do cliente: total das recargas menos o gasto do assistente no WhatsApp desde a primeira recarga.
     * Os testes do painel ficam de fora (são por sua conta). Sem recarga nenhuma, o controle está desligado (`ativa` = false).
     * @return array{ativa:bool, saldo_usd:float, recargas_usd:float, gasto_usd:float, desde:?string}
     */
    public function carteira(): array
    {
        $pdo = Banco::conexao();
        $linha = $pdo->query('SELECT COALESCE(SUM(valor_micro), 0) AS total, MIN(criado_em) AS primeira, COUNT(*) AS n FROM ia_recargas')->fetch();
        if ((int) $linha['n'] === 0) {
            return ['ativa' => false, 'saldo_usd' => 0.0, 'recargas_usd' => 0.0, 'gasto_usd' => 0.0, 'desde' => null];
        }
        $gasto = $pdo->prepare("SELECT COALESCE(SUM(custo_micro), 0) FROM ia_uso WHERE origem = 'whatsapp' AND criado_em >= ?");
        $gasto->execute([$linha['primeira']]);
        $gastoMicro = (int) $gasto->fetchColumn();
        return [
            'ativa' => true,
            'saldo_usd' => ((int) $linha['total'] - $gastoMicro) / 1_000_000.0,
            'recargas_usd' => (int) $linha['total'] / 1_000_000.0,
            'gasto_usd' => $gastoMicro / 1_000_000.0,
            'desde' => Relogio::iso($linha['primeira']),
        ];
    }

    /** @throws \InvalidArgumentException valor fora de US$ 0,01 a US$ 10.000 */
    public function registrarRecarga(float $valorUsd, string $nota): void
    {
        if ($valorUsd < 0.01 || $valorUsd > 10_000) {
            throw new \InvalidArgumentException('A recarga vai de US$ 0,01 a US$ 10.000.');
        }
        Banco::conexao()->prepare('INSERT INTO ia_recargas (criado_em, valor_micro, nota) VALUES (?, ?, ?)')
            ->execute([Relogio::agora(), (int) round($valorUsd * 1_000_000), mb_substr(trim($nota), 0, 120)]);
        $this->limparSemCredito();   // acabou de entrar crédito: a próxima mensagem já tenta a IA de novo
    }

    public function apagarRecarga(int $id): void
    {
        Banco::conexao()->prepare('DELETE FROM ia_recargas WHERE id = ?')->execute([$id]);
    }

    /** @return list<array{id:int, em:?string, valor_usd:float, nota:string}> */
    public function recargas(int $limite = 10): array
    {
        $linhas = Banco::conexao()->query('SELECT id, criado_em, valor_micro, nota FROM ia_recargas ORDER BY id DESC LIMIT ' . max(1, min(100, $limite)))->fetchAll();
        return array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'em' => Relogio::iso($l['criado_em']), 'valor_usd' => (int) $l['valor_micro'] / 1_000_000.0, 'nota' => $l['nota']], $linhas);
    }

    // ---------- "Sem crédito" no OpenRouter ----------

    /** O OpenRouter respondeu 402 (sem créditos ou limite da chave): fica marcado por alguns minutos para o bot não insistir. */
    public function marcarSemCredito(): void
    {
        $this->config->salvar(['ia_sem_credito_em' => Relogio::agora()]);
    }

    public function limparSemCredito(): void
    {
        if ($this->config->get('ia_sem_credito_em') !== '') {
            $this->config->salvar(['ia_sem_credito_em' => '']);
        }
    }

    /** Desde quando o OpenRouter está recusando por falta de crédito (null = não está). */
    public function semCreditoDesde(): ?string
    {
        $valor = $this->config->get('ia_sem_credito_em');
        return $valor === '' ? null : $valor;
    }

    /** Registros de uso com mais de 180 dias saem (o gasto do mês é o que importa). */
    public function limparAntigos(): void
    {
        Banco::conexao()->prepare('DELETE FROM ia_uso WHERE criado_em < ?')->execute([gmdate('Y-m-d H:i:s', time() - 180 * 86400)]);
    }
}
