// Animação do status do pedido: uma cena de cozinha para cada etapa, em SVG com CSS (sem biblioteca).
// A cena acompanha o status: anotação chegando → frigideira com massa pulando → cloche levantando → moto → sino no balcão.
// Só `transform` e `opacity` se mexem (barato para o celular) e tudo para com prefers-reduced-motion.
// O desenho é decorativo (aria-hidden): quem lê o status é o texto ao lado. Os estilos estão em components.css.

const BRILHO = 'M0-7 2-2 7 0 2 2 0 7-2 2-7 0-2-2Z';

const CENAS = {
  // Recebemos: a anotação do pedido chega à cozinha, com um selo de "recebido" que pulsa.
  novo: `
    <g class="an-flutua">
      <path class="an-papel" d="M52 22h56a4 4 0 0 1 4 4v72l-4-5-4 5-4-5-4 5-4-5-4 5-4-5-4 5-4-5-4 5-4-5-4 5-4-5-4 5-4-5-4 5-4-5-4 5V26a4 4 0 0 1 4-4Z"/>
      <path d="M60 40h40M60 52h40M60 64h28M60 76h34"/>
    </g>
    <g class="an-selo">
      <circle class="an-onda" cx="112" cy="30" r="12"/>
      <circle class="an-verde" cx="112" cy="30" r="12"/>
      <path class="an-check-branco" d="m106.5 30.5 4 4 7.5-8"/>
    </g>`,

  // Em preparo: frigideira no fogo, massa e ingredientes pulando, vapor subindo.
  em_preparo: `
    <path d="M30 108h100"/>
    <g class="an-chamas">
      <path class="an-chama an-fogo" style="--d:0s" d="M62 108c-5-4-5-10 0-16 5 6 5 12 0 16Z"/>
      <path class="an-chama an-fogo" style="--d:.25s" d="M80 108c-6-5-6-12 0-19 6 7 6 14 0 19Z"/>
      <path class="an-chama an-fogo" style="--d:.5s" d="M98 108c-5-4-5-10 0-16 5 6 5 12 0 16Z"/>
    </g>
    <g class="an-vapor">
      <path class="an-fio" style="--d:0s" d="M64 50c-4-5 4-8 0-13"/>
      <path class="an-fio" style="--d:.7s" d="M80 48c-4-5 4-8 0-13"/>
      <path class="an-fio" style="--d:1.4s" d="M96 50c-4-5 4-8 0-13"/>
    </g>
    <g class="an-comida">
      <circle class="an-pedaco an-fogo" style="--d:0s" cx="65" cy="62" r="4.5"/>
      <path class="an-pedaco an-massa-fio" style="--d:.18s" d="M75 62c3-5 6 3 10-1" />
      <circle class="an-pedaco an-verde" style="--d:.36s" cx="97" cy="62" r="4"/>
    </g>
    <g class="an-frigideira">
      <path class="an-papel" d="M46 68h68c0 12-12 20-34 20S46 80 46 68Z"/>
      <path class="an-cabo" d="M114 72l26-6"/>
    </g>`,

  // Pronto: a cloche levanta e mostra o prato, com brilhos.
  pronto: `
    <path class="an-papel" d="M30 98h100"/>
    <ellipse class="an-papel" cx="80" cy="97" rx="46" ry="7"/>
    <path class="an-macarrao" d="M60 91c0-9 9-14 20-14s20 5 20 14c-4 2-12 3-20 3s-16-1-20-3Z"/>
    <g class="an-cloche">
      <path class="an-papel" d="M42 91c0-26 17-40 38-40s38 14 38 40Z"/>
      <circle class="an-papel" cx="80" cy="46" r="4"/>
    </g>
    <g transform="translate(36 44)"><path class="an-brilho an-brilho--a" d="${BRILHO}"/></g>
    <g transform="translate(126 40)"><path class="an-brilho an-brilho--b" d="${BRILHO}"/></g>
    <g transform="translate(112 22)"><path class="an-brilho an-brilho--c" d="${BRILHO}"/></g>`,

  // Saiu para entrega: moto com a caixa nas costas, estrada correndo.
  saiu_entrega: `
    <path class="an-estrada" d="M6 106h148"/>
    <g class="an-linhas"><path d="M16 64h16M8 76h22M20 88h12"/></g>
    <g class="an-moto">
      <rect class="an-verde-suave" x="34" y="58" width="32" height="26" rx="4"/>
      <path d="M40 71h20"/>
      <g class="an-roda"><circle class="an-papel" cx="52" cy="94" r="11"/><path d="M52 83v22M41 94h22"/></g>
      <g class="an-roda"><circle class="an-papel" cx="114" cy="94" r="11"/><path d="M114 83v22M103 94h22"/></g>
      <path d="M52 94h24l12-26h14l12 26"/>
      <path d="M102 68l6-11h11"/>
      <circle class="an-papel" cx="90" cy="50" r="7"/>
      <path d="M89 57l-5 11"/>
    </g>`,

  // Aguardando retirada: a sacola no balcão e o sino chamando.
  aguardando_retirada: `
    <path d="M18 102h124"/>
    <g class="an-flutua">
      <path class="an-papel" d="M40 102V60h42v42Z"/>
      <path d="M51 60c0-13 20-13 20 0M40 74h42"/>
    </g>
    <g class="an-sino">
      <path class="an-verde-suave" d="M96 102c0-14 8-22 16-22s16 8 16 22Z"/>
      <path d="M112 80v-6M107 74h10M92 102h40"/>
    </g>
    <g class="an-ding"><path d="M134 82l7-4M137 92h9M134 72l6-7"/></g>`,

  // Concluído: o selo de pronto desenha o check e solta brilhos.
  concluido: `
    <circle class="an-verde-suave" cx="80" cy="60" r="34"/>
    <path class="an-desenha" d="m63 61 12 12 23-25"/>
    <g transform="translate(34 34)"><path class="an-brilho an-brilho--a" d="${BRILHO}"/></g>
    <g transform="translate(128 42)"><path class="an-brilho an-brilho--b" d="${BRILHO}"/></g>
    <g transform="translate(122 96)"><path class="an-brilho an-brilho--c" d="${BRILHO}"/></g>`,

  // Cancelado: nada se mexe.
  cancelado: `
    <circle class="an-papel" cx="80" cy="60" r="34"/>
    <path d="m66 46 28 28M94 46 66 74"/>`,
};

/** @param {string} status  novo | em_preparo | pronto | saiu_entrega | aguardando_retirada | concluido | cancelado */
export function animacaoStatus(status) {
  return `<svg class="an" data-cena="${status in CENAS ? status : 'novo'}" viewBox="0 0 160 120" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${CENAS[status] ?? CENAS.novo}</svg>`;
}
