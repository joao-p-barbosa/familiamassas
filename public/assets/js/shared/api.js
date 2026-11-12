// Conversa com a API. Toda chamada devolve os dados ou lança um ErroApi com mensagem pronta para mostrar.

export class ErroApi extends Error {
  constructor(mensagem, { status = 0, codigo = 'rede', campos = {} } = {}) {
    super(mensagem);
    this.status = status;
    this.codigo = codigo;
    this.campos = campos; // erros por campo do formulário: { nome: "Diga seu nome..." }
  }
}

// O painel coloca o token CSRF numa <meta>; o cardápio público não tem (e não precisa).
const csrf = () => document.querySelector('meta[name="csrf"]')?.content ?? '';

/**
 * @param {string} caminho  ex.: "/api/pedidos"
 * @param {{ metodo?: string, corpo?: any, formulario?: FormData, sinal?: AbortSignal }} opcoes
 */
export async function api(caminho, { metodo = 'GET', corpo, formulario, sinal } = {}) {
  const cabecalhos = { Accept: 'application/json' };
  const token = csrf();
  if (token) cabecalhos['X-CSRF-Token'] = token;

  // Hospedagem compartilhada às vezes bloqueia PUT/PATCH/DELETE: mandamos POST dizendo qual é o método real.
  let metodoReal = metodo;
  if (['PUT', 'PATCH', 'DELETE'].includes(metodo)) {
    cabecalhos['X-HTTP-Method-Override'] = metodo;
    metodoReal = 'POST';
  }
  let body;
  if (formulario) {
    body = formulario;
  } else if (corpo !== undefined) {
    cabecalhos['Content-Type'] = 'application/json';
    body = JSON.stringify(corpo);
  }

  let resposta;
  try {
    resposta = await fetch(caminho, { method: metodoReal, headers: cabecalhos, body, signal: sinal, credentials: 'same-origin' });
  } catch (erro) {
    if (erro.name === 'AbortError') throw erro;
    throw new ErroApi('Sem conexão. Confira a internet e tente de novo.');
  }

  let dados = null;
  try {
    dados = await resposta.json();
  } catch {
    /* resposta sem JSON (erro do servidor): tratado abaixo */
  }
  if (!resposta.ok) {
    const erro = dados?.erro ?? {};
    throw new ErroApi(erro.mensagem ?? 'Algo deu errado do nosso lado. Tente de novo em instantes.', {
      status: resposta.status,
      codigo: erro.codigo ?? 'erro',
      campos: erro.campos ?? {},
    });
  }
  return dados;
}
