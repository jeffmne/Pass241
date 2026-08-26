import type { LocalScan, ScanResultCode } from '../domain/types';
import type { ScanOutcome } from '../domain/queue';
import { database } from './database';

/**
 * La file locale des controles (sections 27.11, 27.12).
 *
 * Chaque scan y est ecrit AVANT toute tentative reseau. C'est la regle qui rend
 * le mode hors connexion utilisable : un controle enregistre localement ne se
 * perd pas si le reseau tombe, si l'application est tuee, ou si la batterie
 * lache entre deux personnes.
 */

type ScanRow = {
  id: number;
  ticket_id: number | null;
  reference: string | null;
  token: string | null;
  result: ScanResultCode;
  confirmed: number;
  scanned_at: string;
  manual: number;
  holder_name: string | null;
  ticket_type: string | null;
};

function toScan(row: ScanRow): LocalScan {
  return {
    id: row.id,
    ticketId: row.ticket_id,
    reference: row.reference,
    token: row.token,
    result: row.result,
    confirmed: row.confirmed === 1,
    scannedAt: row.scanned_at,
    manual: row.manual === 1,
    holderName: row.holder_name,
    ticketType: row.ticket_type,
  };
}

export async function recordScan(scan: {
  eventId: number;
  ticketId: number | null;
  reference: string | null;
  token: string | null;
  result: ScanResultCode;
  scannedAt: string;
  manual: boolean;
  holderName: string | null;
  ticketType: string | null;
}): Promise<number> {
  const db = await database();

  const result = await db.runAsync(
    `INSERT INTO scans (event_id, ticket_id, reference, token, result, confirmed, scanned_at, manual, holder_name, ticket_type)
     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)`,
    scan.eventId,
    scan.ticketId,
    scan.reference,
    scan.token,
    scan.result,
    scan.scannedAt,
    scan.manual ? 1 : 0,
    scan.holderName,
    scan.ticketType,
  );

  return result.lastInsertRowId;
}

/** Les controles en attente, dans l'ordre ou ils ont ete faits. */
export async function pendingScans(eventId: number, limit = 200): Promise<LocalScan[]> {
  const db = await database();

  const rows = await db.getAllAsync<ScanRow>(
    'SELECT * FROM scans WHERE event_id = ? AND confirmed = 0 ORDER BY id LIMIT ?',
    eventId,
    limit,
  );

  return rows.map(toScan);
}

export async function pendingCount(eventId: number): Promise<number> {
  const db = await database();

  const row = await db.getFirstAsync<{ total: number }>(
    'SELECT COUNT(*) AS total FROM scans WHERE event_id = ? AND confirmed = 0',
    eventId,
  );

  return row?.total ?? 0;
}

/**
 * Applique les verdicts du serveur.
 *
 * Le jeton est EFFACE en meme temps : il n'avait de raison d'etre que le temps
 * de prouver la signature au serveur. Le garder ferait trainer sur l'appareil
 * de quoi refabriquer un QR, sans qu'aucun usage ne le justifie.
 */
export async function applyOutcomes(outcomes: ScanOutcome[]): Promise<void> {
  if (outcomes.length === 0) {
    return;
  }

  const db = await database();

  await db.withTransactionAsync(async () => {
    for (const outcome of outcomes) {
      if (!outcome.confirmed || outcome.result === null) {
        continue;
      }

      await db.runAsync(
        `UPDATE scans
         SET result = ?, confirmed = 1, token = NULL, holder_name = ?, ticket_type = ?, reference = ?
         WHERE id = ?`,
        outcome.result,
        outcome.holderName,
        outcome.ticketType,
        outcome.reference,
        outcome.localId,
      );
    }
  });
}

/**
 * L'historique de l'agent (section 27.15).
 *
 * Le plus recent d'abord : c'est le debut de la liste qui l'interesse, quand
 * quelqu'un revient contester un refus trente secondes plus tard.
 */
export async function recentScans(eventId: number, limit = 50): Promise<LocalScan[]> {
  const db = await database();

  const rows = await db.getAllAsync<ScanRow>(
    'SELECT * FROM scans WHERE event_id = ? ORDER BY scanned_at DESC, id DESC LIMIT ?',
    eventId,
    limit,
  );

  return rows.map(toScan);
}

/** Compteurs locaux, affiches meme sans reseau (section 27.14). */
export async function localCounters(eventId: number): Promise<{
  scanned: number;
  valid: number;
  alreadyUsed: number;
  invalid: number;
}> {
  const db = await database();

  const rows = await db.getAllAsync<{ result: ScanResultCode; total: number }>(
    'SELECT result, COUNT(*) AS total FROM scans WHERE event_id = ? GROUP BY result',
    eventId,
  );

  const total = (code: ScanResultCode) => rows.find((row) => row.result === code)?.total ?? 0;

  return {
    scanned: rows.reduce((sum, row) => sum + row.total, 0),
    valid: total('ACCEPTED'),
    alreadyUsed: total('ALREADY_USED') + total('CONFLICT_LOST'),
    invalid: total('INVALID') + total('REFUNDED') + total('CANCELLED') + total('WRONG_EVENT'),
  };
}
