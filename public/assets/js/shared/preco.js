// Regra de preço dos pratos, igual à do servidor (app/Services/PrecoService.php, D87).
// Aqui ela serve para MOSTRAR o preço em tempo real; quem decide o valor do pedido é o servidor.
//
//   Prato da casa:   preço fixo do prato no tamanho escolhido (item.precos).
//   Monte sua massa: tamanho + acréscimo da massa + acréscimo do molho + preço de cada ingrediente.
//                    Ingrediente de preço 0 é "incluso"; os de preço maior que 0 são os "extras".

const achar = (lista, id) => lista.find((o) => o.id === id) ?? null;

/** Preço fixo de um prato da casa num tamanho, ou null se ele não é vendido nesse tamanho. */
export const precoDoPrato = (item, tamanhoId) => item.precos?.find((p) => p.tamanho_id === tamanhoId)?.preco_centavos ?? null;

/**
 * @param {{ tamanhos: any[], massas: any[], molhos: any[], ingredientes: any[], itens: any[] }} cardapio
 * @param {{ item_id?: number|null, tamanho_id?: number|null, massa_id?: number|null, molho_id?: number|null, ingrediente_ids?: number[] }} escolha
 */
export function calcularMontagem(cardapio, escolha) {
  const tamanho = achar(cardapio.tamanhos, escolha.tamanho_id);
  const item = escolha.item_id ? achar(cardapio.itens, escolha.item_id) : null;
  if (item) {
    const preco = tamanho ? precoDoPrato(item, tamanho.id) : null;
    return {
      tamanho, massa: achar(cardapio.massas, item.massa_id), molho: achar(cardapio.molhos, item.molho_id),
      inclusos: item.ingrediente_ids.map((id) => achar(cardapio.ingredientes, id)).filter(Boolean), extras: [], valorExtras: 0,
      preco: preco ?? 0, completa: preco !== null,
    };
  }
  const massa = achar(cardapio.massas, escolha.massa_id);
  const molho = achar(cardapio.molhos, escolha.molho_id);
  const escolhidos = [...new Set(escolha.ingrediente_ids ?? [])].map((id) => achar(cardapio.ingredientes, id)).filter(Boolean);
  escolhidos.sort((a, b) => a.ordem - b.ordem || a.id - b.id);
  const extras = escolhidos.filter((i) => i.preco_extra_centavos > 0);
  const valorExtras = extras.reduce((soma, i) => soma + i.preco_extra_centavos, 0);
  return {
    tamanho,
    massa,
    molho,
    inclusos: escolhidos.filter((i) => i.preco_extra_centavos === 0),
    extras,
    valorExtras,
    preco: (tamanho?.preco_centavos ?? 0) + (massa?.acrescimo_centavos ?? 0) + (molho?.acrescimo_centavos ?? 0) + valorExtras,
    completa: Boolean(tamanho && massa && molho),
  };
}

/** Menor preço possível (para o "a partir de"): do prato da casa, se vier um, ou do "Monte sua massa". */
export function precoMinimo(cardapio, item = null) {
  const disponiveis = cardapio.tamanhos.filter((t) => t.estado === 'disponivel');
  const precos = disponiveis.map((t) => (item ? precoDoPrato(item, t.id) : t.preco_centavos)).filter((p) => p !== null);
  return precos.length ? Math.min(...precos) : null;
}
