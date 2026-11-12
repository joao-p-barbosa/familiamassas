// Confere as regras de tempo do cartão no navegador (public/assets/js/shared/tempo.js):
// a soma dos períodos em Novo + Em preparo e as faixas neutro / atenção / atrasado.
// Os mesmos limites são conferidos no servidor em tests/regras.php. Rodar:   node tests/tempo.test.mjs
import { segundosDeProducao, faixaDeTempo, minutosDe, emProducao, LIMITE_ATENCAO_MIN, LIMITE_ATRASO_MIN } from '../public/assets/js/shared/tempo.js';

let falhas = 0;
const conferir = (descricao, obtido, esperado) => {
  const ok = JSON.stringify(obtido) === JSON.stringify(esperado);
  if (!ok) falhas++;
  console.log(`  ${ok ? 'ok   ' : 'FALHA'} ${descricao}${ok ? '' : `\n        esperado: ${JSON.stringify(esperado)}\n        obtido:   ${JSON.stringify(obtido)}`}`);
};

console.log('\nLIMITES DAS CORES (minutos : segundos)');
for (const [minutos, segundos, esperado] of [[0, 0, 'neutro'], [19, 0, 'neutro'], [19, 59, 'neutro'], [20, 0, 'atencao'], [29, 59, 'atencao'], [30, 0, 'atrasado'], [90, 0, 'atrasado']]) {
  conferir(`${minutos}:${String(segundos).padStart(2, '0')} → ${esperado}`, faixaDeTempo(minutos * 60 + segundos), esperado);
}
conferir('Os limites são 20 e 30 minutos', [LIMITE_ATENCAO_MIN, LIMITE_ATRASO_MIN], [20, 30]);
conferir('19 min 59 s ainda contam como 19 minutos', minutosDe(19 * 60 + 59), 19);

console.log('\nSOMA DOS PERÍODOS (o relógio não zera ao mudar de etapa)');
const agora = Date.parse('2027-01-15T12:00:00Z');
const antes = (segundos) => new Date(agora - segundos * 1000).toISOString();
conferir('Novo há 12 min, nada fechado: 12 min', minutosDe(segundosDeProducao({ producao_acumulado_s: 0, producao_desde: antes(12 * 60) }, agora)), 12);
conferir('Novo (10 min fechados) e Em preparo há 15 min: 25 min', minutosDe(segundosDeProducao({ producao_acumulado_s: 600, producao_desde: antes(15 * 60) }, agora)), 25);
conferir('Voltou de Pronto: 28 min fechados + 5 min abertos = 33 min', minutosDe(segundosDeProducao({ producao_acumulado_s: 28 * 60, producao_desde: antes(5 * 60) }, agora)), 33);
conferir('Pedido pronto: o relógio parou nos 45 min fechados', minutosDe(segundosDeProducao({ producao_acumulado_s: 45 * 60, producao_desde: null }, agora + 3_600_000)), 45);
conferir('Passa o tempo e a cor muda sozinha: 19:58 → 20:00 vira atenção', [
  faixaDeTempo(segundosDeProducao({ producao_acumulado_s: 0, producao_desde: antes(19 * 60 + 58) }, agora)),
  faixaDeTempo(segundosDeProducao({ producao_acumulado_s: 0, producao_desde: antes(19 * 60 + 58) }, agora + 2000)),
], ['neutro', 'atencao']);
conferir('Só Novo e Em preparo contam como produção', ['novo', 'em_preparo', 'pronto', 'saiu_entrega', 'aguardando_retirada'].map((status) => emProducao({ status })), [true, true, false, false, false]);

console.log(falhas === 0 ? '\nTodos os testes passaram.' : `\n${falhas} teste(s) falharam.`);
process.exit(falhas === 0 ? 0 : 1);
