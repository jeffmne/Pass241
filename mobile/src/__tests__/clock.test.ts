import { clockOffsetSeconds, clockWarning, isClockSuspicious } from '../domain/clock';

/*
 * L'ecart d'horloge (section 31.1).
 *
 * L'arbitrage du double scan fait gagner l'horodatage local le plus ancien : un
 * appareil deregle perd des arbitrages qu'il aurait du gagner. L'application ne
 * corrige rien, elle mesure — et previent.
 */

it('mesure l avance de l appareil sur le serveur', () => {
  const offset = clockOffsetSeconds(
    '2026-08-26T19:00:00.000Z',
    new Date('2026-08-26T19:05:00.000Z'),
  );

  expect(offset).toBe(300);
});

it('mesure aussi le retard', () => {
  const offset = clockOffsetSeconds(
    '2026-08-26T19:00:00.000Z',
    new Date('2026-08-26T18:58:00.000Z'),
  );

  expect(offset).toBe(-120);
});

it('rend zero plutot qu un NaN sur une heure illisible', () => {
  // Un ecart faux serait remonte au serveur et fausserait une explication
  // d'arbitrage. Zero dit « inconnu » sans mentir sur l'ampleur.
  expect(clockOffsetSeconds('pas une date')).toBe(0);
});

it('tolere les petits ecarts', () => {
  // Deux minutes : la meme tolerance que le serveur. En dessous, le decalage ne
  // change aucun arbitrage reel.
  expect(isClockSuspicious(90)).toBe(false);
  expect(isClockSuspicious(-90)).toBe(false);
  expect(isClockSuspicious(300)).toBe(true);
});

it('ne previent pas quand l ecart est inconnu', () => {
  expect(isClockSuspicious(null)).toBe(false);
  expect(clockWarning(null)).toBeNull();
});

it('dit a l agent quoi faire, pas ce qui s est passe', () => {
  const message = clockWarning(-900);

  // Le message est lu debout, avant l'ouverture des portes : il donne le sens,
  // l'ampleur, et l'action.
  expect(message).toContain('15 min');
  expect(message).toContain('retard');
  expect(message).toContain('Reglez');
});
