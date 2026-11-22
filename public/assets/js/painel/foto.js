// Foto enviada pelo painel (cardápio e logo da loja): reduzida no navegador antes de subir para /api/admin/upload.
// O servidor só guarda o que chega; quem encolhe é o aparelho de quem envia (o PHP da imagem de produção não tem GD).

/** Reduz a imagem para no máximo `lado` px e converte para WebP (ou JPEG, se o navegador não gerar WebP). */
export async function reduzirFoto(arquivo, lado = 1200, qualidade = 0.82) {
  const imagem = await createImageBitmap(arquivo);
  try {
    return await gerar(imagem, lado, qualidade);
  } finally {
    imagem.close?.();
  }
}

/**
 * Foto do cardápio em dois tamanhos, para o banco guardar pouco e o celular do cliente baixar pouco:
 *   foto:      até 960 px, para o detalhe do item (aparece com 640 px de largura)
 *   miniatura: até 240 px, para a lista (aparece com 88 px; 240 cobre telas de densidade 2x e 3x)
 */
export async function fotoDoCardapio(arquivo) {
  const imagem = await createImageBitmap(arquivo);
  try {
    return { foto: await gerar(imagem, 960, 0.8), miniatura: await gerar(imagem, 240, 0.75) };
  } finally {
    imagem.close?.();
  }
}

async function gerar(imagem, lado, qualidade) {
  const escala = Math.min(1, lado / Math.max(imagem.width, imagem.height));
  const tela = document.createElement('canvas');
  tela.width = Math.max(1, Math.round(imagem.width * escala));
  tela.height = Math.max(1, Math.round(imagem.height * escala));
  const pincel = tela.getContext('2d');
  pincel.imageSmoothingQuality = 'high';   // redução grande (4000 px → 240 px) sem serrilhado
  pincel.drawImage(imagem, 0, 0, tela.width, tela.height);
  const blob = (tipo) => new Promise((pronto) => tela.toBlob(pronto, tipo, qualidade));
  let resultado = await blob('image/webp');
  if (!resultado || resultado.type !== 'image/webp') resultado = await blob('image/jpeg');
  return resultado;
}

