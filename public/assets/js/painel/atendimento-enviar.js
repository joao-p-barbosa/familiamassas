// Etapa 4 do atendimento: enviar os produtos escolhidos. O pedido entra na coluna "Salão" do quadro de Pedidos.
//   * atendimento novo: abre a conta (nome e modalidade vêm do assistente) e registra o primeiro pedido;
//   * conta aberta: soma mais um pedido à mesma conta.
// Aqui NÃO há forma de pagamento: ela só é registrada quando a conta é fechada.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { estado, totaisDoCarrinho, esvaziarCarrinho, recarregarCardapio } from '../cardapio/estado.js';
import { rotuloDaModalidade } from '../shared/atendimento.js';
import { mostrarErro } from './casca.js';
import { navegar } from './roteador.js';
import { CHAVE_RASCUNHO } from './salao-novo.js';

const dialogo = $('#folha-enviar');
let contexto = null;   // { tipo: 'novo', rascunho } | { tipo: 'existente', atendimento }
let enviando = false;

/** A tela de produtos diz para quem são os produtos (muda a cada vez que ela abre). */
export const definirContexto = (novo) => (contexto = novo);

const dono = () => (contexto.tipo === 'novo' ? contexto.rascunho : contexto.atendimento);

export function abrirEnvio() {
  if (!contexto) return;
  const t = totaisDoCarrinho('salao');
  const d = dono();
  const nome = contexto.tipo === 'novo' ? d.nome : d.cliente_nome;
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="enviar-titulo">Enviar pedido</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <div class="folha__corpo enviar">
      <p class="enviar__quem"><strong>${esc(nome)}</strong> · ${esc(rotuloDaModalidade(d))}<br>
        <span>${contexto.tipo === 'novo' ? 'Atendimento novo: a conta abre agora.' : 'Soma-se ao que já foi pedido nesta conta.'}</span></p>
      <ul class="conta-linhas" aria-label="Produtos deste pedido">
        ${t.linhas.map((l) => `
          <li class="conta-linha">
            <span class="conta-linha__nome"><span class="numero">${l.quantidade}×</span> ${esc(l.nome)}${l.resumo ? `<small>${esc(l.resumo)}</small>` : ''}</span>
            <strong class="conta-linha__total numero">${reais(l.total)}</strong>
          </li>`).join('')}
      </ul>
      <dl class="totais"><div class="totais__total"><dt>${plural(t.quantidade, 'item', 'itens')}</dt><dd class="numero">${reais(t.subtotal)}</dd></div></dl>
      <p class="campo__ajuda">O pagamento é registrado só quando a conta for fechada.</p>
      <p class="campo__erro" id="erro-enviar" role="alert" hidden></p>
    </div>
    <footer class="folha__rodape">
      <button type="button" class="botao botao--g botao--bloco botao--com-valor" id="confirmar-envio"><span>Enviar pedido</span><span class="botao__valor">${reais(t.subtotal)}</span></button>
    </footer>`;
  enviando = false;
  abrirFolha(dialogo);
}

async function enviar() {
  if (enviando || !contexto) return;
  enviando = true;
  const botao = $('#confirmar-envio');
  botao.setAttribute('aria-busy', 'true');
  const t = totaisDoCarrinho('salao');
  const novo = contexto.tipo === 'novo';
  const itens = estado.carrinho.map(({ uid, ...linha }) => linha);
  try {
    const criado = novo
      ? await api('/api/admin/atendimentos', {
        metodo: 'POST',
        corpo: { cliente: { nome: contexto.rascunho.nome }, modalidade: contexto.rascunho.modalidade, mesa: contexto.rascunho.mesa, itens, total_esperado_centavos: t.subtotal },
      })
      : await api(`/api/admin/atendimentos/${contexto.atendimento.id}/pedidos`, { metodo: 'POST', corpo: { itens, total_esperado_centavos: t.subtotal } });
    const atendimentoId = novo ? criado.id : contexto.atendimento.id;
    const nome = novo ? contexto.rascunho.nome : contexto.atendimento.cliente_nome;
    esvaziarCarrinho();
    if (novo) {
      try { sessionStorage.removeItem(CHAVE_RASCUNHO); } catch { /* sem armazenamento da aba */ }
    }
    try { sessionStorage.setItem('fm_salao_destaque', String(atendimentoId)); } catch { /* sem armazenamento da aba */ }
    contexto = null;
    await fecharFolha(dialogo);
    avisar(novo ? `Atendimento de ${nome} aberto · pedido #${criado.numero} enviado` : `Pedido #${criado.numero} somado à conta de ${nome}`);
    navegar('/painel/pedidos');
  } catch (erro) {
    enviando = false;
    botao.removeAttribute('aria-busy');
    if (erro.status === 401) {
      mostrarErro(erro);
      return;
    }
    // O cardápio pode ter mudado (item esgotou, preço mudou): atualiza para a atendente ver o que mudou.
    if (['total_mudou', 'esgotado', 'item_removido', 'montagem_invalida'].includes(erro.codigo)) await recarregarCardapio().catch(() => {});
    const aviso = $('#erro-enviar');
    aviso.hidden = false;
    aviso.textContent = Object.keys(erro.campos ?? {}).length ? Object.values(erro.campos).join(' ') : erro.message;
    if (erro.codigo === 'atendimento_encerrado') {
      botao.replaceWith(Object.assign(document.createElement('a'), { className: 'botao botao--g botao--bloco', href: '/painel/pedidos', textContent: 'Voltar aos pedidos' }));
    }
  }
}

delegar(dialogo, 'click', '#confirmar-envio', enviar);
