// Mapa de uma faixa da taxa de entrega (D105): ao adicionar ou editar uma faixa, o dono vê no mapa o círculo da área que ela
// cobre, a partir do local da loja, e o círculo acompanha o km enquanto ele digita. As outras faixas aparecem mais claras.
// O círculo é em linha reta, igual à conta da taxa (TaxaDeEntrega): o que está dentro dele paga a taxa desta faixa.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { carregarLeaflet, camadasDoMapa } from '../cardapio/mapa.js';

const dialogo = $('#folha-mapa');   // a mesma janela do mapa do local da loja (as duas nunca abrem juntas)
let sessao = null;

const cor = (nome) => getComputedStyle(document.documentElement).getPropertyValue(nome).trim() || '#c2410c';
const kmDeTexto = (texto) => {
  const n = Number(String(texto).trim().replace(',', '.'));
  return Number.isFinite(n) && n > 0 && n <= 100 ? n : null;
};

/**
 * @param {{ loja: {lat:number, lng:number}, faixa?: {km:string, taxa:string}, outras?: number[], titulo?: string }} opcoes
 *   faixa: os valores atuais (editar) ou vazio (adicionar); outras: o km das outras faixas, desenhadas mais claras
 * @returns {Promise<{km:string, taxa:string} | null>} os valores confirmados, ou null se fechou
 */
export function faixaNoMapa({ loja, faixa = { km: '', taxa: '' }, outras = [], titulo = 'Nova faixa de entrega' }) {
  return new Promise((resolver) => {
    sessao = { resolver, resultado: null, map: null, circulo: null, loja };
    dialogo.innerHTML = `
      <header class="folha__topo">
        <h2 class="folha__titulo" id="mapa-titulo">${esc(titulo)}</h2>
        <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar o mapa">${icone('fechar')}</button>
      </header>
      <div class="mapa" id="mapa">
        <div class="mapa__tela" id="mapa-tela" aria-label="Mapa com a área desta faixa em volta da loja"></div>
        <p class="mapa__aviso" id="mapa-aviso" role="status" hidden></p>
      </div>
      <form class="folha__rodape mapa__rodape" id="form-faixa-mapa" novalidate>
        <div class="faixa-raio faixa-raio--mapa">
          <label class="campo"><span class="campo__rotulo">Até (km)</span>
            <input class="entrada" name="km" inputmode="decimal" value="${esc(faixa.km)}" placeholder="Ex.: 3" required></label>
          <label class="campo"><span class="campo__rotulo">Taxa (R$)</span>
            <input class="entrada" name="taxa" inputmode="decimal" value="${esc(faixa.taxa)}" placeholder="Ex.: 5,00" required></label>
        </div>
        <p class="campo__ajuda" id="faixa-mapa-ajuda" aria-live="polite">Digite a distância para ver a área no mapa.</p>
        <div class="mapa__acoes">
          <button type="button" class="botao botao--secundario" data-fechar>Cancelar</button>
          <button type="submit" class="botao">Usar esta faixa</button>
        </div>
      </form>`;
    abrirFolha(dialogo, { aoFechar: encerrar });
    iniciar(outras);
    $('#form-faixa-mapa [name="km"]', dialogo)?.focus();
  });
}

async function iniciar(outras) {
  const s = sessao;
  let L;
  try {
    L = await carregarLeaflet();
  } catch {
    aviso('Não conseguimos carregar o mapa agora. A faixa pode ser salva mesmo assim.');
    return;
  }
  if (sessao !== s) return;
  const centro = [s.loja.lat, s.loja.lng];
  s.map = L.map('mapa-tela', { center: centro, zoom: 13, zoomControl: false });
  L.control.zoom({ position: 'topright', zoomInTitle: 'Aproximar', zoomOutTitle: 'Afastar' }).addTo(s.map);
  s.map.attributionControl.setPrefix(false);
  camadasDoMapa(L, s.map).once('tileerror', () => aviso('As imagens do mapa não carregaram. Confira a internet.'));

  const acento = cor('--cor-acento');
  for (const km of outras.filter((k) => k > 0).sort((a, b) => b - a)) {
    L.circle(centro, { radius: km * 1000, color: cor('--cor-texto-2'), weight: 2, dashArray: '6 5', fillOpacity: 0.06, interactive: false })
      .bindTooltip(`até ${String(km).replace('.', ',')} km`, { permanent: false }).addTo(s.map);
  }
  L.circleMarker(centro, { radius: 7, color: '#fff', weight: 2, fillColor: acento, fillOpacity: 1 }).bindTooltip('Loja').addTo(s.map);
  s.circulo = L.circle(centro, { radius: 1, color: acento, weight: 2, fillColor: acento, fillOpacity: 0.15, interactive: false });
  desenhar(true);
}

/** Redesenha o círculo pelo km digitado; `enquadrar` aproxima ou afasta o mapa para mostrar o círculo inteiro. */
function desenhar(enquadrar = false) {
  const s = sessao;
  if (!s?.map) return;
  const km = kmDeTexto($('#form-faixa-mapa [name="km"]', dialogo).value);
  const ajuda = $('#faixa-mapa-ajuda', dialogo);
  if (km === null) {
    s.circulo.remove();
    ajuda.textContent = 'Digite a distância para ver a área no mapa.';
    return;
  }
  s.circulo.setRadius(km * 1000).addTo(s.map);
  ajuda.textContent = `Quem estiver dentro do círculo (até ${String(km).replace('.', ',')} km da loja, em linha reta) paga a taxa desta faixa.`;
  if (enquadrar) s.map.fitBounds(s.circulo.getBounds(), { padding: [24, 24], animate: false });
}

function aviso(texto) {
  const el = $('#mapa-aviso', dialogo);
  if (!el) return;
  el.textContent = texto;
  el.hidden = false;
}

function encerrar() {
  const s = sessao;
  if (!s) return;
  sessao = null;
  clearTimeout(s.espera);
  s.map?.remove();
  s.resolver(s.resultado);
}

delegar(dialogo, 'input', '#form-faixa-mapa [name="km"]', () => {
  if (!sessao) return;
  desenhar(false);
  clearTimeout(sessao.espera);
  sessao.espera = setTimeout(() => desenhar(true), 400);   // para de digitar: o mapa enquadra o círculo novo
});
dialogo.addEventListener('submit', (e) => {
  if (!sessao || e.target.id !== 'form-faixa-mapa') return;
  e.preventDefault();
  const form = e.target;
  if (kmDeTexto(form.km.value) === null || !form.taxa.value.trim()) {
    $('#faixa-mapa-ajuda', dialogo).textContent = 'Preencha a distância (até 100 km) e a taxa (ex.: 5,00; use 0 para grátis).';
    (kmDeTexto(form.km.value) === null ? form.km : form.taxa).focus();
    return;
  }
  sessao.resultado = { km: form.km.value.trim(), taxa: form.taxa.value.trim() };
  fecharFolha(dialogo);
});
