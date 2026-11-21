// Foto enviada pelo painel (cardápio e logo da loja): reduzida no navegador antes de subir para /api/admin/upload.

/** Reduz a imagem para no máximo `lado` px e converte para WebP (ou JPEG, se o navegador não gerar WebP). */
export async function reduzirFoto(arquivo, lado = 1200) {
  const imagem = await createImageBitmap(arquivo);
  const escala = Math.min(1, lado / Math.max(imagem.width, imagem.height));
  const tela = document.createElement('canvas');
  tela.width = Math.round(imagem.width * escala);
  tela.height = Math.round(imagem.height * escala);
  tela.getContext('2d').drawImage(imagem, 0, 0, tela.width, tela.height);
  const gerar = (tipo) => new Promise((pronto) => tela.toBlob(pronto, tipo, 0.82));
  let blob = await gerar('image/webp');
  if (!blob || blob.type !== 'image/webp') blob = await gerar('image/jpeg');
  return blob;
}
