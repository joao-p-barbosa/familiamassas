// Parte comum a todas as telas do painel: dados da sessão, botão "Sair" e tratamento de sessão expirada.
import { $, dadosDaPagina, esc } from '../shared/dom.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { icone } from '../shared/icones.js';

const dados = dadosDaPagina('dados-painel');

export const usuario = dados.usuario;   // { id, nome, login, perfil }
export const ajustes = dados.ajustes;   // { polling_segundos, atraso_minutos, comanda_largura_mm, comanda_mostra_preco, nome_loja }
export const conteudo = $('#conteudo');
export const ehAdmin = usuario.perfil === 'admin';

/** Mostra o erro de uma chamada. Sessão expirada (401) leva de volta ao login. */
export function mostrarErro(erro) {
  if (erro?.status === 401) {
    location.assign('/painel/login');
    return;
  }
  avisar(erro?.message ?? 'Algo deu errado. Tente de novo.', { tipo: 'erro' });
}

$('#botao-sair')?.addEventListener('click', async () => {
  try {
    const r = await api('/api/admin/logout', { metodo: 'POST' });
    location.assign(r.ir_para);
  } catch {
    location.assign('/painel/login');
  }
});

/**
 * Diálogo de confirmação (no lugar do confirm() do navegador, que trava a tela e não segue o tema).
 * Devolve uma Promise: true se confirmou, false se desistiu.
 * @param {{ titulo: string, texto?: string, confirmar: string, perigo?: boolean }} opcoes
 */
export function confirmar({ titulo, texto = '', confirmar: rotulo, perigo = false }) {
  const dialogo = $('#folha-dialogo');
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-dialogo-titulo">${esc(titulo)}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    ${texto ? `<div class="folha__corpo"><p>${esc(texto)}</p></div>` : ''}
    <footer class="folha__rodape folha__rodape--acoes">
      <button type="button" class="botao botao--secundario" data-fechar>Voltar</button>
      <button type="button" class="botao ${perigo ? 'botao--perigo' : ''}" id="dialogo-confirmar">${esc(rotulo)}</button>
    </footer>`;
  return new Promise((resolver) => {
    let resposta = false;
    $('#dialogo-confirmar').addEventListener('click', () => {
      resposta = true;
      fecharFolha(dialogo);
    });
    abrirFolha(dialogo, { aoFechar: () => resolver(resposta) });
  });
}
