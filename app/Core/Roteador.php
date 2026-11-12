<?php
declare(strict_types=1);

namespace App\Core;

// Roteador mínimo: liga "MÉTODO /caminho/{parametro}" a uma função.
final class Roteador
{
    /** @var list<array{metodo:string, padrao:string, acao:callable}> */
    private array $rotas = [];

    public function get(string $caminho, callable $acao): void    { $this->adicionar('GET', $caminho, $acao); }
    public function post(string $caminho, callable $acao): void   { $this->adicionar('POST', $caminho, $acao); }
    public function put(string $caminho, callable $acao): void    { $this->adicionar('PUT', $caminho, $acao); }
    public function patch(string $caminho, callable $acao): void  { $this->adicionar('PATCH', $caminho, $acao); }
    public function delete(string $caminho, callable $acao): void { $this->adicionar('DELETE', $caminho, $acao); }

    private function adicionar(string $metodo, string $caminho, callable $acao): void
    {
        // "/api/pedidos/{codigo}" vira a expressão regular "#^/api/pedidos/(?P<codigo>[^/]+)$#"
        $padrao = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $caminho) . '$#';
        $this->rotas[] = ['metodo' => $metodo, 'padrao' => $padrao, 'acao' => $acao];
    }

    public function despachar(Requisicao $req): void
    {
        $caminhoExiste = false;
        foreach ($this->rotas as $rota) {
            if (!preg_match($rota['padrao'], $req->caminho, $achados)) {
                continue;
            }
            $caminhoExiste = true;
            if ($rota['metodo'] !== $req->metodo && !($req->metodo === 'HEAD' && $rota['metodo'] === 'GET')) {
                continue;
            }
            $req->parametros = array_map('rawurldecode', array_filter($achados, 'is_string', ARRAY_FILTER_USE_KEY));
            ($rota['acao'])($req);
            return;
        }
        if (str_starts_with($req->caminho, '/api/')) {
            throw $caminhoExiste
                ? new ErroHttp(405, 'metodo_nao_permitido', 'Esta operação não existe para este endereço.')
                : ErroHttp::naoEncontrado('Endereço da API não encontrado.');
        }
        Resposta::pagina('nao-encontrado', [], 404);
    }
}
