// Configurações da loja: dados, funcionamento e horários, tipos de pedido, tema e atualização do painel.
// (Senhas e usuários: tela Usuários. Impressora, papel e comanda: tela Impressora.)
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { centavosDeTexto, textoDeCentavos, telefone as formatarTelefone } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { recipienteDaTela, mostrarErro } from './casca.js';

const conteudo = recipienteDaTela();   // o contêiner desta tela (criado pelo roteador)

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

// ---------- Taxa de entrega por distância (D98) ----------
const dinheiro = (centavos) => (centavos === null || centavos === undefined ? '' : textoDeCentavos(centavos));
const linhaFaixa = (f = { ate_km: '', taxa_centavos: null }) => `
  <div class="faixa-raio" data-faixa-raio>
    <label class="campo"><span class="campo__rotulo">Até (km)</span><input class="entrada" name="faixa_km" inputmode="decimal" value="${esc(String(f.ate_km).replace('.', ','))}" placeholder="Ex.: 3"></label>
    <label class="campo"><span class="campo__rotulo">Taxa (R$)</span><input class="entrada" name="faixa_taxa" inputmode="decimal" value="${dinheiro(f.taxa_centavos)}" placeholder="Ex.: 5,00"></label>
    <button type="button" class="botao botao--fantasma botao--icone" data-raio="tirar" aria-label="Tirar esta faixa">${icone('lixeira', 18)}</button>
  </div>`;

function raioHtml(r) {
  const temPonto = r.lat !== null && r.lng !== null;
  return `
    <section class="bloco" id="bloco-raio">
      <h2 class="bloco__titulo">Taxa de entrega pela distância</h2>
      <p class="bloco__nota">Cadastre faixas de distância da loja até o cliente (em linha reta, pelo ponto que ele marca no mapa ou manda pelo WhatsApp). Sem faixas, vale a taxa fixa acima.</p>
      <div class="local-loja">
        <p><strong>Local da loja:</strong> <span id="raio-ponto">${temPonto ? `marcado (${r.lat.toFixed(5)}, ${r.lng.toFixed(5)})` : 'ainda não marcado'}</span></p>
        <input type="hidden" name="raio_lat" value="${temPonto ? r.lat : ''}"><input type="hidden" name="raio_lng" value="${temPonto ? r.lng : ''}">
        <div class="impressora__acoes">
          <button type="button" class="botao botao--secundario botao--p" data-raio="aqui">${icone('pin', 16)}Usar a localização deste aparelho</button>
          <button type="button" class="botao botao--fantasma botao--p" data-raio="endereco">Achar pelo endereço da loja</button>
        </div>
        <p class="campo__ajuda">“Usar a localização deste aparelho” funciona melhor com o celular ou o computador dentro da loja.</p>
      </div>
      <div id="raio-faixas">${r.faixas.map(linhaFaixa).join('')}</div>
      <div class="impressora__acoes"><button type="button" class="botao botao--secundario botao--p" data-raio="mais">${icone('mais', 16)}Adicionar faixa</button></div>
      <fieldset class="campo" style="border: 0; padding: 0; margin: var(--e-3) 0 0;">
        <legend class="campo__rotulo">Mais longe que a última faixa</legend>
        <label class="escolha-simples"><input type="radio" name="raio_alem" value="nao" ${r.alem_centavos === null ? 'checked' : ''}><span>Não entregar</span></label>
        <label class="escolha-simples"><input type="radio" name="raio_alem" value="sim" ${r.alem_centavos !== null ? 'checked' : ''}><span>Entregar, com a taxa de</span></label>
        <input class="entrada" name="raio_alem_taxa" inputmode="decimal" style="max-width: 9rem;" value="${dinheiro(r.alem_centavos)}" placeholder="Ex.: 12,00" aria-label="Taxa de quem está mais longe (R$)">
      </fieldset>
      <span class="campo__erro" id="erro-entrega_raio" hidden></span>
    </section>`;
}

/** Lê as faixas do formulário. Faixa toda em branco é ignorada; meio preenchida é erro. */
function lerRaio(form) {
  const faixas = [];
  for (const linha of $$('[data-faixa-raio]', form)) {
    const km = linha.querySelector('[name="faixa_km"]').value.trim().replace(',', '.');
    const taxa = linha.querySelector('[name="faixa_taxa"]').value.trim();
    if (!km && !taxa) continue;
    const centavos = centavosDeTexto(taxa);
    if (!km || Number.isNaN(Number(km)) || centavos === null) return { erro: 'Preencha a distância e a taxa de cada faixa (ex.: até 3 km, R$ 5,00).' };
    faixas.push({ ate_km: Number(km), taxa_centavos: centavos });
  }
  const alem = form.raio_alem.value === 'sim' ? centavosDeTexto(form.raio_alem_taxa.value) : null;
  if (form.raio_alem.value === 'sim' && alem === null) return { erro: 'Informe a taxa de quem está mais longe, ou marque “Não entregar”.' };
  const lat = form.raio_lat.value === '' ? null : Number(form.raio_lat.value);
  const lng = form.raio_lng.value === '' ? null : Number(form.raio_lng.value);
  return { raio: { lat, lng, faixas, alem_centavos: alem } };
}

function marcarPonto(lat, lng) {
  const form = $('#form-config');
  form.raio_lat.value = lat;
  form.raio_lng.value = lng;
  $('#raio-ponto').textContent = `marcado (${lat.toFixed(5)}, ${lng.toFixed(5)})`;
  avisar('Local da loja marcado. Clique em Salvar para guardar.');
}

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
        ${campo('endereco_loja', 'Endereço do restaurante', c.endereco_loja, { extra: 'maxlength="120"', ajuda: 'Aparece na retirada e é o destino do botão "Como chegar ao restaurante" (Google Maps). Escreva como numa busca: rua, número, bairro e cidade.' })}
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
          ${liga('aceita_mesa', 'Mesa (o cliente pede no restaurante, só com o nome)', c.aceita_mesa)}
        </div>
        <p class="campo__ajuda">O pedido de mesa feito pelo cardápio cai no Salão, com o nome do cliente, para a atendente levar. Pra viagem continua sendo registrado pela atendente.</p>
        <span class="campo__erro" id="erro-aceita" hidden></span>
        <div class="bloco__grade">
          ${campo('taxa_entrega', 'Taxa de entrega (R$)', textoDeCentavos(c.taxa_entrega_centavos), { extra: 'inputmode="decimal"', ajuda: 'Use 0,00 para entrega grátis.' })}
          ${campo('area_entrega', 'Área de entrega', c.area_entrega, { extra: 'maxlength="120"', ajuda: 'Texto que o cliente lê ao escolher entrega.' })}
          ${campo('tempo_entrega_min', 'Tempo de entrega (min)', c.tempo_entrega_min, { extra: 'inputmode="numeric"' })}
          ${campo('tempo_retirada_min', 'Tempo para retirada (min)', c.tempo_retirada_min, { extra: 'inputmode="numeric"' })}
        </div>
      </section>

      ${raioHtml(c.entrega_raio)}

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
        <h2 class="bloco__titulo">Atualização do painel</h2>
        <div class="bloco__grade">
          ${campo('polling_segundos', 'Atualizar o painel a cada (s)', c.polling_segundos, { extra: 'inputmode="numeric"', ajuda: 'De 3 a 60 segundos.' })}
        </div>
        <p class="bloco__nota">${icone('impressora', 16)}<span>Impressora, papel e comanda ficam na tela <a href="/painel/impressora">Impressora</a>.</span></p>
      </section>

      <div class="barra-salvar">
        <span class="barra-salvar__nota" id="nota-salvar" role="status"></span>
        <button type="submit" class="botao botao--g">Salvar configurações</button>
      </div>
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
// Faixas de distância e local da loja (D98).
delegar(conteudo, 'click', '[data-raio]', async (e, botao) => {
  const acao = botao.dataset.raio;
  if (acao === 'mais') {
    $('#raio-faixas').insertAdjacentHTML('beforeend', linhaFaixa());
    $('#raio-faixas [data-faixa-raio]:last-child input')?.focus();
  } else if (acao === 'tirar') {
    botao.closest('[data-faixa-raio]').remove();
  } else if (acao === 'aqui') {
    if (!navigator.geolocation) { avisar('Este aparelho não informa a localização.', { tipo: 'erro' }); return; }
    botao.setAttribute('aria-busy', 'true');
    navigator.geolocation.getCurrentPosition(
      (p) => { botao.removeAttribute('aria-busy'); marcarPonto(p.coords.latitude, p.coords.longitude); },
      () => { botao.removeAttribute('aria-busy'); avisar('Não deu para pegar a localização. Permita o acesso ou use o endereço da loja.', { tipo: 'erro' }); },
      { enableHighAccuracy: true, timeout: 15000 },
    );
  } else if (acao === 'endereco') {
    const endereco = $('#form-config').endereco_loja.value.trim();
    if (!endereco) { avisar('Preencha o endereço da loja primeiro (com a cidade).', { tipo: 'erro' }); return; }
    botao.setAttribute('aria-busy', 'true');
    try {
      const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=br&q=${encodeURIComponent(endereco)}`, { headers: { Accept: 'application/json' } });
      const [achado] = r.ok ? await r.json() : [];
      if (achado) marcarPonto(Number(achado.lat), Number(achado.lon));
      else avisar('Não achamos esse endereço no mapa. Escreva com a cidade (ex.: Rua X, 100, Centro, Campinas) ou use a localização do aparelho.', { tipo: 'erro', duracao: 7000 });
    } catch {
      avisar('Sem conexão com o mapa agora. Tente de novo.', { tipo: 'erro' });
    } finally {
      botao.removeAttribute('aria-busy');
    }
  }
});

conteudo.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const botao = form.querySelector('button[type="submit"]');

  const taxa = centavosDeTexto(form.taxa_entrega.value);
  if (taxa === null) {
    mostrarErros(form, { taxa_entrega: 'Digite um valor, por exemplo 6,00.' });
    return;
  }
  const raio = lerRaio(form);
  if (raio.erro) {
    mostrarErros(form, { entrega_raio: raio.erro });
    return;
  }
  botao.setAttribute('aria-busy', 'true');
  try {
    config = await api('/api/admin/configuracoes', {
      metodo: 'PUT',
      corpo: {
        nome_loja: form.nome_loja.value, whatsapp_loja: form.whatsapp_loja.value, endereco_loja: form.endereco_loja.value,
        funcionamento: form.funcionamento.value, horarios,
        aceita_entrega: form.aceita_entrega.checked, aceita_retirada: form.aceita_retirada.checked, aceita_mesa: form.aceita_mesa.checked,
        taxa_entrega_centavos: taxa, area_entrega: form.area_entrega.value,
        tempo_entrega_min: form.tempo_entrega_min.value, tempo_retirada_min: form.tempo_retirada_min.value,
        tema: form.tema.value,
        polling_segundos: form.polling_segundos.value,
        entrega_raio: raio.raio,
      },
    });
    horarios = Array.from({ length: 7 }, (_, d) => (config.horarios[d] ?? []).map((faixa) => [...faixa]));
    temaSalvo = config.tema;
    const rolagem = window.scrollY;
    desenhar();
    window.scrollTo(0, rolagem);
    // O nome da loja aparece no menu lateral (e na barra de cima do celular): atualiza sem recarregar.
    $$('.lateral__marca-texto, .barra-topo__marca').forEach((el) => (el.textContent = config.nome_loja));
    $$('.lateral__marca-inicial').forEach((el) => (el.textContent = config.nome_loja.charAt(0).toUpperCase()));
    $('.lateral__marca')?.setAttribute('title', config.nome_loja);
    avisar('Configurações salvas');
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao') mostrarErros(form, erro.campos);
    else mostrarErro(erro);
  }
});

/** A tela abriu (a primeira vez ou de novo): busca as configurações e desenha. */
export async function montar() {
  await carregar();
}

/** Saiu da tela sem salvar: a prévia do tema não pode ficar valendo para o resto do painel. */
export function desmontar() {
  document.documentElement.dataset.theme = temaSalvo;
}
