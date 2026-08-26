/**
 * Les routes de l'application (section 27.18).
 *
 * Cinq ecrans, pas un de plus. L'application agent ne vend pas de billets, ne
 * gere pas d'evenements et n'administre rien : chaque ecran ajoute ici devrait
 * se justifier contre le perimetre de la section 27.17.
 *
 * Le type est partage pour que la navigation soit verifiee a la compilation —
 * un parametre d'evenement oublie planterait sinon au pied d'une porte.
 */
export type RootStackParamList = {
  Events: undefined;
  Scan: { eventId: number; eventName: string };
  History: { eventId: number; eventName: string };
  Search: { eventId: number; eventName: string };
  Dashboard: { eventId: number; eventName: string };
};
