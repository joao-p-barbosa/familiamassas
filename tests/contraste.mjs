// Confere o contraste (WCAG) dos pares texto/fundo de cada tema.
// Rodar:  node tests/contraste.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const pasta = fileURLToPath(new URL('../public/assets/css/themes/', import.meta.url));
const temas = ['italia', 'linho', 'pedra', 'grafite'];

// [texto, fundo, mínimo, descrição]. 4.5 = texto normal; 3 = texto grande, ícones e bordas de campo.
const pares = [
  ['texto', 'fundo', 4.5, 'texto sobre o fundo'],
  ['texto', 'superficie', 4.5, 'texto sobre cartão'],
  ['texto', 'superficie-2', 4.5, 'texto sobre superfície 2'],
  ['texto-2', 'fundo', 4.5, 'texto secundário sobre o fundo'],
  ['texto-2', 'superficie', 4.5, 'texto secundário sobre cartão'],
  ['texto-2', 'superficie-2', 4.5, 'texto secundário sobre superfície 2'],
  ['acento-texto', 'acento', 4.5, 'texto do botão principal'],
  ['acento-texto', 'acento-hover', 4.5, 'texto do botão principal (hover)'],
  ['acento-tinta', 'fundo', 4.5, 'acento como texto sobre o fundo'],
  ['acento-tinta', 'superficie', 4.5, 'acento como texto sobre cartão'],
  ['acento-tinta', 'acento-suave', 4.5, 'acento sobre acento suave (selecionado)'],
  ['texto', 'acento-suave', 4.5, 'texto sobre acento suave'],
  ['texto-forte', 'superficie-forte', 4.5, 'texto sobre a barra escura'],
  ['destaque', 'fundo', 4.5, 'vermelho da marca sobre o fundo'],
  ['destaque', 'superficie', 4.5, 'vermelho da marca sobre cartão'],
  ['sucesso', 'sucesso-suave', 4.5, 'selo de sucesso'],
  ['sucesso', 'superficie', 4.5, 'sucesso como texto sobre cartão'],
  ['alerta', 'alerta-suave', 4.5, 'selo de alerta'],
  ['perigo', 'perigo-suave', 4.5, 'selo de perigo'],
  ['perigo', 'superficie', 4.5, 'mensagem de erro sobre cartão'],
  ['perigo', 'fundo', 4.5, 'mensagem de erro sobre o fundo'],
  ['info', 'info-suave', 4.5, 'selo de informação'],
  ['borda-forte', 'superficie', 3, 'borda de campo sobre cartão'],
  ['borda-forte', 'fundo', 3, 'borda de campo sobre o fundo'],
  ['foco', 'fundo', 3, 'anel de foco sobre o fundo'],
  ['foco', 'superficie', 3, 'anel de foco sobre cartão'],
];
const fixos = [['#ffffff', 'perigo-fundo', 4.5, 'texto do botão de perigo']];

const luminancia = (hex) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const contraste = (a, b) => {
  const [claro, escuro] = [luminancia(a), luminancia(b)].sort((x, y) => y - x);
  return (claro + 0.05) / (escuro + 0.05);
};

let falhas = 0;
for (const tema of temas) {
  const css = readFileSync(`${pasta}${tema}.css`, 'utf8');
  const cor = (nome) => {
    const achado = css.match(new RegExp(`--cor-${nome}:\\s*(#[0-9a-fA-F]{6})`));
    if (!achado) throw new Error(`${tema}: --cor-${nome} não é um hex de 6 dígitos`);
    return achado[1];
  };
  console.log(`\n${tema.toUpperCase()}`);
  for (const [texto, fundo, minimo, descricao] of [...pares, ...fixos]) {
    const corTexto = texto.startsWith('#') ? texto : cor(texto);
    const valor = contraste(corTexto, cor(fundo));
    const ok = valor >= minimo;
    if (!ok) falhas++;
    console.log(`  ${ok ? 'ok   ' : 'FALHA'} ${valor.toFixed(2).padStart(5)}:1 (mín. ${minimo})  ${descricao}`);
  }
}
console.log(falhas === 0 ? '\nTodos os pares passam.' : `\n${falhas} par(es) abaixo do mínimo.`);
process.exit(falhas === 0 ? 0 : 1);
