// Tempo de produção do pedido e a cor do cartão no painel.
//
// Regra: o tempo é a SOMA dos períodos em "Novo" e "Em preparo" (o servidor soma os períodos já fechados e manda
// quando começou o período aberto; aqui só se completa a conta). Mudar de Novo para Em preparo não zera o relógio.
//   0 a 19 min   neutro
//   20 a 29 min  atencao  (amarelo)
//   30 min ou +  atrasado (vermelho)
// Os mesmos limites estão em app/Services/TempoDePreparo.php.
export const LIMITE_ATENCAO_MIN = 20;
export const LIMITE_ATRASO_MIN = 30;

/** Segundos de produção de um pedido neste instante. `agora` é a hora (em ms) já corrigida para a do servidor. */
export function segundosDeProducao(pedido, agora = Date.now()) {
  const aberto = pedido.producao_desde ? Math.max(0, agora - new Date(pedido.producao_desde).getTime()) / 1000 : 0;
  return Math.floor((pedido.producao_acumulado_s ?? 0) + aberto);
}

/** Minutos inteiros (19 min 59 s ainda são 19). */
export const minutosDe = (segundos) => Math.floor(Math.max(0, segundos) / 60);

/** 'neutro' | 'atencao' | 'atrasado' */
export function faixaDeTempo(segundos) {
  const minutos = minutosDe(segundos);
  if (minutos >= LIMITE_ATRASO_MIN) return 'atrasado';
  if (minutos >= LIMITE_ATENCAO_MIN) return 'atencao';
  return 'neutro';
}

/** O pedido ainda está sendo produzido (Novo ou Em preparo)? Só então o relógio corre e a cor vale. */
export const emProducao = (pedido) => pedido.status === 'novo' || pedido.status === 'em_preparo';
