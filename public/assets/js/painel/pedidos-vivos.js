// Fonte de pedidos do painel: guarda os pedidos em memória e pergunta ao servidor, de tempos em tempos,
// só o que mudou desde a última vez (polling, decisão D5: sem WebSocket).
import { api } from '../shared/api.js';
import { ajustes, mostrarErro } from './casca.js';

/**
 * @param {{ aoMudar: (motivo: 'dados'|'conexao') => void, aoChegar?: (novos: object[]) => void, antesDeMudar?: () => void }} opcoes
 *   aoMudar: algo mudou e a tela deve redesenhar;  aoChegar: pedidos novos que entraram depois da primeira carga.
 */
export function criarFonte({ aoMudar, aoChegar = () => {}, antesDeMudar = () => {} }) {
  const pedidos = new Map();   // id → pedido
  let desde = null;            // hora (do servidor) da última consulta
  let primeiraCarga = true;
  let buscando = false;

  const fonte = {
    pedidos,
    conectado: true,
    carregado: false,
    ultimaSincronia: null,

    lista: () => [...pedidos.values()],

    async sincronizar() {
      if (buscando) return;
      buscando = true;
      try {
        const r = await api(`/api/admin/pedidos${desde ? `?desde=${encodeURIComponent(desde)}` : ''}`);
        // Só interessa o que é novo ou tem data de atualização diferente da que já temos.
        const alterados = r.pedidos.filter((p) => pedidos.get(p.id)?.atualizado_em !== p.atualizado_em);
        const novos = primeiraCarga ? [] : alterados.filter((p) => !pedidos.has(p.id) && p.status === 'novo');
        const mudou = alterados.length > 0 || !fonte.carregado || !fonte.conectado;
        if (alterados.length > 0) {
          antesDeMudar(); // a tela guarda a posição dos cartões para animar a mudança
          for (const pedido of alterados) pedidos.set(pedido.id, pedido);
        }
        // Na próxima consulta pedimos com 2 s de folga: nada que mudou "no mesmo segundo" se perde
        // (pedido repetido não faz mal, o Map troca pelo mesmo).
        desde = new Date(new Date(r.agora).getTime() - 2000).toISOString();
        fonte.conectado = true;
        fonte.carregado = true;
        fonte.ultimaSincronia = new Date();
        primeiraCarga = false;
        if (mudou) aoMudar('dados');
        if (novos.length > 0) aoChegar(novos);
      } catch (erro) {
        if (erro.status === 401) {
          location.assign('/painel/login');
          return;
        }
        if (fonte.conectado) {
          fonte.conectado = false;
          aoMudar('conexao');
        }
      } finally {
        buscando = false;
      }
    },

    /** Muda a etapa de um pedido. Devolve o pedido atualizado, ou null se não deu. */
    async mudarStatus(id, status, motivo = null) {
      try {
        const pedido = await api(`/api/admin/pedidos/${id}/status`, { metodo: 'PATCH', corpo: { status, motivo } });
        antesDeMudar();
        pedidos.set(pedido.id, pedido);
        aoMudar('dados');
        return pedido;
      } catch (erro) {
        mostrarErro(erro);
        if (erro.status === 409) fonte.sincronizar(); // outra pessoa moveu: busca o estado real
        return null;
      }
    },

    /** Troca um pedido na memória (ex.: depois de marcar como impresso). */
    guardar(pedido) {
      pedidos.set(pedido.id, pedido);
    },

    iniciar() {
      fonte.sincronizar();
      // Continua consultando mesmo com a aba em segundo plano: a impressão automática depende disso.
      setInterval(() => fonte.sincronizar(), Math.max(3, ajustes.polling_segundos) * 1000);
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') fonte.sincronizar();
      });
      window.addEventListener('online', () => fonte.sincronizar());
    },
  };
  return fonte;
}
