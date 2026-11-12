<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ConfigRepository;

// Dados da loja para o cardápio e a regra de "aberta ou fechada agora".
final class LojaService
{
    private const DIAS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    public function __construct(private readonly ConfigRepository $config = new ConfigRepository()) {}

    /** @return array<string, list<array{0:string,1:string}>> dia da semana (0 = domingo) → faixas "HH:MM" */
    public function horarios(): array
    {
        $horarios = json_decode($this->config->get('horarios', '{}'), true);
        return is_array($horarios) ? $horarios : [];
    }

    /** @return array{aberta:bool, mensagem:string} */
    public function estado(?\DateTimeImmutable $agora = null): array
    {
        $modo = $this->config->get('funcionamento', 'auto');
        if ($modo === 'aberta') {
            return ['aberta' => true, 'mensagem' => 'Aberto agora'];
        }
        if ($modo === 'fechada') {
            return ['aberta' => false, 'mensagem' => 'Fechado no momento'];
        }

        $agora ??= new \DateTimeImmutable('now', Relogio::fusoDaLoja());
        $horarios = $this->horarios();
        $hoje = (int) $agora->format('w');
        $hora = $agora->format('H:i');

        foreach ($horarios[(string) $hoje] ?? [] as [$abre, $fecha]) {
            if ($hora >= $abre && $hora < $fecha) {
                return ['aberta' => true, 'mensagem' => "Aberto até {$fecha}"];
            }
        }
        // Fechada: procura a próxima abertura (ainda hoje ou nos próximos 7 dias).
        for ($salto = 0; $salto <= 7; $salto++) {
            $dia = ($hoje + $salto) % 7;
            foreach ($horarios[(string) $dia] ?? [] as [$abre]) {
                if ($salto === 0 && $abre <= $hora) {
                    continue;
                }
                $quando = match ($salto) {
                    0 => 'hoje',
                    1 => 'amanhã',
                    default => self::DIAS[$dia],
                };
                return ['aberta' => false, 'mensagem' => "Fechado · abre {$quando} às {$abre}"];
            }
        }
        return ['aberta' => false, 'mensagem' => 'Fechado no momento'];
    }

    /** Dados da loja que o cardápio pode mostrar. */
    public function publica(): array
    {
        $c = $this->config;
        // O cliente só pede entrega ou retirada. Mesa e pra viagem são registrados pela atendente, no salão.
        $tipos = [];
        foreach (PedidoService::TIPOS_DO_CLIENTE as $tipo) {
            if ($c->get("aceita_{$tipo}", '1') === '1') {
                $tipos[] = $tipo;
            }
        }
        return [
            'nome'                  => $c->get('nome_loja', 'Nome do Restaurante'),
            'tema'                  => $c->get('tema', 'italia'),
            'whatsapp'              => $c->get('whatsapp_loja'),
            'endereco'              => $c->get('endereco_loja'),
            'tipos_pedido'          => $tipos,
            'taxa_entrega_centavos' => $c->inteiro('taxa_entrega_centavos'),
            'area_entrega'          => $c->get('area_entrega'),
            'tempo_retirada_min'    => $c->inteiro('tempo_retirada_min', 25),
            'tempo_entrega_min'     => $c->inteiro('tempo_entrega_min', 50),
            'horarios'              => $this->horarios(),
        ] + $this->estado();
    }
}
