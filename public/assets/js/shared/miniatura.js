// Foto do cardápio em tamanho de lista (D103): o upload guarda também uma versão pequena com o mesmo nome e "-mini".

/** /uploads/abc.webp → /uploads/abc-mini.webp. Outro endereço (ou vazio) volta igual. */
export function miniatura(caminho) {
  return /^\/uploads\/[a-f0-9]{24}\.(webp|jpg|png)$/.test(caminho ?? '') ? caminho.replace(/\.(\w+)$/, '-mini.$1') : caminho;
}
