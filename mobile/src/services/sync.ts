import { fetchTickets, pushScans } from '../api/agent';
import { NetworkError } from '../api/client';
import { matchResults, nextBatch, serialiseBatch } from '../domain/queue';
import { readMeta, writeMeta } from '../db/database';
import { applyOutcomes, pendingScans } from '../db/scans';
import { upsertTickets } from '../db/tickets';

/**
 * La synchronisation (section 27.11).
 *
 * Deux sens, deux logiques :
 *
 * DESCENDANT — les billets. Par curseur et par `since` : une porte qui se
 * reconnecte au milieu de la soiree ne retelecharge pas tout. Sans cela chaque
 * reconnexion couterait plusieurs mega-octets sur une connexion mobile.
 *
 * MONTANT — les controles. Par lots, rejouables, et sans jamais bloquer sur un
 * element : l'idempotence du serveur permet de retransmettre plutot que de
 * deviner ce qui est passe.
 *
 * AUCUNE PERTE N'EST TOLEREE DANS CE SENS. Un controle deja effectue vaut
 * mieux remonte deux fois que pas du tout : le serveur dedoublonne, et
 * l'application prefere reessayer indefiniment plutot que d'oublier une
 * personne entree.
 */

export type SyncReport = {
  downloaded: number;
  uploaded: number;
  /** Verdicts que le serveur a contredits (section 31.1). */
  overturned: number;
  offline: boolean;
};

const sinceKey = (eventId: number) => `tickets.since.${eventId}`;

/**
 * Telecharge les billets modifies depuis la derniere fois.
 *
 * L'horodatage de reference n'est ecrit qu'a la FIN, une fois toutes les pages
 * recues : le mettre a jour page par page ferait sauter les billets restants
 * si le reseau tombait au milieu.
 */
export async function downloadTickets(
  token: string,
  eventId: number,
  onProgress?: (received: number) => void,
): Promise<number> {
  const since = (await readMeta(sinceKey(eventId))) ?? undefined;

  let cursor: number | undefined;
  let received = 0;
  let syncedAt: string | null = null;

  for (;;) {
    const page = await fetchTickets(token, eventId, { since, cursor });

    await upsertTickets(eventId, page.tickets);

    received += page.tickets.length;
    syncedAt = page.synced_at;
    onProgress?.(received);

    if (!page.has_more || page.next_cursor === null) {
      break;
    }

    cursor = page.next_cursor;
  }

  if (syncedAt) {
    await writeMeta(sinceKey(eventId), syncedAt);
  }

  return received;
}

/**
 * Remonte la file, lot par lot, jusqu'a l'epuiser.
 *
 * Un lot dont le serveur n'a pas pu enregistrer certains elements les laisse
 * non confirmes : ils repartiront au prochain passage. La boucle s'arrete des
 * qu'un tour n'a rien confirme, sinon un element durablement en echec ferait
 * tourner l'application en rond au lieu de rendre la main a l'agent.
 */
export async function uploadPending(
  token: string,
  eventId: number,
  deviceId: string,
  clockOffsetSeconds: number | null,
): Promise<{ uploaded: number; overturned: number }> {
  let uploaded = 0;
  let overturned = 0;

  for (;;) {
    const pending = await pendingScans(eventId);

    if (pending.length === 0) {
      break;
    }

    const batch = nextBatch(pending);
    const response = await pushScans(
      token,
      eventId,
      deviceId,
      serialiseBatch(batch),
      clockOffsetSeconds,
    );

    const outcomes = matchResults(batch, response.results);
    const confirmed = outcomes.filter((outcome) => outcome.confirmed);

    for (const outcome of confirmed) {
      const local = batch.find((scan) => scan.id === outcome.localId);

      if (local && outcome.result && local.result !== outcome.result) {
        overturned += 1;
      }
    }

    await applyOutcomes(outcomes);

    uploaded += confirmed.length;

    if (confirmed.length === 0) {
      // Rien n'avance : inutile de marteler le serveur.
      break;
    }
  }

  return { uploaded, overturned };
}

/**
 * Un tour complet.
 *
 * Les controles PARTENT AVANT que les billets n'arrivent. L'ordre n'est pas
 * anodin : si la connexion ne dure que quelques secondes, ce qu'on veut sauver
 * en priorite, ce sont les passages deja effectues — les billets, eux, se
 * retelechargent.
 */
export async function synchronise(
  token: string,
  eventId: number,
  deviceId: string,
  clockOffsetSeconds: number | null,
): Promise<SyncReport> {
  try {
    const { uploaded, overturned } = await uploadPending(token, eventId, deviceId, clockOffsetSeconds);
    const downloaded = await downloadTickets(token, eventId);

    return { uploaded, overturned, downloaded, offline: false };
  } catch (error) {
    if (error instanceof NetworkError) {
      // Hors connexion : ce n'est pas une erreur a montrer, c'est un etat
      // normal a une porte de concert (section 27.9).
      return { uploaded: 0, overturned: 0, downloaded: 0, offline: true };
    }

    throw error;
  }
}
