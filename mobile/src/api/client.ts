/**
 * Client HTTP de l'application agent.
 *
 * Trois choses, et rien d'autre : porter le jeton, abandonner assez tot, et
 * distinguer une panne de reseau d'un refus du serveur. Cette derniere
 * distinction est la plus importante de l'application — un refus se montre a
 * l'agent, une panne de reseau ne se montre PAS, elle se met en file (section
 * 27.9).
 */

/** L'adresse du serveur, injectee au build (`EXPO_PUBLIC_API_URL`). */
export const apiBaseUrl: string =
  process.env.EXPO_PUBLIC_API_URL?.replace(/\/+$/, '') ?? 'http://10.0.2.2:8000';

/**
 * Delai d'abandon.
 *
 * Douze secondes : au-dela, sur une connexion mobile gabonaise, la requete a
 * plus de chances d'etre perdue que lente. Mieux vaut basculer hors connexion
 * et reessayer que laisser un agent devant une roue qui tourne pendant qu'une
 * file attend.
 */
const TIMEOUT_MS = 12_000;

/** Le serveur a repondu, et il a dit non. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly payload: unknown = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/** Le serveur n'a pas repondu. Ce n'est pas la meme chose. */
export class NetworkError extends Error {
  constructor(message = 'Serveur injoignable.') {
    super(message);
    this.name = 'NetworkError';
  }
}

type RequestOptions = {
  method?: 'GET' | 'POST';
  path: string;
  token?: string | null;
  body?: unknown;
  query?: Record<string, string | number | undefined>;
};

export async function request<T>(options: RequestOptions): Promise<T> {
  const { method = 'GET', path, token, body, query } = options;

  if (!__DEV__ && !apiBaseUrl.startsWith('https://')) {
    throw new ApiError(0, 'La connexion securisee au serveur n\'est pas configuree.');
  }

  /*
   * Chaine de requete assemblee a la main, et non avec `URL.searchParams`.
   *
   * L'implementation de `URL` de React Native est partielle : `searchParams` y
   * a longtemps manque, et une requete se retrouverait sans ses parametres —
   * une synchronisation qui recommence a zero a chaque page, sans le moindre
   * message d'erreur. Le cout de l'assemblage manuel est nul, celui du doute
   * ne l'est pas.
   */
  const url = apiBaseUrl + path + queryString(query);

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

  let response: Response;
  let text: string;

  try {
    response = await fetch(url, {
      method,
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    // Le delai couvre aussi un corps interrompu apres reception des en-tetes.
    text = await response.text();
  } catch {
    // Coupure, DNS, abandon : dans tous les cas le serveur n'a rien dit.
    throw new NetworkError();
  } finally {
    clearTimeout(timer);
  }

  const payload = text ? safeParse(text) : null;

  if (!response.ok) {
    throw new ApiError(response.status, messageFor(response.status, payload), payload);
  }

  if (text && payload === null) {
    throw new NetworkError('Reponse incomplete du serveur. Reessayez.');
  }

  return payload as T;
}

export function queryString(query: Record<string, string | number | undefined> | undefined): string {
  const parts = Object.entries(query ?? {})
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);

  return parts.length === 0 ? '' : `?${parts.join('&')}`;
}

function safeParse(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/**
 * Message lisible par un agent, pas par un developpeur.
 *
 * Les messages de validation du serveur sont deja ecrits pour etre lus a une
 * porte : on les reprend tels quels quand ils existent, plutot que de les
 * remplacer par un code d'erreur.
 */
function messageFor(status: number, payload: unknown): string {
  const errors = (payload as { errors?: Record<string, string[]> } | null)?.errors;

  if (errors) {
    const first = Object.values(errors)[0]?.[0];
    if (first) return first;
  }

  const message = (payload as { message?: string } | null)?.message;

  if (status === 401) return 'Session expiree. Reconnectez-vous.';
  if (status === 403) return 'Ce compte n\'a pas acces a cet evenement.';
  if (status === 404) return 'Introuvable.';
  if (status === 429) return 'Trop de tentatives. Patientez un instant.';
  if (status >= 500) return 'Le serveur a rencontre un probleme.';

  return message ?? 'La demande n\'a pas abouti.';
}
