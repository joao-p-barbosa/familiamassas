// Mapa para marcar o local da entrega, no estilo dos apps de entrega: o pino fica no centro e a pessoa arrasta o mapa
// (ou toca no ponto exato) até o lugar certo. Ao abrir, pede a localização do aparelho para começar perto dela.
//
// O mapa NUNCA é obrigatório: sem permissão, sem internet ou sem endereço encontrado, a pessoa volta ao formulário e
// digita o endereço como antes. Tudo que o mapa preenche continua editável.
//
// Terceiros usados (os dois são do OpenStreetMap, sem chave de acesso):
//   * imagens do mapa: tile.openstreetmap.org;
//   * "qual é o endereço deste ponto": nominatim.openstreetmap.org (uma consulta quando a pessoa para de mexer o mapa).
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

function carregarLeaflet() {
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
 * @param {{ inicial?: { lat: number, lng: number } | null }} opcoes  último ponto marcado (o mapa abre nele)
 * @returns {Promise<{ lat: number, lng: number, rua: string, numero: string, bairro: string } | null>}
 *   o local confirmado, ou null se a pessoa preferiu digitar o endereço.
 */
export function escolherNoMapa({ inicial = null, enderecoDaLoja = '' } = {}) {
  return new Promise((resolver) => {
    sessao = { resolver, resultado: null, map: null, endereco: null, buscando: null, pontoEscolhido: false, temporizador: null, enderecoDaLoja };
    dialogo.innerHTML = `
      <header class="folha__topo">
        <h2 class="folha__titulo" id="mapa-titulo">Onde entregamos?</h2>
        <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar o mapa">${icone('fechar')}</button>
      </header>
      <div class="mapa" id="mapa">
        <div class="mapa__tela" id="mapa-tela" aria-label="Mapa para marcar o local da entrega. Setas movem o mapa; mais e menos aproximam ou afastam."></div>
        <div class="mapa__pino" aria-hidden="true">${PINO}<span class="mapa__sombra"></span></div>
        <button type="button" class="botao botao--secundario botao--icone mapa__localizar" id="mapa-localizar" aria-label="Usar minha localização">${icone('alvo', 22)}</button>
        <p class="mapa__aviso" id="mapa-aviso" role="status" hidden></p>
      </div>
      <footer class="folha__rodape mapa__rodape">
        <div class="mapa__endereco" id="mapa-endereco" aria-live="polite"></div>
        <div class="mapa__acoes">
          <button type="button" class="botao botao--secundario" data-acao="digitar">Digitar o endereço</button>
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
    if (sessao === s) avisar('Não conseguimos carregar o mapa agora. Digite o endereço: o pedido segue normalmente.', true);
    return;
  }
  if (sessao !== s) return; // fechou enquanto carregava

  s.map = L.map('mapa-tela', { center: inicial ? [inicial.lat, inicial.lng] : CENTRO_PADRAO, zoom: inicial ? ZOOM_DO_PONTO : ZOOM_PADRAO, zoomControl: false });
  L.control.zoom({ position: 'topright', zoomInTitle: 'Aproximar', zoomOutTitle: 'Afastar' }).addTo(s.map);
  s.map.attributionControl.setPrefix(false);
  // O OpenStreetMap exige saber de que site vem o pedido (política de uso dos tiles). O site todo manda
  // Referrer-Policy "same-origin", então só nestas chamadas informamos a origem (endereço do site, sem o caminho).
  const imagens = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    referrerPolicy: 'origin',
    maxZoom: 19,
    attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
  }).addTo(s.map);
  imagens.once('tileerror', () => avisar('As imagens do mapa não carregaram. Confira a internet ou digite o endereço.', true));

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

function pedirLocalizacao() {
  const s = sessao;
  if (!s?.map) return;
  if (!('geolocation' in navigator) || !window.isSecureContext) {
    avisar('A localização do aparelho só funciona em conexão segura (https). Arraste o mapa até o local da entrega ou digite o endereço.');
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
      avisar(`${motivo} Arraste o mapa até o local da entrega ou digite o endereço.`);
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
    html = '<p class="mapa__dica">Arraste o mapa até o local exato da entrega, ou toque nele.</p>';
  } else if (s.carregando) {
    html = '<p class="mapa__dica" aria-busy="true">Buscando o endereço…</p>';
  } else if (s.endereco && (s.endereco.rua || s.endereco.bairro)) {
    html = `<p class="mapa__rua">${icone('pin', 18)}<span>${esc(ruaComNumero(s.endereco) || s.endereco.bairro)}</span></p>
            ${s.endereco.rua && s.endereco.bairro ? `<p class="mapa__bairro">${esc(s.endereco.bairro)}</p>` : ''}
            ${s.endereco.numero ? '' : '<p class="mapa__dica">O mapa não sabe o número: você completa na tela seguinte.</p>'}`;
  } else {
    html = `<p class="mapa__dica">${s.falhou ? 'Não conseguimos achar o nome da rua agora.' : 'Não achamos o nome da rua neste ponto.'} Confirme o local e digite o endereço na tela seguinte.</p>`;
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
  s.buscando?.abort();
  s.map?.remove();
  s.resolver(s.resultado);
}

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
