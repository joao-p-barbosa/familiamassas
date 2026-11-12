// Serviços do painel que precisam funcionar em QUALQUER tela, por isso vivem na casca e não no quadro de Pedidos:
//   * o fluxo de pedidos (consulta o servidor a cada poucos segundos, só o que mudou);
//   * o som e o aviso de pedido novo;
//   * a impressão automática da comanda (o aparelho marcado como estação imprime mesmo com outra tela aberta);
//   * o aviso "pedido de <cliente> pronto para servir" para a atendente, onde quer que ela esteja.
// As telas só "escutam" o fluxo (ouvir) e desenham o que quiserem.
import { avisar } from '../shared/avisos.js';
import { criarFonte } from './pedidos-vivos.js';
import { imprimirAutomatico, estacao } from './impressao.js';
import { tocarAlerta } from './som.js';
import { iniciarDetalhe, atualizarDetalhe } from './detalhe.js';
import { usuario } from './casca.js';

const ouvintes = { antes: new Set(), mudou: new Set() };

/**
 * Escuta o fluxo de pedidos. `antes`: vai mudar (a tela guarda a posição dos cartões para animar);
 * `mudou`: mudou ('dados' ou 'conexao'). Devolve a função que para de escutar (chame em desmontar()).
 */
export function ouvir(evento, funcao) {
  ouvintes[evento].add(funcao);
  return () => ouvintes[evento].delete(funcao);
}

/** Pedidos que acabaram de chegar (para o quadro destacar o cartão por um instante). */
export const recemChegados = new Set();

const imprimindo = new Set();
let statusAnterior = null; // id → status, para perceber quem ficou pronto agora

export const fonte = criarFonte({
  antesDeMudar: () => ouvintes.antes.forEach((f) => f()),
  aoMudar: (motivo) => {
    ouvintes.mudou.forEach((f) => f(motivo));
    if (motivo === 'conexao') return;
    avisarProntosDoSalao();
    atualizarDetalhe();
    imprimirPendentes();
  },
  aoChegar: (todos) => {
    todos.forEach((p) => recemChegados.add(p.id));
    // Quem acabou de registrar o pedido no salão não precisa de aviso (nem de som) do próprio pedido.
    const novos = todos.filter((p) => p.criado_por !== usuario.id);
    if (novos.length === 0) return;
    tocarAlerta();
    avisar(novos.length === 1 ? `Pedido novo: #${novos[0].id}, ${novos[0].cliente_nome}` : `${novos.length} pedidos novos`);
    const antes = document.title;
    document.title = `(${novos.length}) Pedidos novos`;
    setTimeout(() => { if (document.title.startsWith('(')) document.title = antes; }, 8000);
  },
});

/** Pedido de um atendimento do salão que passou para "Pronto": a atendente é avisada para servir. */
function avisarProntosDoSalao() {
  const lista = fonte.lista();
  const atual = new Map(lista.map((p) => [p.id, p.status]));
  if (statusAnterior) {
    const prontos = lista.filter((p) => p.atendimento_id && p.status === 'pronto' && ['novo', 'em_preparo'].includes(statusAnterior.get(p.id)));
    if (prontos.length > 0) {
      tocarAlerta();
      avisar(prontos.length === 1 ? `Pedido de ${prontos[0].cliente_nome} pronto para servir` : `${prontos.length} pedidos prontos para servir`);
    }
  }
  statusAnterior = atual;
}

/** Imprime as comandas ainda não impressas (só se este aparelho for a estação de impressão). */
export async function imprimirPendentes() {
  if (!estacao.ativa) return;
  for (const p of fonte.lista()) {
    if (p.impresso_em || !['novo', 'em_preparo'].includes(p.status) || imprimindo.has(p.id)) continue;
    imprimindo.add(p.id);
    const atualizado = await imprimirAutomatico(p);
    imprimindo.delete(p.id);
    if (atualizado) fonte.guardar(atualizado);
  }
  atualizarDetalhe();
}

let iniciado = false;
export function iniciarServicos() {
  if (iniciado) return;
  iniciado = true;
  iniciarDetalhe(fonte);
  fonte.iniciar();
}
