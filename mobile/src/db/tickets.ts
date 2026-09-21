import type { AgentEvent, LocalTicket, TicketStatus } from '../domain/types';
import type { SQLiteDatabase } from 'expo-sqlite';
import { database, writeTransaction } from './database';

/**
 * Les billets, cote appareil.
 *
 * Deux acces seulement, et ce sont les deux gestes de l'agent : retrouver un
 * billet par l'empreinte de son QR, ou le chercher a la main quand le QR ne se
 * lit pas (sections 27.7, 27.15).
 */

type TicketRow = {
  id: number;
  reference: string;
  token_hash: string;
  holder_name: string | null;
  ticket_type: string | null;
  status: TicketStatus;
  used_at: string | null;
  updated_at: string | null;
};

function toTicket(row: TicketRow): LocalTicket {
  return {
    id: row.id,
    reference: row.reference,
    tokenHash: row.token_hash,
    holderName: row.holder_name,
    ticketType: row.ticket_type,
    status: row.status,
    usedAt: row.used_at,
    updatedAt: row.updated_at,
  };
}

/**
 * Enregistre une page de synchronisation.
 *
 * `INSERT ... ON CONFLICT DO UPDATE` : la synchronisation incrementale renvoie
 * des billets deja connus dont le statut a change — un remboursement prononce
 * pendant la soiree, par exemple. Les inserer en double ferait apparaitre deux
 * fois la meme personne dans une recherche.
 *
 * Le statut local n'est PAS ecrase quand le billet est deja marque utilise ici
 * et que le serveur le croit encore actif : c'est le cas d'un controle fait
 * hors connexion et pas encore remonte. L'ecraser ferait rentrer la meme
 * personne deux fois.
 */
export async function upsertTickets(
  eventId: number,
  rows: Array<{
    id: number;
    reference: string;
    token_hash: string;
    holder_name: string | null;
    ticket_type: string | null;
    status: TicketStatus;
    updated_at: string | null;
  }>,
): Promise<void> {
  if (rows.length === 0) {
    return;
  }

  await writeTransaction(async (db) => {
    const statement = await db.prepareAsync(
        `INSERT INTO tickets (id, event_id, reference, token_hash, holder_name, ticket_type, status, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (id) DO UPDATE SET
           reference = excluded.reference,
           -- Une empreinte vide vient de la RECHERCHE, qui n'en descend pas :
           -- l'ecraser rendrait un billet deja telecharge illisible au scan,
           -- alors qu'il fonctionnait parfaitement.
           token_hash = CASE
             WHEN excluded.token_hash = '' THEN tickets.token_hash
             ELSE excluded.token_hash
           END,
           holder_name = excluded.holder_name,
           ticket_type = excluded.ticket_type,
           status = CASE
             WHEN tickets.status = 'USED' AND excluded.status = 'ACTIVE' THEN 'USED'
             ELSE excluded.status
           END,
           updated_at = excluded.updated_at`);
    try {
      for (const row of rows) {
        await statement.executeAsync(
          row.id,
          eventId,
          row.reference,
          row.token_hash,
          row.holder_name,
          row.ticket_type,
          row.status,
          row.updated_at,
        );
      }
    } finally {
      await statement.finalizeAsync();
    }
  });
}

/** Le geste du scan : une empreinte, une reponse (section 27.7). */
export async function findByTokenHash(
  eventId: number,
  tokenHash: string,
  tx?: SQLiteDatabase,
): Promise<LocalTicket | null> {
  const db = tx ?? await database();

  const row = await db.getFirstAsync<TicketRow>(
    'SELECT * FROM tickets WHERE event_id = ? AND token_hash = ?',
    eventId,
    tokenHash,
  );

  return row ? toTicket(row) : null;
}

export async function findByReference(
  eventId: number,
  reference: string,
  tx?: SQLiteDatabase,
): Promise<LocalTicket | null> {
  const db = tx ?? await database();

  const row = await db.getFirstAsync<TicketRow>(
    'SELECT * FROM tickets WHERE event_id = ? AND reference = ?',
    eventId,
    reference.trim().toUpperCase(),
  );

  return row ? toTicket(row) : null;
}

/**
 * Recherche manuelle, hors connexion (section 27.15).
 *
 * Par debut de reference — l'agent lit ce qui reste lisible sur un billet
 * abime, souvent les premiers caracteres — ou par fragment de nom. Les billets
 * encore attendus remontent en premier : a la porte, un billet deja passe est
 * une information, pas une reponse.
 */
export async function searchLocalTickets(
  eventId: number,
  term: string,
  limit = 20,
): Promise<LocalTicket[]> {
  const trimmed = term.trim();

  if (trimmed.length < 3) {
    return [];
  }

  const db = await database();
  const escaped = trimmed.replace(/[%_\\]/g, (match) => `\\${match}`);

  const rows = await db.getAllAsync<TicketRow>(
    `SELECT * FROM tickets
     WHERE event_id = ?
       AND (UPPER(reference) LIKE UPPER(?) ESCAPE '\\' OR UPPER(holder_name) LIKE UPPER(?) ESCAPE '\\')
     ORDER BY CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END, reference
     LIMIT ?`,
    eventId,
    `${escaped}%`,
    `%${escaped}%`,
    limit,
  );

  return rows.map(toTicket);
}

/** Marque le passage localement, pour que le second scan refuse hors connexion. */
export async function markUsedLocally(ticketId: number, usedAt: string, tx: SQLiteDatabase): Promise<void> {
  await tx.runAsync('UPDATE tickets SET status = ?, used_at = ? WHERE id = ?', 'USED', usedAt, ticketId);
}

export async function countTickets(eventId: number): Promise<number> {
  const db = await database();

  const row = await db.getFirstAsync<{ total: number }>(
    'SELECT COUNT(*) AS total FROM tickets WHERE event_id = ?',
    eventId,
  );

  return row?.total ?? 0;
}

/** Les evenements autorises, conserves pour ouvrir l'application hors connexion. */
export async function saveEvents(events: AgentEvent[]): Promise<void> {
  await writeTransaction(async (db) => {
    // Retire les affectations revoquees, sans effacer leurs scans en attente.
    await db.runAsync('DELETE FROM events');
    for (const event of events) {
      await db.runAsync(
        `INSERT INTO events (id, name, starts_at, ends_at, venue, organization, entry_point_id, entry_point_name, tickets_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (id) DO UPDATE SET
           name = excluded.name,
           starts_at = excluded.starts_at,
           ends_at = excluded.ends_at,
           venue = excluded.venue,
           organization = excluded.organization,
           entry_point_id = excluded.entry_point_id,
           entry_point_name = excluded.entry_point_name,
           tickets_count = excluded.tickets_count`,
        event.id,
        event.name,
        event.startsAt,
        event.endsAt,
        event.venue,
        event.organization,
        event.entryPoint?.id ?? null,
        event.entryPoint?.name ?? null,
        event.ticketsCount,
      );
    }
  });
}

export async function savedEvents(): Promise<AgentEvent[]> {
  const db = await database();

  const rows = await db.getAllAsync<{
    id: number;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    venue: string | null;
    organization: string | null;
    entry_point_id: number | null;
    entry_point_name: string | null;
    tickets_count: number;
  }>('SELECT * FROM events ORDER BY starts_at IS NULL, starts_at');

  return rows.map((row) => ({
    id: row.id,
    name: row.name,
    startsAt: row.starts_at,
    endsAt: row.ends_at,
    venue: row.venue,
    organization: row.organization,
    entryPoint:
      row.entry_point_id === null
        ? null
        : { id: row.entry_point_id, name: row.entry_point_name ?? '' },
    ticketsCount: row.tickets_count,
  }));
}
