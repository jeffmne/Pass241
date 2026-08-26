import { queryString } from '../api/client';

/*
 * La chaine de requete (section 27.5).
 *
 * Assemblee a la main plutot que par `URL.searchParams`, dont l'implementation
 * de React Native est partielle. Un parametre perdu ne leve aucune erreur : la
 * synchronisation recommencerait simplement a zero a chaque page, en silence.
 * D'ou ce test.
 */

it('n ajoute rien quand il n y a pas de parametre', () => {
  expect(queryString(undefined)).toBe('');
  expect(queryString({})).toBe('');
});

it('ecarte les valeurs absentes plutot que d envoyer du vide', () => {
  // `since` absent signifie « tout telecharger » ; `since=` signifierait autre
  // chose, et le serveur le refuserait.
  expect(queryString({ since: undefined, cursor: 40 })).toBe('?cursor=40');
  expect(queryString({ q: '' })).toBe('');
});

it('encode ce qui doit l etre', () => {
  // Une recherche par nom contient des espaces, parfois une apostrophe.
  expect(queryString({ q: 'Marie Nzeng' })).toBe('?q=Marie%20Nzeng');
  expect(queryString({ q: 'a&b=c' })).toBe('?q=a%26b%3Dc');
});

it('assemble plusieurs parametres', () => {
  expect(queryString({ since: '2026-08-26T19:00:00Z', cursor: 500 })).toBe(
    '?since=2026-08-26T19%3A00%3A00Z&cursor=500',
  );
});
