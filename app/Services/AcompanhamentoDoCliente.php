<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Requisicao;
use App\Repositories\PedidoRepository;

/**
 * Lembra quais pedidos pertencem a este navegador, para a Home mostrar o acompanhamento mesmo depois de
 * atualizar a página ou fechar e abrir o navegador.
 *
 * Fica num cookie do servidor (não no localStorage): sobrevive à limpeza que o Safari faz no armazenamento
 * da página e já chega junto com a requisição, então a Home nasce com o pedido no lugar, sem piscar.
 * O cookie guarda só os códigos aleatórios dos pedidos (os mesmos que já estão no link de acompanhamento).
 */
final class AcompanhamentoDoCliente
{
    public const COOKIE = 'fm_pedidos';
    public const MAXIMO = 3;
    /** Depois de encerrado, o pedido continua na Home por este tempo (para o cliente ver que deu certo). */
    public const SEGUNDOS_APOS_ENCERRAR = 3 * 3600;
    private const VALIDADE = 7 * 86400;

    public function __construct(
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
        private readonly PedidoService $servico = new PedidoService(),
    ) {}

    /** @return list<string> códigos guardados neste navegador (mais novo primeiro) */
    public function codigos(Requisicao $req): array
    {
        $codigos = [];
        foreach (explode(',', $req->cookie(self::COOKIE)) as $codigo) {
            if (preg_match('/^[0-9a-f]{16}$/', $codigo) === 1 && !in_array($codigo, $codigos, true)) {
                $codigos[] = $codigo;
            }
        }
        return array_slice($codigos, 0, self::MAXIMO);
    }

    public function registrar(Requisicao $req, string $codigo): void
    {
        $this->gravar($req, array_slice(array_values(array_unique([$codigo, ...$this->codigos($req)])), 0, self::MAXIMO));
    }

    public function remover(Requisicao $req, string $codigo): void
    {
        $this->gravar($req, array_values(array_filter($this->codigos($req), static fn (string $c): bool => $c !== $codigo)));
    }

    /**
     * Pedidos para mostrar na Home: os que ainda andam e os que acabaram de encerrar.
     * O que sumiu do banco ou encerrou há muito tempo sai do cookie.
     * @return list<array<string,mixed>> no formato público do pedido, sem a lista de itens
     */
    public function paraHome(Requisicao $req): array
    {
        $guardados = $this->codigos($req);
        $validos = [];
        $lista = [];
        foreach ($guardados as $codigo) {
            $pedido = $this->pedidos->porCodigo($codigo);
            if ($pedido === null) {
                continue;
            }
            $encerrado = in_array($pedido['status'], PedidoRepository::ENCERRADOS, true);
            $desde = (int) strtotime($pedido['status_atualizado_em']);
            if ($encerrado && time() - $desde > self::SEGUNDOS_APOS_ENCERRAR) {
                continue;
            }
            $validos[] = $codigo;
            $publico = $this->servico->publico($pedido);
            unset($publico['itens']);
            $lista[] = $publico;
        }
        if ($validos !== $guardados) {
            $this->gravar($req, $validos);
        }
        return $lista;
    }

    /** @param list<string> $codigos */
    private function gravar(Requisicao $req, array $codigos): void
    {
        $_COOKIE[self::COOKIE] = implode(',', $codigos);   // quem perguntar de novo na mesma requisição já vê o valor novo
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, implode(',', $codigos), [
            'expires'  => $codigos === [] ? time() - 3600 : time() + self::VALIDADE,
            'path'     => '/',
            'secure'   => $req->https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
