import { decideLocally } from '../domain/validate';
import type { LocalTicket, TicketStatus } from '../domain/types';

/*
 * La decision locale (section 27.7).
 *
 * C'est le seul endroit de l'application ou une erreur laisse entrer quelqu'un
 * qui ne devrait pas, ou laisse dehors quelqu'un qui a paye. Il est donc pur,
 * et il est teste en entier.
 */

function ticket(status: TicketStatus): LocalTicket {
  return {
    id: 7,
    reference: 'T-ABCD-2345',
    tokenHash: 'peu-importe',
    holderName: 'Marie Nzeng',
    ticketType: 'VIP',
    status,
    usedAt: null,
    updatedAt: null,
  };
}

it('laisse entrer un billet actif et le marque utilise', () => {
  const decision = decideLocally(ticket('ACTIVE'));

  expect(decision.verdict.code).toBe('ACCEPTED');
  expect(decision.verdict.accepted).toBe(true);
  expect(decision.verdict.instruction).toBe('Entree autorisee');

  // Sans cette marque, le meme billet repasserait la meme porte tant que le
  // reseau n'est pas revenu.
  expect(decision.marksUsed).toBe(true);
});

it('refuse un billet deja utilise sans le remarquer', () => {
  const decision = decideLocally(ticket('USED'));

  expect(decision.verdict.code).toBe('ALREADY_USED');
  expect(decision.verdict.accepted).toBe(false);
  expect(decision.marksUsed).toBe(false);
});

it('distingue un billet rembourse d un billet annule', () => {
  // Deux refus, deux causes : l'agent ne reagit pas pareil, et le journal ne
  // raconte pas la meme soiree.
  expect(decideLocally(ticket('REFUNDED')).verdict.code).toBe('REFUNDED');
  expect(decideLocally(ticket('CANCELLED')).verdict.code).toBe('CANCELLED');
  expect(decideLocally(ticket('EXPIRED')).verdict.code).toBe('CANCELLED');
});

it('refuse un QR inconnu de la base locale', () => {
  const decision = decideLocally(null);

  /*
   * Un billet d'un autre evenement tombe ici aussi : le serveur dira « autre
   * evenement », l'application dit « invalide ». Les deux refusent l'entree,
   * donc le geste de l'agent est le meme, et l'historique se corrige a la
   * synchronisation.
   */
  expect(decision.verdict.code).toBe('INVALID');
  expect(decision.verdict.holderName).toBeNull();
  expect(decision.marksUsed).toBe(false);
});

it('porte le nom du titulaire jusqu a l ecran', () => {
  const decision = decideLocally(ticket('ACTIVE'));

  // L'agent verifie le nom apres avoir lu la decision (section 27.8).
  expect(decision.verdict.holderName).toBe('Marie Nzeng');
  expect(decision.verdict.ticketType).toBe('VIP');
  expect(decision.verdict.reference).toBe('T-ABCD-2345');
});

it('marque une admission accordee sans lecture du QR', () => {
  const decision = decideLocally(ticket('ACTIVE'), { manual: true });

  // La marque suit le controle jusqu'au serveur, puis jusqu'au journal de
  // l'organisateur (section 27.15).
  expect(decision.verdict.manual).toBe(true);
  expect(decision.verdict.accepted).toBe(true);
});

it('n accorde rien de plus a la reference qu au QR', () => {
  const decision = decideLocally(ticket('REFUNDED'), { manual: true });

  expect(decision.verdict.code).toBe('REFUNDED');
  expect(decision.verdict.accepted).toBe(false);
});
