// Guia de estilo: troca de tema ao vivo e demonstração da folha, do aviso e do contador.
import { $, delegar } from './shared/dom.js';
import { abrirFolha } from './shared/folha.js';
import { avisar } from './shared/avisos.js';

// Tema: basta trocar o atributo data-theme do <html>; todo o CSS reage sozinho.
delegar(document, 'change', 'input[name="tema"]', (e, entrada) => {
  document.documentElement.dataset.theme = entrada.value;
});

$('#g-abrir-folha').addEventListener('click', () => abrirFolha($('#g-folha')));
$('#g-aviso').addEventListener('click', () => avisar('Prato adicionado ao carrinho'));
$('#g-aviso-erro').addEventListener('click', () => avisar('Sem conexão. Confira a internet e tente de novo.', { tipo: 'erro' }));

delegar(document, 'click', '[data-passo]', (e, botao) => {
  const valor = botao.parentElement.querySelector('.quantidade__valor');
  valor.textContent = Math.max(1, Math.min(20, Number(valor.textContent) + Number(botao.dataset.passo)));
});
