<?php
declare(strict_types=1);

namespace App\Core;

// Erro "esperado" (validação, não encontrado, sem permissão). Vira uma resposta JSON com o status certo.
final class ErroHttp extends \RuntimeException
{
    /** @param array<string,string> $campos erros por campo do formulário */
    public function __construct(
        public readonly int $status,
        public readonly string $codigo,
        string $mensagem,
        public readonly array $campos = [],
    ) {
        parent::__construct($mensagem);
    }

    /** @param array<string,string> $campos */
    public static function validacao(array $campos, string $mensagem = 'Confira os campos destacados.'): self
    {
        return new self(422, 'validacao', $mensagem, $campos);
    }

    public static function naoEncontrado(string $mensagem = 'Não encontramos o que você pediu.'): self
    {
        return new self(404, 'nao_encontrado', $mensagem);
    }
}
