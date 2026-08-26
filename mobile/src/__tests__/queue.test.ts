import { MAX_BATCH, matchResults, nextBatch, serialiseBatch, serialiseScan } from '../domain/queue';
import type { LocalScan } from '../domain/types';

/*
 * La file de synchronisation (sections 27.11, 27.12).
 *
 * Ce qui est verifie ici : qu'un controle deja effectue ne se perd jamais, et
 * que le verdict du serveur remplace toujours celui de l'application — meme
 * quand il le contredit.
 */

function scan(overrides: Partial<LocalScan> = {}): LocalScan {
  return {
    id: 1,
    ticketId: 42,
    reference: 'T-ABCD-2345',
    token: 'jeton-signe',
    result: 'ACCEPTED',
    confirmed: false,
    scannedAt: '2026-08-26T19:42:00.000Z',
    manual: false,
    holderName: 'Marie Nzeng',
    ticketType: 'VIP',
    ...overrides,
  };
}

it('envoie le jeton quand le QR a ete lu', () => {
  expect(serialiseScan(scan())).toEqual({
    token: 'jeton-signe',
    scanned_at: '2026-08-26T19:42:00.000Z',
  });
});

it('envoie la reference quand le QR n a pas pu etre lu', () => {
  const payload = serialiseScan(scan({ token: null, manual: true }));

  /*
   * Jamais les deux : le serveur privilegie le jeton, et joindre une reference
   * a un QR parfaitement lisible ferait passer une entree scannee pour une
   * admission manuelle.
   */
  expect(payload).toEqual({ reference: 'T-ABCD-2345', scanned_at: '2026-08-26T19:42:00.000Z' });
  expect(payload).not.toHaveProperty('token');
});

it('decoupe la file au plafond du serveur', () => {
  const pending = Array.from({ length: MAX_BATCH + 40 }, (_, index) => scan({ id: index + 1 }));

  // Au-dela, le serveur refuse le lot entier — et un lot refuse, c'est une
  // file qui ne repart jamais.
  expect(nextBatch(pending)).toHaveLength(MAX_BATCH);
});

it('applique le verdict du serveur a la bonne ligne', () => {
  const sent = [scan({ id: 10 }), scan({ id: 11, result: 'ACCEPTED' })];

  const outcomes = matchResults(sent, [
    { index: 0, result: 'ACCEPTED', scan_id: 900 },
    { index: 1, result: 'CONFLICT_LOST', scan_id: 901 },
  ]);

  expect(outcomes).toHaveLength(2);
  expect(outcomes[0]).toMatchObject({ localId: 10, result: 'ACCEPTED', confirmed: true });

  /*
   * L'agent avait lu « entree autorisee » ; un scan plus ancien remonte d'une
   * autre porte l'a emporte (section 31.1). L'application remplace, elle ne
   * discute pas.
   */
  expect(outcomes[1]).toMatchObject({ localId: 11, result: 'CONFLICT_LOST', confirmed: true });
});

it('laisse en attente un controle que le serveur n a pas pu enregistrer', () => {
  const outcomes = matchResults([scan({ id: 5 })], [
    { index: 0, error: 'Ce controle n\'a pas pu etre enregistre.' },
  ]);

  // Non confirme : il repartira au prochain envoi. C'est la difference entre
  // une ligne perdue et une ligne retentee.
  expect(outcomes[0]).toMatchObject({ localId: 5, confirmed: false, result: null });
});

it('ignore un resultat sans ligne correspondante', () => {
  // Appliquer un verdict a la mauvaise ligne serait pire que ne rien
  // appliquer : l'historique dirait qu'une autre personne est entree.
  expect(matchResults([scan({ id: 1 })], [{ index: 7, result: 'ACCEPTED' }])).toHaveLength(0);
});

it('complete le nom du porteur avec ce que le serveur connait', () => {
  const sent = [scan({ id: 3, holderName: null, ticketType: null })];

  const outcomes = matchResults(sent, [
    { index: 0, result: 'ACCEPTED', holder_name: 'Paul Obame', ticket_type: 'Standard' },
  ]);

  /*
   * Le cas du billet achete apres la derniere synchronisation : l'application
   * ne connaissait personne derriere ce QR, le serveur si.
   */
  expect(outcomes[0].holderName).toBe('Paul Obame');
  expect(outcomes[0].ticketType).toBe('Standard');
});

it('serialise un lot dans l ordre ou il a ete scanne', () => {
  const batch = [scan({ id: 1, scannedAt: 'A' }), scan({ id: 2, scannedAt: 'B' })];

  // L'appariement des reponses se fait par indice : l'ordre est le contrat.
  expect(serialiseBatch(batch).map((item) => item.scanned_at)).toEqual(['A', 'B']);
});
