// Página do cliente: a vitrine do cardápio, o fechamento do pedido e o acompanhamento do pedido na Home.
import { iniciarVitrine } from './vitrine.js';
import { definirAoFecharPedido } from './carrinho.js';
import { abrirCheckout } from './checkout.js';
import { iniciarAcompanhamento } from './acompanhamento.js';

definirAoFecharPedido(abrirCheckout);
iniciarVitrine({ comLoja: true });
iniciarAcompanhamento();
