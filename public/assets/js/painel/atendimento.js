// Produtos do atendimento (etapa 3 do fluxo da atendente): escolher os produtos de um atendimento novo ou somar mais
// produtos a uma conta aberta. Usa o MESMO cardápio, montador, carrinho e preço da página do cliente (cardapio/vitrine.js):
// uma só regra de montagem e de preço. Cada atendimento tem o seu carrinho.
//
//   /painel/atendimento?novo=1   atendimento novo: nome e modalidade vêm do assistente "Novo pedido salão" (guardados na aba);
//   /painel/atendimento?id=7     mais produtos para a conta aberta do atendimento 7.
import { esc } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { MODALIDADES, rotuloDaModalidade } from '../shared/atendimento.js';
import { recipienteDaTela, mostrarErro } from './casca.js';
import { navegar } from './roteador.js';
import { CHAVE_RASCUNHO } from './salao-novo.js';
import { definirContexto, abrirEnvio } from './atendimento-enviar.js';

const raiz = recipienteDaTela();   // o contêiner desta tela (criado pelo roteador)
let vitrineLigada = false;
let contextoAnterior = null;

function lerRascunho() {
  try {
    const bruto = JSON.parse(sessionStorage.getItem(CHAVE_RASCUNHO) ?? 'null');
    return bruto?.nome && bruto?.modalidade ? bruto : null;
  } catch {
    return null;
  }
}

function topoHtml(contexto) {
  const novo = contexto.tipo === 'novo';
  const d = novo ? contexto.rascunho : contexto.atendimento;
  const nome = novo ? d.nome : d.cliente_nome;
  const ETAPAS = ['Cliente', 'Modalidade', 'Produtos', 'Enviar'];
  return `
    <a class="botao botao--fantasma atendimento__voltar" href="/painel/pedidos">${icone('seta-esq', 18)}Voltar aos pedidos</a>
    <div class="cabecalho-tela">
      <div>
        <h1 class="cabecalho-tela__titulo">${novo ? `Pedido de ${esc(nome)}` : `Mais produtos para ${esc(nome)}`}</h1>
        <p class="cabecalho-tela__apoio">
          <span class="selo">${icone(MODALIDADES[d.modalidade].icone, 14)}${esc(rotuloDaModalidade(d))}</span>
          ${novo ? 'Atendimento novo.' : `Conta aberta · ${plural(d.pedidos_ativos, 'pedido', 'pedidos')} · ${reais(d.total_centavos)} até agora.`}
        </p>
      </div>
    </div>
    ${novo ? `
    <div class="atendimento__etapas">
      <div class="trilha trilha--etapas" aria-hidden="true">${ETAPAS.map((_, i) => `<span class="trilha__passo" ${i < 2 ? 'data-feita' : ''} ${i === 2 ? 'data-atual' : ''}></span>`).join('')}</div>
      <p class="novo__etapa"><strong>Etapa 3 de 4</strong> · Produtos. Depois você envia o pedido.</p>
    </div>` : ''}`;
}

export async function montar({ consulta }) {
  const id = Number(consulta.get('id')) || 0;
  let contexto;
  if (id > 0) {
    let atendimento;
    try {
      atendimento = await api(`/api/admin/atendimentos/${id}`);
    } catch (erro) {
      mostrarErro(erro);
      navegar('/painel/pedidos', { substituir: true });
      return;
    }
    if (atendimento.status !== 'aberto') {
      avisar(`A conta de ${atendimento.cliente_nome} já foi fechada: não dá para somar produtos.`, { tipo: 'erro' });
      navegar('/painel/pedidos', { substituir: true });
      return;
    }
    contexto = { tipo: 'existente', atendimento, chave: String(id) };
  } else {
    const rascunho = lerRascunho();
    if (!rascunho) {
      navegar('/painel/pedidos?novo=1', { substituir: true });   // não há atendimento em andamento: recomeça pelo nome
      return;
    }
    contexto = { tipo: 'novo', rascunho, chave: 'novo' };
  }

  // A estrutura da tela é desenhada uma vez; o topo muda a cada atendimento.
  if (!raiz.querySelector('#visao-cardapio')) {
    raiz.innerHTML = `
      <div class="atendimento" id="atendimento-topo"></div>
      <div class="salao__grade" id="visao-cardapio">
        <div class="salao__principal">
          <nav class="salao__nav" id="categorias" aria-label="Categorias"></nav>
          <div class="salao__itens"><div id="lista-cardapio" aria-busy="true"></div></div>
        </div>
        <aside class="salao__carrinho" aria-label="Produtos deste pedido"><div class="painel-carrinho" id="carrinho-lateral"></div></aside>
      </div>
      <div class="barra-carrinho" id="barra-carrinho" hidden></div>`;
  }
  document.getElementById('atendimento-topo').innerHTML = topoHtml(contexto);
  definirContexto(contexto);

  // Os módulos do cardápio procuram estes elementos ao carregar: por isso só entram depois de a tela estar desenhada.
  const [{ iniciarVitrine }, { definirAoFecharPedido }, { usarCarrinho }, { limparRascunho }] = await Promise.all([
    import('../cardapio/vitrine.js'),
    import('../cardapio/carrinho.js'),
    import('../cardapio/estado.js'),
    import('../cardapio/montador.js'),
  ]);
  if (contextoAnterior !== null && contextoAnterior !== contexto.chave) limparRascunho();   // o prato pela metade era de outra conta
  contextoAnterior = contexto.chave;
  usarCarrinho('salao', contexto.chave);
  if (!vitrineLigada) {
    definirAoFecharPedido(abrirEnvio);
    vitrineLigada = true;
  }
  await iniciarVitrine({ comLoja: false });
}
