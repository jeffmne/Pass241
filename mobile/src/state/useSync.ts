import { useCallback, useEffect, useRef, useState } from 'react';

import { pendingCount } from '../db/scans';
import { synchronise } from '../services/sync';
import { useNetwork } from './network';
import { useSession } from './session';

/**
 * La synchronisation vue par les ecrans (sections 27.11, 27.14).
 *
 * Automatique ET manuelle, comme le demande la section 27.11 : automatique
 * parce qu'un agent a autre chose a faire que de penser au reseau, manuelle
 * parce qu'il veut pouvoir verifier que sa file est partie avant de rendre le
 * telephone.
 *
 * TROIS DECLENCHEURS, et un seul verrou. Le retour du reseau, le rythme de
 * fond, et le bouton. Un verrou (`running`) empeche deux tours simultanes :
 * sans lui, un retour de reseau pendant un tour en cours enverrait deux fois le
 * meme lot — sans dommage grace a l'idempotence, mais pour rien.
 *
 * L'INTERVALLE EST LONG A DESSEIN. Trente secondes : assez pour que la file ne
 * s'accumule pas, assez peu pour ne pas reveiller la radio du telephone toutes
 * les cinq secondes pendant quatre heures. La batterie d'un agent doit tenir
 * toute la soiree.
 */

const INTERVAL_MS = 30_000;

/**
 * Verrou PARTAGE entre toutes les instances du hook.
 *
 * Plusieurs ecrans l'utilisent en meme temps — le scanner reste monte quand la
 * recherche s'ouvre par-dessus. Un verrou par instance laisserait deux ecrans
 * envoyer le meme lot simultanement : sans dommage, l'idempotence du serveur
 * couvre le cas, mais deux fois le meme trafic sur une connexion mobile est
 * exactement ce qu'on ne veut pas payer.
 */
const inFlight = new Set<number>();

export type SyncState = {
  pending: number;
  syncing: boolean;
  lastSyncAt: Date | null;
  /** Verdicts retournes par le serveur depuis l'ouverture (section 31.1). */
  overturned: number;
  error: string | null;
  sync: () => Promise<void>;
  refreshPending: () => Promise<void>;
};

export function useSync(eventId: number | null): SyncState {
  const { token, deviceId, clockOffset } = useSession();
  const { online } = useNetwork();

  const [pending, setPending] = useState(0);
  const [syncing, setSyncing] = useState(false);
  const [lastSyncAt, setLastSyncAt] = useState<Date | null>(null);
  const [overturned, setOverturned] = useState(0);
  const [error, setError] = useState<string | null>(null);

  const running = useRef(false);

  const refreshPending = useCallback(async () => {
    if (eventId === null) return;

    setPending(await pendingCount(eventId));
  }, [eventId]);

  const sync = useCallback(async () => {
    if (eventId === null || !token || running.current || inFlight.has(eventId)) {
      return;
    }

    running.current = true;
    inFlight.add(eventId);
    setSyncing(true);
    setError(null);

    try {
      const report = await synchronise(token, eventId, deviceId, clockOffset);

      if (!report.offline) {
        setLastSyncAt(new Date());
      }

      if (report.overturned > 0) {
        setOverturned((count) => count + report.overturned);
      }
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Synchronisation impossible.');
    } finally {
      running.current = false;
      inFlight.delete(eventId);
      setSyncing(false);
      await refreshPending();
    }
  }, [clockOffset, deviceId, eventId, refreshPending, token]);

  // Le retour du reseau est le meilleur moment pour vider la file.
  useEffect(() => {
    if (online) {
      void sync();
    }
  }, [online, sync]);

  useEffect(() => {
    void refreshPending();

    const timer = setInterval(() => {
      void sync();
    }, INTERVAL_MS);

    return () => clearInterval(timer);
  }, [refreshPending, sync]);

  return { pending, syncing, lastSyncAt, overturned, error, sync, refreshPending };
}
