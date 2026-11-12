// Confere que o cálculo do navegador (public/assets/js/shared/preco.js) dá o MESMO resultado do servidor.
// Os casos e o cardápio vêm de tests/casos-preco.json, gerado por tests/regras.php. Rodar depois dele:
//   node tests/preco.test.mjs
import { readFileSync } from 'node:fs';
import { calcularMontagem, precoMinimo } from '../public/assets/js/shared/preco.js';

const { cardapio, casos } = JSON.parse(readFileSync(new URL('./casos-preco.json', import.meta.url), 'utf8'));
let falhas = 0;
const conferir = (descricao, obtido, esperado) => {
  const ok = JSON.stringify(obtido) === JSON.stringify(esperado);
  if (!ok) falhas++;
  console.log(`  ${ok ? 'ok   ' : 'FALHA'} ${descricao}${ok ? '' : `\n        esperado: ${JSON.stringify(esperado)}\n        obtido:   ${JSON.stringify(obtido)}`}`);
};

console.log('\nPREÇO NO NAVEGADOR = PREÇO NO SERVIDOR');
for (const caso of casos) {
  const r = calcularMontagem(cardapio, caso.escolha);
  conferir(caso.descricao, [r.preco, r.extras.map((i) => i.nome)], [caso.preco, caso.extras]);
}

console.log('\n"A PARTIR DE"');
conferir('Monte seu prato: menor tamanho disponível', precoMinimo(cardapio), 2490);
const nhoque = cardapio.massas.find((m) => m.nome === 'Nhoque').id;
const queijos = cardapio.molhos.find((m) => m.nome === 'Quatro queijos').id;
conferir('Prato da casa com nhoque e quatro queijos: base + acréscimos', precoMinimo(cardapio, { massa_id: nhoque, molho_id: queijos }), 2490 + 400 + 500);

console.log(falhas === 0 ? '\nTodos os testes passaram.' : `\n${falhas} teste(s) falharam.`);
process.exit(falhas === 0 ? 0 : 1);
