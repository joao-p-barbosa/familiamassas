// Configurações da loja: dados, funcionamento e horários, tipos de pedido, tema, cozinha/impressão e senha.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { centavosDeTexto, textoDeCentavos, telefone as formatarTelefone } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { conteudo, mostrarErro } from './casca.js';
import { imprimirTeste } from './impressao.js';

const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
// Amostra de cada tema: fundo, acento e destaque (as mesmas cores dos arquivos em css/themes).
const TEMAS = [
  { id: 'italia', nome: 'Itália', cores: ['#f1f2f1', '#007a3a', '#c42632'] },
  { id: 'linho', nome: 'Linho', cores: ['#f1ece2', '#33291f', '#9c3b2a'] },
  { id: 'pedra', nome: 'Pedra', cores: ['#eceef0', '#25313d', '#a8323c'] },
  { id: 'grafite', nome: 'Grafite', cores: ['#151817', '#eef0ed', '#f0747c'] },
];

let config = null;
let horarios = [];     // por dia (0 = domingo): lista de [abre, fecha]
let temaSalvo = 'italia';

const campo = (nome, rotulo, valor, { ajuda = '', extra = '', tipo = 'text' } = {}) => `
  <label class="campo">
    <span class="campo__rotulo">${rotulo}</span>
    <input class="entrada" type="${tipo}" name="${nome}" value="${esc(valor ?? '')}" aria-describedby="erro-${nome}" ${extra}>
    ${ajuda ? `<span class="campo__ajuda">${ajuda}</span>` : ''}
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;
const liga = (nome, rotulo, ligado) => `
  <label class="interruptor"><input type="checkbox" name="${nome}" ${ligado ? 'checked' : ''}><span class="interruptor__trilho"></span><span>${rotulo}</span></label>`;

function horariosHtml() {
  return DIAS.map((dia, d) => `
    <div class="config__dia">
      <span class="config__dia-nome">${dia}</span>
      <div class="config__faixas">
        ${horarios[d].length === 0 ? '<span class="config__fechado">Fechado</span>' : horarios[d].map(([abre, fecha], f) => `
          <div class="config__faixa">
            <input class="entrada" type="time" value="${esc(abre)}" data-dia="${d}" data-faixa="${f}" data-ponta="0" aria-label="${dia}: abre às">
            <span>até</span>
            <input class="entrada" type="time" value="${esc(fecha)}" data-dia="${d}" data-faixa="${f}" data-ponta="1" aria-label="${dia}: fecha às">
            <button type="button" class="botao botao--fantasma botao--icone" data-tirar-faixa="${d},${f}" aria-label="Tirar este horário de ${dia}">${icone('lixeira', 18)}</button>
          </div>`).join('')}
        ${horarios[d].length < 3 ? `<button type="button" class="botao botao--fantasma botao--p" data-nova-faixa="${d}" style="justify-self: start;">${icone('mais', 16)}${horarios[d].length ? 'Outro horário' : 'Abrir neste dia'}</button>` : ''}
      </div>
    </div>`).join('');
}

function desenhar() {
  const c = config;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">Configurações</h1></div>
    <form class="config" id="form-config" novalidate>
      <section class="bloco">
        <h2 class="bloco__titulo">Loja</h2>
        <div class="bloco__grade">
          ${campo('nome_loja', 'Nome da loja', c.nome_loja, { extra: 'maxlength="40"', ajuda: 'Aparece no topo do cardápio e na comanda.' })}
          ${campo('whatsapp_loja', 'WhatsApp da loja', c.whatsapp_loja ? formatarTelefone(c.whatsapp_loja) : '', { tipo: 'tel', extra: 'inputmode="numeric"', ajuda: 'O cliente fala com este número pela página do pedido.' })}
        </div>
        ${campo('endereco_loja', 'Endereço para retirada', c.endereco_loja, { extra: 'maxlength="120"' })}
      </section>

      <section class="bloco">
        <h2 class="bloco__titulo">Funcionamento</h2>
        <p class="bloco__nota">${icone(c.estado_agora.aberta ? 'check' : 'relogio', 16)}<span>Agora: <strong>${esc(c.estado_agora.mensagem)}</strong></span></p>
        <fieldset class="segmentado" aria-label="Como a loja abre">
          ${[['auto', 'Seguir os horários'], ['aberta', 'Aberta agora'], ['fechada', 'Fechada agora']].map(([valor, rotulo]) => `
            <label class="segmentado__item"><input type="radio" name="funcionamento" value="${valor}" ${c.funcionamento === valor ? 'checked' : ''}><span>${rotulo}</span></label>`).join('')}
        </fieldset>
        <p class="campo__ajuda">“Aberta agora” e “Fechada agora” ignoram os horários abaixo até você voltar para “Seguir os horários”.</p>
        <div id="horarios">${horariosHtml()}</div>
        <span class="campo__erro" id="erro-horarios" hidden></span>
      </section>

      <section class="bloco">
        <h2 class="bloco__titulo">Pedidos</h2>
        <div style="display: grid;">
          ${liga('aceita_entrega', 'Entrega', c.aceita_entrega)}
          ${liga('aceita_retirada', 'Retirada na loja', c.aceita_retirada)}
          ${liga('aceita_local', 'Consumo no local (mesa)', c.aceita_local)}
        </div>
        <span class="campo__erro" id="erro-aceita" hidden></span>
        <div class="bloco__grade">
          ${campo('taxa_entrega', 'Taxa de entrega (R$)', textoDeCentavos(c.taxa_entrega_centavos), { extra: 'inputmode="decimal"', ajuda: 'Use 0,00 para entrega grátis.' })}
          ${campo('area_entrega', 'Área de entrega', c.area_entrega, { extra: 'maxlength="120"', ajuda: 'Texto que o cliente lê ao escolher entrega.' })}
          ${campo('tempo_entrega_min', 'Tempo de entrega (min)', c.tempo_entrega_min, { extra: 'inputmode="numeric"' })}
          ${campo('tempo_retirada_min', 'Tempo para retirada (min)', c.tempo_retirada_min, { extra: 'inputmode="numeric"' })}
        </div>
      </section>

      <section class="bloco">
        <h2 class="bloco__titulo">Aparência</h2>
        <fieldset class="temas">
          <legend class="so-leitor">Tema do cardápio e do painel</legend>
          ${TEMAS.map((t) => `
            <label class="tema">
              <input type="radio" name="tema" value="${t.id}" ${c.tema === t.id ? 'checked' : ''}>
              <span class="tema__amostra" aria-hidden="true">${t.cores.map((cor) => `<span style="background: ${cor}"></span>`).join('')}</span>
              <span>${t.nome}</span>
            </label>`).join('')}
        </fieldset>
        <p class="campo__ajuda">A tela já mostra o tema escolhido. Ele só vale para os clientes depois de salvar.</p>
      </section>

      <section class="bloco">
        <h2 class="bloco__titulo">Cozinha e impressão</h2>
        <div class="bloco__grade">
          <label class="campo">
            <span class="campo__rotulo">Largura do papel</span>
            <select class="entrada" name="comanda_largura_mm">
              <option value="80" ${c.comanda_largura_mm === 80 ? 'selected' : ''}>80 mm</option>
              <option value="58" ${c.comanda_largura_mm === 58 ? 'selected' : ''}>58 mm</option>
            </select>
          </label>
          ${campo('atraso_minutos', 'Pedido atrasa depois de (min)', c.atraso_minutos, { extra: 'inputmode="numeric"', ajuda: 'O cartão muda de cor no Kanban e na cozinha.' })}
          ${campo('polling_segundos', 'Atualizar o painel a cada (s)', c.polling_segundos, { extra: 'inputmode="numeric"', ajuda: 'De 3 a 60 segundos.' })}
        </div>
        ${liga('comanda_mostra_preco', 'Imprimir preços e pagamento na comanda', c.comanda_mostra_preco)}
        <div><button type="button" class="botao botao--secundario" id="comanda-teste">${icone('impressora', 18)}Imprimir comanda de teste</button></div>
        <div class="comanda-previa" id="comanda-previa" hidden></div>
      </section>

      <div class="barra-salvar">
        <span class="barra-salvar__nota" id="nota-salvar" role="status"></span>
        <button type="submit" class="botao botao--g">Salvar configurações</button>
      </div>
    </form>

    <form class="config" id="form-senha" novalidate style="margin-top: var(--e-12);">
      <section class="bloco">
        <h2 class="bloco__titulo">Minha senha</h2>
        <div class="bloco__grade">
          ${campo('atual', 'Senha atual', '', { tipo: 'password', extra: 'autocomplete="current-password"' })}
          ${campo('nova', 'Nova senha', '', { tipo: 'password', extra: 'autocomplete="new-password"', ajuda: 'Pelo menos 8 caracteres.' })}
        </div>
        <div><button type="submit" class="botao botao--secundario">Trocar senha</button></div>
      </section>
    </form>`;
}

function mostrarErros(form, erros) {
  for (const el of $$('.campo__erro', form)) {
    const nome = el.id.replace('erro-', '');
    // O servidor fala em "taxa_entrega_centavos"; o campo da tela chama "taxa_entrega".
    const mensagem = erros[nome] ?? erros[`${nome}_centavos`];
    el.hidden = !mensagem;
    el.textContent = mensagem ?? '';
    form.querySelector(`[name="${nome}"]`)?.setAttribute('aria-invalid', mensagem ? 'true' : 'false');
  }
  const primeiro = $$('.campo__erro', form).find((el) => !el.hidden);
  primeiro?.scrollIntoView({ block: 'center' });
  form.querySelector('[aria-invalid="true"]')?.focus();
}

async function carregar() {
  try {
    config = await api('/api/admin/configuracoes');
    horarios = Array.from({ length: 7 }, (_, d) => (config.horarios[d] ?? []).map((faixa) => [...faixa]));
    temaSalvo = config.tema;
    desenhar();
  } catch (erro) {
    mostrarErro(erro);
  }
}

// ---------- Eventos ----------
delegar(conteudo, 'click', '[data-nova-faixa]', (e, botao) => {
  const dia = Number(botao.dataset.novaFaixa);
  const ultima = horarios[dia].at(-1);
  horarios[dia].push(ultima ? [ultima[1] < '18:00' ? '18:00' : '22:00', '23:00'] : ['11:00', '23:00']);
  $('#horarios').innerHTML = horariosHtml();
});
delegar(conteudo, 'click', '[data-tirar-faixa]', (e, botao) => {
  const [dia, faixa] = botao.dataset.tirarFaixa.split(',').map(Number);
  horarios[dia].splice(faixa, 1);
  $('#horarios').innerHTML = horariosHtml();
});
delegar(conteudo, 'change', 'input[type="time"]', (e, entrada) => {
  horarios[Number(entrada.dataset.dia)][Number(entrada.dataset.faixa)][Number(entrada.dataset.ponta)] = entrada.value;
});
// Tema: prévia ao vivo, sem recarregar.
delegar(conteudo, 'change', 'input[name="tema"]', (e, entrada) => {
  document.documentElement.dataset.theme = entrada.value;
  $('#nota-salvar').textContent = entrada.value === temaSalvo ? '' : 'Tema em prévia. Salve para valer no cardápio.';
});
delegar(conteudo, 'focusout', 'input[name="taxa_entrega"]', (e, entrada) => {
  const valor = centavosDeTexto(entrada.value);
  if (valor !== null) entrada.value = textoDeCentavos(valor);
});
delegar(conteudo, 'input', 'input[name="whatsapp_loja"]', (e, entrada) => {
  entrada.value = formatarTelefone(entrada.value);
});
delegar(conteudo, 'click', '#comanda-teste', () => {
  const form = $('#form-config');
  const previa = $('#comanda-previa');
  previa.innerHTML = imprimirTeste(Number(form.comanda_largura_mm.value), form.comanda_mostra_preco.checked);
  previa.hidden = false;
  avisar('Comanda de teste enviada para a impressora padrão');
});

conteudo.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const botao = form.querySelector('button[type="submit"]');

  if (form.id === 'form-senha') {
    botao.setAttribute('aria-busy', 'true');
    try {
      await api('/api/admin/senha', { metodo: 'POST', corpo: { atual: form.atual.value, nova: form.nova.value } });
      form.reset();
      mostrarErros(form, {});
      avisar('Senha trocada');
    } catch (erro) {
      if (erro.codigo === 'validacao') mostrarErros(form, erro.campos);
      else mostrarErro(erro);
    }
    botao.removeAttribute('aria-busy');
    return;
  }

  const taxa = centavosDeTexto(form.taxa_entrega.value);
  if (taxa === null) {
    mostrarErros(form, { taxa_entrega: 'Digite um valor, por exemplo 6,00.' });
    return;
  }
  botao.setAttribute('aria-busy', 'true');
  try {
    config = await api('/api/admin/configuracoes', {
      metodo: 'PUT',
      corpo: {
        nome_loja: form.nome_loja.value, whatsapp_loja: form.whatsapp_loja.value, endereco_loja: form.endereco_loja.value,
        funcionamento: form.funcionamento.value, horarios,
        aceita_entrega: form.aceita_entrega.checked, aceita_retirada: form.aceita_retirada.checked, aceita_local: form.aceita_local.checked,
        taxa_entrega_centavos: taxa, area_entrega: form.area_entrega.value,
        tempo_entrega_min: form.tempo_entrega_min.value, tempo_retirada_min: form.tempo_retirada_min.value,
        tema: form.tema.value,
        comanda_largura_mm: Number(form.comanda_largura_mm.value), comanda_mostra_preco: form.comanda_mostra_preco.checked,
        atraso_minutos: form.atraso_minutos.value, polling_segundos: form.polling_segundos.value,
      },
    });
    horarios = Array.from({ length: 7 }, (_, d) => (config.horarios[d] ?? []).map((faixa) => [...faixa]));
    temaSalvo = config.tema;
    const rolagem = window.scrollY;
    desenhar();
    window.scrollTo(0, rolagem);
    document.querySelector('.painel-topo .marca').textContent = config.nome_loja;
    avisar('Configurações salvas');
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao') mostrarErros(form, erro.campos);
    else mostrarErro(erro);
  }
});

carregar();
