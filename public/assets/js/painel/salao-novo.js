// "Novo pedido salão" (botão do quadro de Pedidos): as duas primeiras etapas do atendimento.
//   1. Cliente: o nome (obrigatório). É ele que identifica o atendimento no quadro.
//   2. Modalidade: Mesa (com a identificação da mesa) ou Pra viagem. Definida ANTES de escolher os produtos.
// Depois, a atendente vai para a tela "Produtos do atendimento" (etapa 3) e envia o pedido (etapa 4), que entra na coluna "Salão" do quadro.
// Nada é gravado no servidor aqui: o atendimento só nasce quando o primeiro pedido é enviado.
import { $, esc, delegar, guardado } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { navegar } from './roteador.js';

export const CHAVE_RASCUNHO = 'fm_atendimento_novo';

const dialogo = document.getElementById('folha-novo');
const ETAPAS = ['Cliente', 'Modalidade', 'Produtos', 'Enviar'];
let passo = 1;
let dados = { nome: '', modalidade: '', mesa: '' };

/** Abre o assistente (sempre do começo). */
export function abrirNovoAtendimento() {
  passo = 1;
  dados = { nome: '', modalidade: '', mesa: '' };
  desenhar();
  abrirFolha(dialogo);
  $('[name="nome"]', dialogo)?.focus();
}

const trilha = () => `
  <div class="trilha trilha--etapas" aria-hidden="true">
    ${ETAPAS.map((_, i) => `<span class="trilha__passo" ${i < passo - 1 ? 'data-feita' : ''} ${i === passo - 1 ? 'data-atual' : ''}></span>`).join('')}
  </div>
  <p class="novo__etapa"><strong>Etapa ${passo} de ${ETAPAS.length}</strong> · ${ETAPAS[passo - 1]}</p>`;

function desenhar() {
  const primeiro = passo === 1;
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="novo-titulo">${primeiro ? 'Quem é o cliente?' : 'Mesa ou pra viagem?'}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar sem criar">${icone('fechar')}</button>
    </header>
    <form class="folha__corpo novo" id="form-novo" novalidate>
      ${trilha()}
      ${primeiro ? `
      <label class="campo">
        <span class="campo__rotulo">Nome do cliente</span>
        <input class="entrada" name="nome" value="${esc(dados.nome)}" maxlength="80" autocomplete="off" aria-describedby="ajuda-nome erro-nome">
        <span class="campo__ajuda" id="ajuda-nome">É o nome que aparece no cartão do pedido.</span>
        <span class="campo__erro" id="erro-nome" hidden></span>
      </label>` : `
      <fieldset class="grupo-opcoes grupo-opcoes--duas">
        <legend class="so-leitor">Modalidade do atendimento</legend>
        ${[['mesa', 'Mesa', 'talheres', 'O cliente come no salão.'], ['viagem', 'Pra viagem', 'sacola', 'O cliente leva embora.']].map(([valor, rotulo, figura, apoio]) => `
          <label class="opcao opcao--figura">
            <input class="opcao__entrada" type="radio" name="modalidade" value="${valor}" ${dados.modalidade === valor ? 'checked' : ''}>
            <span class="opcao__marca">${icone('check', 14)}</span>
            <span class="opcao__figura">${icone(figura, 26)}</span>
            <span class="opcao__corpo"><span class="opcao__titulo">${rotulo}</span><span class="opcao__descricao">${apoio}</span></span>
          </label>`).join('')}
      </fieldset>
      <span class="campo__erro" id="erro-modalidade" hidden></span>
      <label class="campo" id="campo-mesa" ${dados.modalidade === 'mesa' ? '' : 'hidden'}>
        <span class="campo__rotulo">Mesa</span>
        <input class="entrada" name="mesa" value="${esc(dados.mesa)}" maxlength="20" autocomplete="off" placeholder="Ex.: 5 ou varanda 2" aria-describedby="erro-mesa">
        <span class="campo__erro" id="erro-mesa" hidden></span>
      </label>`}
    </form>
    <footer class="folha__rodape folha__rodape--acoes">
      ${primeiro
        ? '<button type="button" class="botao botao--secundario" data-fechar>Cancelar</button>'
        : `<button type="button" class="botao botao--secundario" data-novo="voltar">${icone('seta-esq', 18)}Voltar</button>`}
      <button type="submit" form="form-novo" class="botao botao--g">${primeiro ? 'Continuar' : 'Escolher produtos'}${icone('seta-dir', 18)}</button>
    </footer>`;
}

function erro(campo, mensagem) {
  const el = $(`#erro-${campo}`, dialogo);
  el.hidden = false;
  el.textContent = mensagem;
  $(`[name="${campo}"]`, dialogo)?.setAttribute('aria-invalid', 'true');
  $(`[name="${campo}"]`, dialogo)?.focus();
}

function lerCampos() {
  const form = $('#form-novo', dialogo);
  if (passo === 1) dados.nome = form.nome.value.trim();
  else {
    dados.modalidade = form.modalidade.value || dados.modalidade;
    dados.mesa = form.mesa?.value.trim() ?? dados.mesa;
  }
}

dialogo.addEventListener('submit', async (e) => {
  e.preventDefault();
  lerCampos();
  if (passo === 1) {
    if (dados.nome.length < 2) return erro('nome', 'Informe o nome do cliente.');
    passo = 2;
    desenhar();
    return;
  }
  if (!dados.modalidade) return erro('modalidade', 'Escolha Mesa ou Pra viagem.');
  if (dados.modalidade === 'mesa' && !dados.mesa) return erro('mesa', 'Informe a mesa.');
  // Guarda o que foi escolhido para a próxima tela (só neste navegador, só nesta aba) e começa com o carrinho vazio.
  guardado.apagar('fm_carrinho_salao_novo_v1');
  try {
    sessionStorage.setItem(CHAVE_RASCUNHO, JSON.stringify({ nome: dados.nome, modalidade: dados.modalidade, mesa: dados.modalidade === 'mesa' ? dados.mesa : '' }));
  } catch {
    /* sem armazenamento da aba: a tela de produtos volta para cá */
  }
  await fecharFolha(dialogo);
  navegar('/painel/atendimento?novo=1');
});

delegar(dialogo, 'change', 'input[name="modalidade"]', (e, entrada) => {
  dados.modalidade = entrada.value;
  $('#campo-mesa', dialogo).hidden = entrada.value !== 'mesa';
  $('#erro-modalidade', dialogo).hidden = true;
  if (entrada.value === 'mesa') $('[name="mesa"]', dialogo).focus();
});
delegar(dialogo, 'click', '[data-novo="voltar"]', () => {
  lerCampos();
  passo = 1;
  desenhar();
  $('[name="nome"]', dialogo).focus();
});
