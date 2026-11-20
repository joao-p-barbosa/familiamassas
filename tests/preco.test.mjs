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
const nhoque = cardapio.itens.find((i) => i.nome === 'Nhoque quatro queijos');
conferir('Prato da casa: o menor preço da tabela dele (D87)', precoMinimo(cardapio, nhoque), 3390);

console.log('\nPRATO DA CASA (PREÇO FIXO POR TAMANHO)');
const inteiro = cardapio.tamanhos.find((t) => t.nome === 'Inteiro').id;
const fixo = calcularMontagem(cardapio, { item_id: nhoque.id, tamanho_id: inteiro, ingrediente_ids: [999] });
conferir('Preço do Inteiro vem da tabela; ingrediente mandado pelo cliente é ignorado', [fixo.preco, fixo.extras.length, fixo.completa], [4890, 0, true]);
conferir('Tamanho sem preço na tabela não fecha o prato', calcularMontagem(cardapio, { item_id: nhoque.id, tamanho_id: 999 }).completa, false);

console.log(falhas === 0 ? '\nTodos os testes passaram.' : `\n${falhas} teste(s) falharam.`);
process.exit(falhas === 0 ? 0 : 1);
