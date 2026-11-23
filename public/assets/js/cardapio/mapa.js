// Mapa para marcar o local da entrega, no estilo dos apps de entrega: o pino fica no centro e a pessoa arrasta o mapa
// (ou toca no ponto exato) até o lugar certo. Ao abrir, pede a localização do aparelho para começar perto dela.
//
// O mapa NUNCA é obrigatório: sem permissão, sem internet ou sem endereço encontrado, a pessoa volta ao formulário e
// digita o endereço como antes. Tudo que o mapa preenche continua editável.
//
// Terceiros usados (sem chave de acesso no navegador):
//   * imagens do mapa: tile.openstreetmap.org (Ruas) e server.arcgisonline.com (Satélite, da Esri, com os nomes das ruas por cima);
//   * "qual é o endereço deste ponto": nominatim.openstreetmap.org (uma consulta quando a pessoa para de mexer o mapa);
//   * busca de endereço (D119): passa pelo nosso servidor (/api/endereco/...), que usa o Google Places quando há chave
//     configurada, ou o Photon (OpenStreetMap). Serve à atendente no pedido externo, que não está no local da entrega.
// A biblioteca Leaflet fica no próprio site (assets/vendor/leaflet) e só é carregada quando o mapa é aberto.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';

const dialogo = $('#folha-mapa');
const CENTRO_PADRAO = [-23.5505, -46.6333]; // ponto de partida quando não há localização (centro de São Paulo)
const ZOOM_PADRAO = 12;
const ZOOM_DO_PONTO = 17;

const PINO = `
  <svg class="mapa__pino-desenho" width="40" height="52" viewBox="0 0 36 46" aria-hidden="true" focusable="false">
    <path class="mapa__pino-corpo" d="M18 45S3 30 3 17a15 15 0 0 1 30 0c0 13-15 28-15 28Z"/>
    <circle class="mapa__pino-miolo" cx="18" cy="17" r="6"/>
  </svg>`;

let leaflet = null;      // Promise que entrega a biblioteca (carregada uma vez)
let sessao = null;       // estado do mapa aberto

/** A biblioteca Leaflet (carregada uma vez). Também usada pelo mapa do raio de entrega no painel (D105). */
export function carregarLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  leaflet ??= new Promise((resolver, rejeitar) => {
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = '/assets/vendor/leaflet/leaflet.css';
    const js = document.createElement('script');
    js.src = '/assets/vendor/leaflet/leaflet.js';
    js.onload = () => resolver(window.L);
    js.onerror = () => {
      leaflet = null; // deixa tentar de novo na próxima vez
      rejeitar(new Error('leaflet'));
    };
    document.head.append(css, js);
  });
  return leaflet;
}

const OSM = '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';
const ESRI = 'Imagens © <a href="https://www.esri.com" target="_blank" rel="noopener">Esri</a>, Maxar, Earthstar Geographics';
const CHAVE_CAMADA = 'fm_mapa_camada';

/**
 * As imagens do mapa com o botão Ruas | Satélite (D119). A escolha fica guardada no aparelho.
 * Também usada pelo mapa do raio de entrega no painel. Devolve a camada de ruas (para avisar quando as imagens não carregam).
 */
export function camadasDoMapa(L, map) {
  const ruas = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { referrerPolicy: 'origin', maxZoom: 19, attribution: OSM });
  const esri = (servico, opcoes = {}) => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${servico}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, maxNativeZoom: 18, ...opcoes });
  const satelite = L.layerGroup([
    esri('World_Imagery', { attribution: ESRI }),
    esri('Reference/World_Transportation'),
    esri('Reference/World_Boundaries_and_Places'),
  ]);
  let atual = 'ruas';
  try { atual = localStorage.getItem(CHAVE_CAMADA) === 'satelite' ? 'satelite' : 'ruas'; } catch { /* sem armazenamento: começa em Ruas */ }
  (atual === 'satelite' ? satelite : ruas).addTo(map);

  const Botao = L.Control.extend({
    options: { position: 'topleft' },
    onAdd() {
      const caixa = L.DomUtil.create('div', 'mapa__camadas');
      caixa.setAttribute('role', 'group');
      caixa.setAttribute('aria-label', 'Tipo de mapa');
      caixa.innerHTML = [['ruas', 'Ruas'], ['satelite', 'Satélite']]
        .map(([id, nome]) => `<button type="button" data-camada="${id}" aria-pressed="${id === atual}">${nome}</button>`).join('');
      L.DomEvent.disableClickPropagation(caixa);
      caixa.addEventListener('click', (e) => {
        const botao = e.target.closest('[data-camada]');
        if (!botao || botao.dataset.camada === atual) return;
        map.removeLayer(atual === 'satelite' ? satelite : ruas);
        atual = botao.dataset.camada;
        (atual === 'satelite' ? satelite : ruas).addTo(map);
        caixa.querySelectorAll('[data-camada]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.camada === atual)));
        try { localStorage.setItem(CHAVE_CAMADA, atual); } catch { /* sem armazenamento */ }
      });
      return caixa;
    },
  });
  new Botao().addTo(map);
  return ruas;
}

/** "Rua das Flores, 120" / "Rua das Flores" / "" */
const ruaComNumero = (e) => [e.rua, e.numero].filter(Boolean).join(', ');

async function descobrirEndereco(lat, lng, sinal) {
  const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=pt-BR&lat=${lat}&lon=${lng}`;
  const resposta = await fetch(url, { signal: sinal, referrerPolicy: 'origin', headers: { Accept: 'application/json' } });
  if (!resposta.ok) throw new Error('geocodificacao');
  const a = (await resposta.json()).address ?? {};
  return {
    rua: a.road ?? a.pedestrian ?? a.footway ?? a.path ?? a.residential ?? '',
    numero: a.house_number ?? '',
    bairro: a.suburb ?? a.neighbourhood ?? a.quarter ?? a.city_district ?? a.village ?? '',
  };
}

// ---------- Abrir ----------
/**
 * @param {{ inicial?: { lat: number, lng: number } | null, loja?: boolean }} opcoes  inicial: último ponto marcado (o mapa abre nele);
 *   loja: o dono marcando o local do restaurante no painel (D99), sem a opção de digitar o endereço.
 * @returns {Promise<{ lat: number, lng: number, rua: string, numero: string, bairro: string } | null>}
 *   o local confirmado, ou null se a pessoa preferiu digitar o endereço (ou fechou).
 */
export function escolherNoMapa({ inicial = null, enderecoDaLoja = '', loja = false } = {}) {
  return new Promise((resolver) => {
    sessao = {
      resolver, resultado: null, map: null, endereco: null, buscando: null, pontoEscolhido: false, temporizador: null, enderecoDaLoja, loja,
      escolhido: null,      // D119: o endereço da busca (com número); vale enquanto o pino estiver nele
      sessaoBusca: novaSessaoDeBusca(), digitando: null, consulta: null,
    };
    const ondeTexto = loja ? 'do restaurante' : 'da entrega';
    dialogo.innerHTML = `
      <header class="folha__topo">
        <h2 class="folha__titulo" id="mapa-titulo">${loja ? 'Onde fica o restaurante?' : 'Onde entregamos?'}</h2>
        <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar o mapa">${icone('fechar')}</button>
      </header>
      <div class="mapa__busca" role="search">
        <label class="so-leitor" for="mapa-busca">Buscar o endereço</label>
        <span class="mapa__busca-icone" aria-hidden="true">${icone('busca', 18)}</span>
        <input class="entrada" id="mapa-busca" type="search" placeholder="Buscar o endereço (rua e número)" autocomplete="off"
          role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mapa-sugestoes">
        <ul class="mapa__sugestoes" id="mapa-sugestoes" role="listbox" aria-label="Endereços encontrados" hidden></ul>
      </div>
      <div class="mapa" id="mapa">
        <div class="mapa__tela" id="mapa-tela" aria-label="Mapa para marcar o local ${ondeTexto}. Setas movem o mapa; mais e menos aproximam ou afastam."></div>
        <div class="mapa__pino" aria-hidden="true">${PINO}<span class="mapa__sombra"></span></div>
        <button type="button" class="botao botao--secundario botao--icone mapa__localizar" id="mapa-localizar" aria-label="Usar minha localização">${icone('alvo', 22)}</button>
        <p class="mapa__aviso" id="mapa-aviso" role="status" hidden></p>
      </div>
      <footer class="folha__rodape mapa__rodape">
        <div class="mapa__endereco" id="mapa-endereco" aria-live="polite"></div>
        <div class="mapa__acoes">
          <button type="button" class="botao botao--secundario" data-acao="digitar">${loja ? 'Cancelar' : 'Digitar o endereço'}</button>
          <button type="button" class="botao" id="mapa-confirmar" data-acao="confirmar-local" disabled>Confirmar local</button>
        </div>
      </footer>`;
    escrever();
    abrirFolha(dialogo, { aoFechar: encerrar });
    iniciarMapa(inicial);
  });
}

async function iniciarMapa(inicial) {
  const s = sessao;
  let L;
  try {
    L = await carregarLeaflet();
  } catch {
    if (sessao === s) avisar(s.loja ? 'Não conseguimos carregar o mapa agora. Tente de novo daqui a pouco.' : 'Não conseguimos carregar o mapa agora. Digite o endereço: o pedido segue normalmente.', true);
    return;
  }
  if (sessao !== s) return; // fechou enquanto carregava

  s.map = L.map('mapa-tela', { center: inicial ? [inicial.lat, inicial.lng] : CENTRO_PADRAO, zoom: inicial ? ZOOM_DO_PONTO : ZOOM_PADRAO, zoomControl: false });
  L.control.zoom({ position: 'topright', zoomInTitle: 'Aproximar', zoomOutTitle: 'Afastar' }).addTo(s.map);
  s.map.attributionControl.setPrefix(false);
  // O OpenStreetMap exige saber de que site vem o pedido (política de uso dos tiles). O site todo manda
  // Referrer-Policy "same-origin", então só nas imagens de Ruas informamos a origem (endereço do site, sem o caminho).
  const imagens = camadasDoMapa(L, s.map);
  imagens.once('tileerror', () => avisar(s.loja ? 'As imagens do mapa não carregaram. Confira a internet.' : 'As imagens do mapa não carregaram. Confira a internet ou digite o endereço.', true));

  const mapa = $('#mapa');
  s.map.on('movestart', () => mapa.setAttribute('data-movendo', ''));
  s.map.on('moveend', () => {
    mapa.removeAttribute('data-movendo');
    if (s.pontoEscolhido) agendarBusca();
  });
  // A pessoa mexeu no mapa de propósito: a partir daqui o centro é "o local".
  const escolheu = () => (s.pontoEscolhido = true);
  s.map.on('dragend', escolheu);
  s.map.on('click', (e) => {
    escolheu();
    s.map.panTo(e.latlng);
  });
  $('#mapa-tela').addEventListener('keydown', escolheu);
  $('#mapa-tela').addEventListener('wheel', escolheu, { passive: true });

  // O diálogo acabou de abrir (e anima): o mapa precisa medir o tamanho final.
  setTimeout(() => sessao === s && s.map?.invalidateSize(), 380);

  if (inicial) {
    s.pontoEscolhido = true;
    agendarBusca();
  } else {
    pedirLocalizacao();
  }
}

// ---------- Localização do aparelho ----------
/** Sem localização do aparelho, o mapa abre perto do restaurante (pelo endereço cadastrado), que é onde o cliente costuma estar. */
async function irParaLoja() {
  const s = sessao;
  if (!s?.map || !s.enderecoDaLoja) return;
  try {
    const resposta = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=br&q=${encodeURIComponent(s.enderecoDaLoja)}`,
      { referrerPolicy: 'origin', headers: { Accept: 'application/json' } });
    const [achou] = await resposta.json();
    if (achou && sessao === s && !s.pontoEscolhido) s.map.setView([Number(achou.lat), Number(achou.lon)], 15);
  } catch {
    /* fica no ponto de partida padrão */
  }
}

const arraste = () => (sessao?.loja ? 'Arraste o mapa até o restaurante.' : 'Arraste o mapa até o local da entrega ou digite o endereço.');

function pedirLocalizacao() {
  const s = sessao;
  if (!s?.map) return;
  if (!('geolocation' in navigator) || !window.isSecureContext) {
    avisar(`A localização do aparelho só funciona em conexão segura (https). ${arraste()}`);
    irParaLoja();
    return;
  }
  avisar('Procurando você… se o navegador perguntar, toque em Permitir.');
  navigator.geolocation.getCurrentPosition(
    (posicao) => {
      if (sessao !== s) return;
      avisar('');
      s.pontoEscolhido = true;
      s.map.setView([posicao.coords.latitude, posicao.coords.longitude], ZOOM_DO_PONTO);
    },
    (erro) => {
      if (sessao !== s) return;
      const motivo = {
        1: 'A localização está bloqueada neste aparelho.',
        2: 'Não conseguimos descobrir onde você está agora.',
        3: 'Demorou demais para achar sua localização.',
      }[erro.code] ?? 'Não conseguimos usar sua localização.';
      avisar(`${motivo} ${arraste()}`);
      irParaLoja();
    },
    { enableHighAccuracy: true, timeout: 12_000, maximumAge: 30_000 },
  );
}

// ---------- Endereço do ponto ----------
function agendarBusca() {
  const s = sessao;
  clearTimeout(s.temporizador);
  s.buscando?.abort();
  s.endereco = null;
  s.carregando = true;
  escrever();
  s.temporizador = setTimeout(async () => {
    const { lat, lng } = s.map.getCenter();
    // D119: o pino ainda está no endereço escolhido na busca: vale o dele (o mapa não sabe o número da casa).
    if (s.escolhido && s.map.distance([lat, lng], [s.escolhido.lat, s.escolhido.lng]) < 25) {
      s.endereco = { rua: s.escolhido.rua, numero: s.escolhido.numero, bairro: s.escolhido.bairro };
      s.falhou = false;
      s.carregando = false;
      escrever();
      return;
    }
    s.escolhido = null;
    const controle = new AbortController();
    s.buscando = controle;
    const limite = setTimeout(() => controle.abort(), 8000);
    try {
      s.endereco = await descobrirEndereco(lat, lng, controle.signal);
      s.falhou = false;
    } catch (erro) {
      if (erro.name === 'AbortError' && s.buscando !== controle) return; // veio uma busca mais nova
      s.endereco = null;
      s.falhou = true;
    } finally {
      clearTimeout(limite);
    }
    if (sessao !== s || s.buscando !== controle) return;
    s.carregando = false;
    escrever();
  }, 550);
}

function escrever() {
  const s = sessao;
  const caixa = $('#mapa-endereco');
  const confirmar = $('#mapa-confirmar');
  if (!caixa || !s) return;
  let html;
  if (!s.pontoEscolhido) {
    html = `<p class="mapa__dica">Arraste o mapa até o local exato ${s.loja ? 'do restaurante' : 'da entrega'}, ou toque nele.</p>`;
  } else if (s.carregando) {
    html = '<p class="mapa__dica" aria-busy="true">Buscando o endereço…</p>';
  } else if (s.endereco && (s.endereco.rua || s.endereco.bairro)) {
    html = `<p class="mapa__rua">${icone('pin', 18)}<span>${esc(ruaComNumero(s.endereco) || s.endereco.bairro)}</span></p>
            ${s.endereco.rua && s.endereco.bairro ? `<p class="mapa__bairro">${esc(s.endereco.bairro)}</p>` : ''}
            ${s.endereco.numero || s.loja ? '' : '<p class="mapa__dica">O mapa não sabe o número: você completa na tela seguinte.</p>'}`;
  } else {
    html = `<p class="mapa__dica">${s.falhou ? 'Não conseguimos achar o nome da rua agora.' : 'Não achamos o nome da rua neste ponto.'} ${s.loja ? 'O local vale assim mesmo: confirme se o pino está no restaurante.' : 'Confirme o local e digite o endereço na tela seguinte.'}</p>`;
  }
  caixa.innerHTML = html;
  confirmar.disabled = !s.pontoEscolhido || Boolean(s.carregando);
}

function avisar(texto, erro = false) {
  const el = $('#mapa-aviso');
  if (!el) return;
  el.hidden = texto === '';
  el.textContent = texto;
  el.toggleAttribute('data-erro', erro);
}

// ---------- Fechar ----------
function encerrar() {
  const s = sessao;
  if (!s) return;
  sessao = null;
  clearTimeout(s.temporizador);
  clearTimeout(s.digitando);
  s.buscando?.abort();
  s.consulta?.abort();
  s.map?.remove();
  s.resolver(s.resultado);
}

// ---------- Busca de endereço (D119) ----------
function novaSessaoDeBusca() {
  return globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

function mostrarSugestoes(lista, mensagem = '') {
  const ul = $('#mapa-sugestoes');
  const entrada = $('#mapa-busca');
  if (!ul || !entrada) return;
  ul.innerHTML = lista.length
    ? lista.map((x, i) => `
        <li role="option" id="mapa-sugestao-${i}" aria-selected="false">
          <button type="button" class="mapa__sugestao" data-sugestao="${esc(x.id)}" tabindex="-1">
            ${icone('pin', 16)}<span><strong>${esc(x.principal)}</strong>${x.secundario ? `<small>${esc(x.secundario)}</small>` : ''}</span>
          </button>
        </li>`).join('')
    : mensagem ? `<li class="mapa__sugestoes-vazio">${esc(mensagem)}</li>` : '';
  ul.hidden = !lista.length && !mensagem;
  entrada.setAttribute('aria-expanded', String(!ul.hidden));
}

async function buscarSugestoes(texto) {
  const s = sessao;
  if (!s) return;
  s.consulta?.abort();
  if (texto.trim().length < 3) {
    mostrarSugestoes([]);
    return;
  }
  const controle = new AbortController();
  s.consulta = controle;
  try {
    const r = await fetch(`/api/endereco/sugestoes?q=${encodeURIComponent(texto)}&sessao=${encodeURIComponent(s.sessaoBusca)}`, { signal: controle.signal });
    const dados = await r.json();
    if (sessao !== s || s.consulta !== controle) return;
    mostrarSugestoes(dados.sugestoes ?? [], 'Nenhum endereço encontrado. Confira a rua ou arraste o mapa até o local.');
  } catch (erro) {
    if (erro.name !== 'AbortError' && sessao === s) mostrarSugestoes([], 'A busca não respondeu agora. Arraste o mapa até o local.');
  }
}

async function escolherSugestao(id, rotulo) {
  const s = sessao;
  if (!s?.map) return;
  mostrarSugestoes([]);
  $('#mapa-busca').value = rotulo;
  try {
    const r = await fetch(`/api/endereco/detalhe?id=${encodeURIComponent(id)}&sessao=${encodeURIComponent(s.sessaoBusca)}`);
    if (!r.ok) throw new Error('detalhe');
    const d = await r.json();
    if (sessao !== s) return;
    s.sessaoBusca = novaSessaoDeBusca();   // o Google cobra por sessão: autocompletar + detalhe = uma sessão
    s.escolhido = d;
    s.pontoEscolhido = true;
    avisar('Confira se o pino está no local certo. Se não estiver, arraste o mapa.');
    s.map.setView([d.lat, d.lng], 18);
    agendarBusca();
  } catch {
    if (sessao === s) avisar('Não achamos esse endereço no mapa. Arraste o mapa até o local.', true);
  }
}

delegar(dialogo, 'input', '#mapa-busca', (e, entrada) => {
  const s = sessao;
  if (!s) return;
  clearTimeout(s.digitando);
  s.digitando = setTimeout(() => buscarSugestoes(entrada.value), 300);
});
delegar(dialogo, 'keydown', '#mapa-busca', (e) => {
  const opcoes = [...dialogo.querySelectorAll('[data-sugestao]')];
  if (!opcoes.length) return;
  const atual = opcoes.findIndex((b) => b.closest('li').getAttribute('aria-selected') === 'true');
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    const proxima = (atual + (e.key === 'ArrowDown' ? 1 : -1) + opcoes.length) % opcoes.length;
    opcoes.forEach((b, i) => b.closest('li').setAttribute('aria-selected', String(i === proxima)));
    $('#mapa-busca').setAttribute('aria-activedescendant', `mapa-sugestao-${proxima}`);
  } else if (e.key === 'Enter') {
    e.preventDefault();
    const alvo = opcoes[atual >= 0 ? atual : 0];
    escolherSugestao(alvo.dataset.sugestao, alvo.querySelector('strong').textContent);
  } else if (e.key === 'Escape') {
    e.stopPropagation();
    mostrarSugestoes([]);
  }
});
delegar(dialogo, 'click', '[data-sugestao]', (e, botao) => escolherSugestao(botao.dataset.sugestao, botao.querySelector('strong').textContent));

delegar(dialogo, 'click', '#mapa-localizar', () => {
  if (!sessao?.map) return;
  pedirLocalizacao();
});
delegar(dialogo, 'click', '[data-acao="digitar"]', () => fecharFolha(dialogo));
delegar(dialogo, 'click', '[data-acao="confirmar-local"]', () => {
  const s = sessao;
  if (!s?.map || !s.pontoEscolhido) return;
  const { lat, lng } = s.map.getCenter();
  s.resultado = { lat: Number(lat.toFixed(6)), lng: Number(lng.toFixed(6)), rua: s.endereco?.rua ?? '', numero: s.endereco?.numero ?? '', bairro: s.endereco?.bairro ?? '' };
  fecharFolha(dialogo);
});
