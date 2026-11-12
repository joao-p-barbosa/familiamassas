// Entrada do painel (SPA). A casca (menu, avisos, janelas) é do casca.js; aqui só se liga o que mantém o painel vivo
// e se abre a tela do endereço. Ver roteador.js para o funcionamento das telas.
import './casca.js';
import { iniciarServicos } from './servicos.js';
import { iniciarRoteador } from './roteador.js';

// O nome da loja vai no <html> para o título de cada tela ("Pedidos · Família Massas").
document.documentElement.dataset.loja = JSON.parse(document.getElementById('dados-painel').textContent).nomeLoja ?? '';

iniciarServicos();
iniciarRoteador();
