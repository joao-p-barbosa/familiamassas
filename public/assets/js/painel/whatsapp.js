// Integração com o WhatsApp: mostra o que já está configurado e gera o link de teste do cardápio.
// O bot em si (webhook, botões, avisos de status) é a Etapa 4; esta tela não finge que ele já existe.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { telefone as formatarTelefone } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { conteudo, mostrarErro } from './casca.js';

function desenhar(s) {
  const item = (ok, texto) => `<li ${ok ? 'data-ok' : ''}><span class="checagem__marca">${icone(ok ? 'check' : 'menos', 14)}</span><span>${texto}</span></li>`;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">WhatsApp</h1></div>
    <div class="config">
      <section class="bloco">
        <h2 class="bloco__titulo">${s.configurado ? 'Credenciais preenchidas' : 'Bot ainda não ligado'}</h2>
        <p class="bloco__nota">${icone('info', 16)}<span>${s.configurado
          ? 'As quatro credenciais estão no arquivo .env. O envio e o recebimento de mensagens entram na próxima etapa do projeto.'
          : 'O bot usa a Cloud API oficial da Meta. As credenciais ficam no arquivo .env do servidor (fora da pasta pública) e nunca aparecem nesta tela.'}</span></p>
        <ul class="checagem">
          ${s.chaves.map((c) => item(c.preenchida, `${esc(c.rotulo)} <span class="campo__ajuda">(${esc(c.chave)})</span>`)).join('')}
          ${item(s.https, s.https ? 'Site em HTTPS' : 'Site em HTTPS (a Meta exige para o webhook; em desenvolvimento local não há)')}
        </ul>
        <div class="campo">
          <span class="campo__rotulo">Endereço do webhook para cadastrar na Meta</span>
          <code class="codigo">https://${esc(location.host)}/webhook/whatsapp.php</code>
        </div>
      </section>

      <section class="bloco">
        <h2 class="bloco__titulo">Últimas 24 horas</h2>
        <dl class="numeros">
          <div><dt>Mensagens recebidas</dt><dd class="numero">${s.recebidas_24h}</dd></div>
          <div><dt>Mensagens enviadas</dt><dd class="numero">${s.enviadas_24h}</dd></div>
          <div><dt>Avisos na fila de reenvio</dt><dd class="numero">${s.envios_pendentes}</dd></div>
          <div><dt>Pedidos pelo WhatsApp hoje</dt><dd class="numero">${s.pedidos_whatsapp_hoje}</dd></div>
        </dl>
      </section>

      <form class="bloco" id="form-link" novalidate>
        <h2 class="bloco__titulo">Link de teste do cardápio</h2>
        <p class="bloco__nota">${icone('ligacao', 16)}<span>É o link que o bot vai mandar no botão “Fazer pedido”. Quem abre já entra com o WhatsApp preenchido e o pedido chega com origem “WhatsApp”.</span></p>
        <label class="campo">
          <span class="campo__rotulo">Número do cliente</span>
          <input class="entrada" type="tel" name="telefone" inputmode="numeric" placeholder="(11) 91234-5678" aria-describedby="erro-telefone">
          <span class="campo__erro" id="erro-telefone" hidden></span>
        </label>
        <div><button type="submit" class="botao botao--secundario">Gerar link</button></div>
        <div id="link-gerado" hidden>
          <code class="codigo" id="link-url"></code>
          <p class="campo__ajuda" id="link-validade" style="margin-top: var(--e-2);"></p>
          <div style="display: flex; gap: var(--e-2); margin-top: var(--e-3);">
            <button type="button" class="botao botao--p" data-copiar>Copiar link</button>
            <a class="botao botao--secundario botao--p" id="link-abrir" target="_blank" rel="noopener">Abrir o cardápio</a>
          </div>
        </div>
      </form>
    </div>`;
}

delegar(conteudo, 'input', 'input[name="telefone"]', (e, entrada) => {
  entrada.value = formatarTelefone(entrada.value);
});
delegar(conteudo, 'click', '[data-copiar]', async () => {
  try {
    await navigator.clipboard.writeText($('#link-url').textContent);
    avisar('Link copiado');
  } catch {
    avisar('Não deu para copiar. Selecione o link e copie à mão.', { tipo: 'erro' });
  }
});
conteudo.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const erro = $('#erro-telefone');
  erro.hidden = true;
  const botao = form.querySelector('button[type="submit"]');
  botao.setAttribute('aria-busy', 'true');
  try {
    const r = await api('/api/admin/whatsapp/link', { metodo: 'POST', corpo: { telefone: form.telefone.value } });
    $('#link-url').textContent = r.url;
    $('#link-abrir').href = r.url;
    $('#link-validade').textContent = `Vale por ${r.validade_minutos / 60} horas. Depois disso o cardápio abre normal, sem o número preenchido.`;
    $('#link-gerado').hidden = false;
  } catch (falha) {
    if (falha.codigo === 'validacao') {
      erro.textContent = falha.campos.telefone;
      erro.hidden = false;
      form.telefone.focus();
    } else {
      mostrarErro(falha);
    }
  }
  botao.removeAttribute('aria-busy');
});

api('/api/admin/whatsapp').then(desenhar).catch(mostrarErro);
