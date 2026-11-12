// Vocabulário do atendimento do salão (a conta de um cliente), usado no painel.
// O servidor calcula a fase de cada atendimento (app/Services/AtendimentoService.php): aqui só ficam os rótulos.
import { esc } from './dom.js';
import { reais } from './formato.js';
import { resumoMontagem } from './pedido.js';

export const MODALIDADES = { mesa: { rotulo: 'Mesa', icone: 'talheres' }, viagem: { rotulo: 'Pra viagem', icone: 'sacola' } };
export const FORMAS_DE_PAGAMENTO = { pix: 'Pix', cartao: 'Cartão', dinheiro: 'Dinheiro' };

/** "Mesa 5" ou "Pra viagem". */
export const rotuloDaModalidade = (a) => (a.modalidade === 'mesa' && a.mesa ? `Mesa ${a.mesa}` : MODALIDADES[a.modalidade]?.rotulo ?? a.modalidade);

/** Onde o atendimento está no quadro do Salão. */
export const FASES = {
  cozinha: 'Na cozinha',
  pronto: 'Pronto para servir',
  conta: 'Aguardando a conta',
  encerrado: 'Encerrado',
};

/** "2× Monte seu prato · Inteiro · Penne · Sugo, 1× Água mineral" (texto já escapado), para o cartão. */
export function itensEmTexto(pedido) {
  return pedido.itens.map((item) => {
    const resumo = item.montagem ? resumoMontagem({ tamanho: item.montagem.tamanho, massa: item.montagem.massa, molho: item.montagem.molho }) : '';
    return `${item.quantidade}× ${esc(item.nome)}${resumo ? ` · ${esc(resumo)}` : ''}`;
  }).join(', ');
}

export { reais };
