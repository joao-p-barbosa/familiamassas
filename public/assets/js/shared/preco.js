// Regra de preço do "Monte seu prato", igual à do servidor (app/Services/PrecoService.php).
// Aqui ela serve para MOSTRAR o preço em tempo real; quem decide o valor do pedido é o servidor.
//
//   preço  = tamanho + acréscimo da massa + acréscimo do molho + extras
//   extras = ingredientes além da quantidade inclusa no tamanho.
//   Quando passa do limite, os mais caros ficam como inclusos e os mais baratos são cobrados.

/**
 * @param {{ tamanhos: any[], massas: any[], molhos: any[], ingredientes: any[] }} cardapio
 * @param {{ tamanho_id?: number|null, massa_id?: number|null, molho_id?: number|null, ingrediente_ids?: number[] }} escolha
 */
export function calcularMontagem(cardapio, escolha) {
  const achar = (lista, id) => lista.find((o) => o.id === id) ?? null;
  const tamanho = achar(cardapio.tamanhos, escolha.tamanho_id);
  const massa = achar(cardapio.massas, escolha.massa_id);
  const molho = achar(cardapio.molhos, escolha.molho_id);

  const escolhidos = [...new Set(escolha.ingrediente_ids ?? [])].map((id) => achar(cardapio.ingredientes, id)).filter(Boolean);
  escolhidos.sort((a, b) => b.preco_extra_centavos - a.preco_extra_centavos || a.ordem - b.ordem || a.id - b.id);

  const limite = tamanho?.inclusos ?? 0;
  const inclusos = tamanho ? escolhidos.slice(0, limite) : escolhidos;
  const extras = tamanho ? escolhidos.slice(limite) : [];
  const valorExtras = extras.reduce((soma, i) => soma + i.preco_extra_centavos, 0);

  return {
    tamanho,
    massa,
    molho,
    inclusos,
    extras,
    limite,
    valorExtras,
    preco: (tamanho?.preco_centavos ?? 0) + (massa?.acrescimo_centavos ?? 0) + (molho?.acrescimo_centavos ?? 0) + valorExtras,
    completa: Boolean(tamanho && massa && molho),
  };
}

/** Menor preço possível de um prato montado (para o "a partir de"). */
export function precoMinimo(cardapio, { massa_id = null, molho_id = null } = {}) {
  const disponiveis = cardapio.tamanhos.filter((t) => t.estado === 'disponivel');
  if (disponiveis.length === 0) return null;
  const base = Math.min(...disponiveis.map((t) => t.preco_centavos));
  const acrescimo = (lista, id) => lista.find((o) => o.id === id)?.acrescimo_centavos ?? 0;
  return base + acrescimo(cardapio.massas, massa_id) + acrescimo(cardapio.molhos, molho_id);
}
