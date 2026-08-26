import type { ScanResultCode } from '../domain/types';
import { allowsEntry, instructionFor, labelFor, toneFor } from '../domain/verdict';

/*
 * Les libelles de verdict (section 28).
 *
 * Ils sont dupliques depuis le serveur parce que, hors connexion, personne ne
 * peut les fournir. Ce test est le prix de cette duplication : il verifie
 * qu'aucun code ne se retrouve sans texte a afficher — ce qui donnerait un
 * ecran vide a une porte de concert.
 */

const CODES: ScanResultCode[] = [
  'ACCEPTED',
  'ALREADY_USED',
  'INVALID',
  'REFUNDED',
  'CANCELLED',
  'WRONG_EVENT',
  'CONFLICT_LOST',
];

it.each(CODES)('donne un libelle et une instruction pour %s', (code) => {
  expect(labelFor(code).length).toBeGreaterThan(0);
  expect(instructionFor(code).length).toBeGreaterThan(0);
});

it('n autorise l entree que sur un billet valide', () => {
  const accepted = CODES.filter(allowsEntry);

  // Un seul code fait entrer. Si cette liste s'allonge un jour, c'est une
  // decision, pas un effet de bord.
  expect(accepted).toEqual(['ACCEPTED']);
});

it('distingue trois tons et non deux', () => {
  /*
   * « Deja utilise » n'est pas un refus comme un autre : le billet est
   * authentique, quelqu'un est entre avec. L'agent doit chercher le vrai
   * porteur, pas soupconner une contrefacon (section 27.8).
   */
  expect(toneFor('ACCEPTED')).toBe('valid');
  expect(toneFor('ALREADY_USED')).toBe('used');
  expect(toneFor('CONFLICT_LOST')).toBe('used');
  expect(toneFor('INVALID')).toBe('invalid');
  expect(toneFor('REFUNDED')).toBe('invalid');
  expect(toneFor('WRONG_EVENT')).toBe('invalid');
});

it('dit la meme chose que le serveur sur les cas principaux', () => {
  // Copie fidele de `App\Enums\ScanResult` : un ecart ferait lire a l'agent
  // autre chose que ce que le journal retiendra.
  expect(labelFor('ACCEPTED')).toBe('Billet valide');
  expect(instructionFor('ACCEPTED')).toBe('Entree autorisee');
  expect(labelFor('ALREADY_USED')).toBe('Billet deja utilise');
  expect(labelFor('WRONG_EVENT')).toBe('Autre evenement');
});
