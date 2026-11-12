<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;

/**
 * Tempo de produção de um pedido = soma dos períodos em que ele esteve em "Novo" e "Em preparo".
 *
 * É calculado pelo histórico de status, não pela hora do pedido: mudar de Novo para Em preparo não zera o
 * relógio, e um pedido que voltou de "Pronto" para "Em preparo" soma o tempo antigo ao novo.
 * O painel recebe duas coisas e faz a conta viva no navegador:
 *   acumulado_s  segundos dos períodos já fechados;
 *   desde        quando começou o período aberto (null se o pedido não está mais sendo produzido).
 * Tempo atual = acumulado_s + (agora - desde).
 */
final class TempoDePreparo
{
    /** Status que contam como "em produção". */
    public const CONTADOS = ['novo', 'em_preparo'];

    /** Limites das cores do cartão, em minutos de produção (também em public/assets/js/shared/tempo.js). */
    public const LIMITE_ATENCAO_MIN = 20;
    public const LIMITE_ATRASO_MIN = 30;

    /**
     * @param list<array{status:string, criado_em:string}> $historico  mudanças de status em ordem; datas "Y-m-d H:i:s" (UTC)
     * @return array{acumulado_s:int, desde:?string}  `desde` em ISO 8601
     */
    public static function calcular(array $historico): array
    {
        $acumulado = 0;
        $desde = null;
        $total = count($historico);
        foreach ($historico as $i => $linha) {
            if (!in_array($linha['status'], self::CONTADOS, true)) {
                continue;
            }
            if ($i + 1 < $total) {
                $acumulado += max(0, self::segundos($historico[$i + 1]['criado_em']) - self::segundos($linha['criado_em']));
            } else {
                $desde = Relogio::iso($linha['criado_em']);   // período aberto: o painel soma o resto
            }
        }
        return ['acumulado_s' => $acumulado, 'desde' => $desde];
    }

    /** "Cor" do cartão para um tempo em segundos: neutro (até 19 min), atencao (20 a 29) ou atrasado (30 ou mais). */
    public static function faixa(int $segundos): string
    {
        $minutos = intdiv(max(0, $segundos), 60);
        return match (true) {
            $minutos >= self::LIMITE_ATRASO_MIN => 'atrasado',
            $minutos >= self::LIMITE_ATENCAO_MIN => 'atencao',
            default => 'neutro',
        };
    }

    private static function segundos(string $dataUtc): int
    {
        return (int) strtotime($dataUtc . ' UTC');
    }
}
