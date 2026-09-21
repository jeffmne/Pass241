import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Rate } from 'k6/metrics';

// Cible exclusivement une preproduction dediee avec PAYMENT_DEFAULT_DRIVER=fake.
// Le stock doit permettre VUS billets ; une vente refusee fait echouer le test.
const base = (__ENV.BASE_URL || '').replace(/\/$/, '');
const eventPath = __ENV.EVENT_PATH;
const typeId = __ENV.TICKET_TYPE_ID;
if (!base || !eventPath || !typeId || __ENV.ALLOW_PURCHASE_LOAD_TEST !== '1') {
  throw new Error('Renseigner BASE_URL, EVENT_PATH, TICKET_TYPE_ID et ALLOW_PURCHASE_LOAD_TEST=1 sur une preproduction fake.');
}
const purchased = new Rate('reservations_created');
export const options = {
  scenarios: {
    burst: { executor: 'per-vu-iterations', vus: Number(__ENV.VUS || 20), iterations: 1, maxDuration: '5m' },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{phase:purchase}': ['p(95)<3000', 'p(99)<8000'],
    reservations_created: ['rate>0.99'],
    checks: ['rate>0.99'],
  },
};

export default function () {
  const page = http.get(base + eventPath, { tags: { phase: 'browse' } });
  if (!check(page, { 'page disponible': (r) => r.status === 200 })) {
    purchased.add(false); fail('Page indisponible');
  }
  const dom = page.html();
  let snapshot;
  dom.find('[wire\\:snapshot]').each((_index, element) => {
    const raw = element.attr('wire:snapshot');
    if (JSON.parse(raw).memo.name === 'public.checkout') snapshot = raw;
  });
  const csrf = dom.find('meta[name="csrf-token"]').attr('content') || dom.find('script[data-csrf]').attr('data-csrf');
  const endpoint = dom.find('script[data-update-uri]').attr('data-update-uri');
  if (!snapshot || !csrf || !endpoint) {
    purchased.add(false); fail('Contrat Livewire absent : verifier la page avant la montee en charge.');
  }
  // Une cle privee par navigateur n'est jamais devinee : on rejoue le snapshot
  // signe et le CSRF obtenus avec les vrais cookies de chaque utilisateur.
  sleep(Number(__ENV.THINK_SECONDS || 1));
  const response = http.post(endpoint.startsWith('http') ? endpoint : base + endpoint, JSON.stringify({
    _token: csrf,
    components: [{
      snapshot,
      updates: { [`quantities.${typeId}`]: 1, firstName: 'Acheteur', lastName: 'Charge', email: '', provider: 'AIRTEL', payerPhone: '074000000' },
      calls: [{ path: '', method: 'placeOrder', params: [] }],
    }],
  }), { headers: { 'Content-Type': 'application/json', 'X-Livewire': 'true', Accept: 'application/json' }, tags: { phase: 'purchase' } });
  let redirect;
  try { redirect = response.json('components.0.effects.redirect'); } catch { /* Comptabilise ci-dessous. */ }
  const ok = response.status === 200 && typeof redirect === 'string' && redirect.includes('/commande/');
  purchased.add(ok);
  check(response, { 'reservation creee': () => ok });
  if (ok) {
    const status = http.get(redirect.startsWith('http') ? redirect : base + redirect, { tags: { phase: 'status' } });
    check(status, { 'suivi accessible': (r) => r.status === 200 });
  }
}
