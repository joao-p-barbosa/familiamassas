// Detalhe de um item pronto (bebida, sobremesa): foto, descrição, observação e quantidade.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais } from '../shared/formato.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { adicionarAoCarrinho } from './estado.js';

const dialogo = $('#folha-item');
let atual = null; // { item, quantidade }

export function abrirItem(item) {
  atual = { item, quantidade: 1 };
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="item-titulo">${esc(item.nome)}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <div class="folha__corpo detalhe-item">
      ${item.imagem ? `<img class="detalhe-item__foto" src="${esc(item.imagem)}" alt="" width="640" height="360">` : ''}
      ${item.descricao ? `<p class="detalhe-item__descricao">${esc(item.descricao)}</p>` : ''}
      <p class="detalhe-item__preco numero">${reais(item.preco_centavos)}</p>
      <label class="campo">
        <span class="campo__rotulo">Observações <span class="campo__opcional">(opcional)</span></span>
        <textarea class="entrada" id="item-observacoes" maxlength="200" rows="2" placeholder="Ex.: sem gelo"></textarea>
      </label>
    </div>
    <footer class="folha__rodape detalhe-item__rodape">
      <div class="quantidade" role="group" aria-label="Quantidade">
        <button type="button" class="quantidade__botao" data-quantidade="-1" aria-label="Diminuir" disabled>${icone('menos', 18)}</button>
        <output class="quantidade__valor" id="item-quantidade">1</output>
        <button type="button" class="quantidade__botao" data-quantidade="1" aria-label="Aumentar">${icone('mais', 18)}</button>
      </div>
      <button type="button" class="botao botao--g botao--com-valor" id="item-adicionar"><span>Adicionar</span><span class="botao__valor" id="item-total">${reais(item.preco_centavos)}</span></button>
    </footer>`;
  abrirFolha(dialogo, { aoFechar: () => (atual = null) });
}

delegar(dialogo, 'click', '[data-quantidade]', (e, botao) => {
  if (!atual) return;
  atual.quantidade = Math.max(1, Math.min(20, atual.quantidade + Number(botao.dataset.quantidade)));
  $('#item-quantidade').textContent = atual.quantidade;
  $('#item-total').textContent = reais(atual.item.preco_centavos * atual.quantidade);
  $('[data-quantidade="-1"]', dialogo).disabled = atual.quantidade <= 1;
  $('[data-quantidade="1"]', dialogo).disabled = atual.quantidade >= 20;
});

delegar(dialogo, 'click', '#item-adicionar', () => {
  if (!atual) return;
  adicionarAoCarrinho({ tipo: 'simples', item_id: atual.item.id, quantidade: atual.quantidade, observacoes: $('#item-observacoes').value.trim() });
  const nome = atual.item.nome;
  fecharFolha(dialogo);
  avisar(`${nome} adicionado ao carrinho`);
});
