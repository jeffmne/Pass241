import type { AgentEvent, ScanResultCode, TicketStatus } from '../domain/types';
import type { ScanPayload, ServerScanResult } from '../domain/queue';
import { request } from './client';

/**
 * Les routes de l'API agent (sections 27.3, 27.5, 27.11, 27.14, 27.15).
 *
 * Un seul fichier pour toute la surface reseau : les chemins et la forme des
 * reponses vivent ici, et nulle part ailleurs. Quand le serveur bougera, il n'y
 * aura qu'un endroit a relire.
 *
 * Les noms de champs restent ceux du serveur (`snake_case`) jusqu'a la
 * frontiere : traduire au vol multiplierait les endroits ou un champ peut se
 * perdre en silence. La conversion se fait explicitement, dans les fonctions
 * qui rendent des objets du domaine.
 */

export type LoginResponse = {
  token: string;
  agent: { id: number; name: string; email: string };
  server_time: string;
};

export async function login(email: string, password: string, deviceId: string) {
  return request<LoginResponse>({
    method: 'POST',
    path: '/api/agent/connexion',
    body: { email, password, device_id: deviceId },
  });
}

export async function logout(token: string) {
  await request<null>({ method: 'POST', path: '/api/agent/deconnexion', token });
}

type EventsResponse = {
  events: Array<{
    id: number;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    venue: string | null;
    organization: string | null;
    entry_point: { id: number; name: string } | null;
    tickets_count: number;
  }>;
  server_time: string;
};

export async function fetchEvents(token: string): Promise<{
  events: AgentEvent[];
  serverTime: string;
}> {
  const data = await request<EventsResponse>({ path: '/api/agent/evenements', token });

  return {
    serverTime: data.server_time,
    events: data.events.map((event) => ({
      id: event.id,
      name: event.name,
      startsAt: event.starts_at,
      endsAt: event.ends_at,
      venue: event.venue,
      organization: event.organization,
      entryPoint: event.entry_point,
      ticketsCount: event.tickets_count,
    })),
  };
}

export type SyncPage = {
  tickets: Array<{
    id: number;
    reference: string;
    token_hash: string;
    holder_name: string | null;
    ticket_type: string | null;
    status: TicketStatus;
    allows_entry: boolean;
    updated_at: string | null;
  }>;
  next_cursor: number | null;
  has_more: boolean;
  synced_at: string;
};

/**
 * Une page de billets.
 *
 * `since` rend la synchronisation incrementale : une porte qui se reconnecte au
 * milieu de la soiree ne retelecharge pas tout. `cursor` pagine sans `OFFSET`,
 * dont le cout grandit avec la profondeur (section 72).
 */
export async function fetchTickets(
  token: string,
  eventId: number,
  options: { since?: string; cursor?: number } = {},
) {
  return request<SyncPage>({
    path: `/api/agent/evenements/${eventId}/billets`,
    token,
    query: { since: options.since, cursor: options.cursor },
  });
}

export type PushScansResponse = {
  results: ServerScanResult[];
  server_time: string;
};

export async function pushScans(
  token: string,
  eventId: number,
  deviceId: string,
  scans: ScanPayload[],
  clockOffsetSeconds: number | null,
) {
  return request<PushScansResponse>({
    method: 'POST',
    path: `/api/agent/evenements/${eventId}/scans`,
    token,
    body: {
      device_id: deviceId,
      clock_offset_seconds: clockOffsetSeconds,
      scans,
    },
  });
}

export type DashboardResponse = {
  event: { id: number; name: string };
  counters: Counters;
  mine: Counters;
  tickets: { expected: number; admitted: number; remaining: number };
  server_time: string;
};

export type Counters = {
  scanned: number;
  valid: number;
  already_used: number;
  invalid: number;
  by_result: Record<ScanResultCode, number>;
};

export async function fetchDashboard(token: string, eventId: number) {
  return request<DashboardResponse>({
    path: `/api/agent/evenements/${eventId}/tableau-de-bord`,
    token,
  });
}

export type HistoryResponse = {
  scans: Array<{
    scan_id: number;
    accepted: boolean;
    result: ScanResultCode;
    label: string;
    instruction: string;
    holder_name: string | null;
    ticket_type: string | null;
    reference: string | null;
    entry_point: string | null;
    scanned_at: string | null;
    manual: boolean;
    conflict_with: number | null;
  }>;
  next_before: number | null;
  has_more: boolean;
  server_time: string;
};

export async function fetchHistory(token: string, eventId: number, before?: number) {
  return request<HistoryResponse>({
    path: `/api/agent/evenements/${eventId}/controles`,
    token,
    query: { before },
  });
}

export type SearchResponse = {
  tickets: Array<{
    id: number;
    reference: string;
    holder_name: string | null;
    ticket_type: string | null;
    status: TicketStatus;
    allows_entry: boolean;
    used_at: string | null;
  }>;
  truncated: boolean;
  server_time: string;
};

/**
 * Recherche cote serveur.
 *
 * Ne remplace pas la recherche locale, qui reste la regle hors connexion : elle
 * la complete quand la base locale ne suffit pas — application fraichement
 * installee, ou billet achete apres la derniere synchronisation, ce qui arrive
 * sans arret dans les deux heures qui precedent un concert (section 27.15).
 */
export async function searchTickets(token: string, eventId: number, term: string) {
  return request<SearchResponse>({
    path: `/api/agent/evenements/${eventId}/recherche`,
    token,
    query: { q: term },
  });
}
