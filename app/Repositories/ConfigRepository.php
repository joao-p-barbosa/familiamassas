<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Banco;

// Configurações da loja (tabela chave → valor).
final class ConfigRepository
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public function todas(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Banco::conexao()->query('SELECT chave, valor FROM configuracoes') as $linha) {
                self::$cache[$linha['chave']] = $linha['valor'];
            }
        }
        return self::$cache;
    }

    public function get(string $chave, string $padrao = ''): string
    {
        return $this->todas()[$chave] ?? $padrao;
    }

    public function inteiro(string $chave, int $padrao = 0): int
    {
        $valor = $this->get($chave, (string) $padrao);
        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    /** @param array<string,string> $valores */
    public function salvar(array $valores): void
    {
        $pdo = Banco::conexao();
        $existe = $pdo->prepare('SELECT COUNT(*) FROM configuracoes WHERE chave = ?');
        $atualiza = $pdo->prepare('UPDATE configuracoes SET valor = ? WHERE chave = ?');
        $insere = $pdo->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?)');
        foreach ($valores as $chave => $valor) {
            $existe->execute([$chave]);
            if ((int) $existe->fetchColumn() > 0) {
                $atualiza->execute([$valor, $chave]);
            } else {
                $insere->execute([$chave, $valor]);
            }
        }
        self::$cache = null;
    }
}
