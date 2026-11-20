// Taxa de entrega por raio (D98), igual à do servidor (app/Services/TaxaDeEntrega.php). Aqui serve só para MOSTRAR.

/** Distância em linha reta (haversine), em km. */
export function km(lat1, lng1, lat2, lng2) {
  const rad = (g) => (g * Math.PI) / 180;
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
  return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(a)));
}

/**
 * @param {object} loja estado.loja (taxa_entrega_centavos e entrega_por_raio)
 * @param {{lat: number|null, lng: number|null}|null} local o ponto que o cliente marcou
 * @returns {{taxa: number, km: number|null, atende: boolean}}
 */
export function taxaDeEntrega(loja, local) {
  const fixa = { taxa: loja?.taxa_entrega_centavos ?? 0, km: null, atende: true };
  const r = loja?.entrega_por_raio;
  if (!r || local?.lat == null || local?.lng == null) return fixa;
  const dist = Math.round(km(r.lat, r.lng, local.lat, local.lng) * 100) / 100;
  const faixa = r.faixas.find((f) => dist <= f.ate_km);
  if (faixa) return { taxa: faixa.taxa_centavos, km: dist, atende: true };
  return r.alem_centavos !== null ? { taxa: r.alem_centavos, km: dist, atende: true } : { taxa: 0, km: dist, atende: false };
}

/** "1,3 km" */
export const kmTexto = (valor) => `${valor.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} km`;
