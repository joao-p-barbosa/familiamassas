// Barra de ferramentas do Kanban: situação da conexão, som e estação de impressão.
import { $ } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { hora } from '../shared/formato.js';
import { avisar } from '../shared/avisos.js';
import { somLigado, alternarSom } from './som.js';
import { estacao, definirEstacaoAtiva } from './impressao.js';
import { ajustes, ehAdmin } from './casca.js';

export function ferramentasHtml() {
  return `
    <div class="ferramentas">
      <span class="conexao" id="conexao" role="status"></span>
      ${ehAdmin ? `<button type="button" class="botao botao--secundario botao--p" id="botao-som" aria-pressed="${somLigado()}">
        ${icone(somLigado() ? 'som' : 'som-mudo', 18)}<span>Som de pedido novo</span>
      </button>` : ''}
      ${ajustes.impressao_modo === 'agente'
        ? `<span class="selo ferramentas__estacao">${icone('impressora', 14)}Comandas pelo computador da loja</span>`
        : `<label class="interruptor ferramentas__estacao">
        <input type="checkbox" id="estacao-ativa" ${estacao.ativa ? 'checked' : ''}>
        <span class="interruptor__trilho"></span>
        <span>Imprimir comandas neste aparelho</span>
      </label>`}
    </div>`;
}

/** @param {{ conectado: boolean, ultimaSincronia: Date|null }} fonte  @param {() => void} aoLigarEstacao */
export function ligarFerramentas(fonte, aoLigarEstacao) {
  $('#botao-som')?.addEventListener('click', (e) => {
    const ligado = alternarSom();
    e.currentTarget.setAttribute('aria-pressed', String(ligado));
    e.currentTarget.firstElementChild.outerHTML = icone(ligado ? 'som' : 'som-mudo', 18);
    avisar(ligado ? 'Som ligado: você ouviu o toque de pedido novo' : 'Som desligado');
  });
  $('#estacao-ativa')?.addEventListener('change', (e) => {
    definirEstacaoAtiva(e.currentTarget.checked);
    avisar(e.currentTarget.checked ? 'Este aparelho vai imprimir as comandas dos pedidos novos' : 'Este aparelho parou de imprimir comandas');
    if (e.currentTarget.checked) aoLigarEstacao();
  });
  atualizarConexao(fonte);
}

export function atualizarConexao(fonte) {
  const el = $('#conexao');
  if (!el) return;
  el.dataset.estado = fonte.conectado ? 'ok' : 'fora';
  el.innerHTML = fonte.conectado
    ? `<span class="conexao__ponto"></span><span>Atualizado às ${fonte.ultimaSincronia ? hora(fonte.ultimaSincronia.toISOString()) : '--:--'}</span>`
    : `${icone('alerta', 16)}<span>Sem conexão. Tentando de novo…</span>`;
}
