import type { LocalScan, ScanResultCode } from './types';

/**
 * La file de synchronisation (sections 27.11, 27.12).
 *
 * Logique PURE : decouper la file en lots, la serialiser, et appliquer les
 * verdicts du serveur. Tout ce qui touche au reseau ou a la base vit ailleurs —
 * c'est ici que se joue la justesse, et c'est ici que les tests portent.
 *
 * TROIS REGLES, chacune apprise d'une vraie entree :
 *
 * 1. ON ENVOIE PAR LOTS. Une porte hors connexion accumule des dizaines de
 *    controles ; un appel par scan multiplierait les allers-retours au moment
 *    ou le reseau est le plus mauvais.
 *
 * 2. ON REJOUE SANS CRAINDRE LE DOUBLON. Le serveur est idempotent sur
 *    (appareil, billet, horodatage local) : une synchronisation interrompue se
 *    retransmet en entier plutot que de deviner ce qui est passe.
 *
 * 3. LE SERVEUR TRANCHE. Le verdict local etait provisoire ; celui qui revient
 *    est definitif, meme quand il contredit ce que l'agent a lu (section 31.1).
 *    L'application remplace, elle ne discute pas.
 */

/** Meme plafond que le serveur : au-dela, le lot est refuse. */
export const MAX_BATCH = 200;

export type ScanPayload = {
  token?: string;
  reference?: string;
  scanned_at: string;
};

export type ServerScanResult = {
  index: number;
  scan_id?: number;
  accepted?: boolean;
  result?: ScanResultCode | null;
  manual?: boolean;
  holder_name?: string | null;
  ticket_type?: string | null;
  reference?: string | null;
  conflict_with?: number | null;
  error?: string;
};

/** Ce qu'il faut ecrire en base apres la reponse du serveur. */
export type ScanOutcome = {
  localId: number;
  /** Verdict definitif, ou null quand le serveur n'a pas pu enregistrer. */
  result: ScanResultCode | null;
  confirmed: boolean;
  holderName: string | null;
  ticketType: string | null;
  reference: string | null;
};

export function nextBatch(pending: LocalScan[]): LocalScan[] {
  return pending.slice(0, MAX_BATCH);
}

/**
 * Serialise un controle pour l'API.
 *
 * Le jeton s'il a ete lu, la reference sinon (section 27.15). Jamais les deux :
 * le serveur privilegie le jeton, et joindre une reference a un QR parfaitement
 * lisible ferait passer une entree scannee pour une admission manuelle.
 */
export function serialiseScan(scan: LocalScan): ScanPayload {
  if (scan.token) {
    return { token: scan.token, scanned_at: scan.scannedAt };
  }

  return { reference: scan.reference ?? '', scanned_at: scan.scannedAt };
}

export function serialiseBatch(scans: LocalScan[]): ScanPayload[] {
  return scans.map(serialiseScan);
}

/**
 * Rapproche les verdicts du serveur des lignes locales.
 *
 * L'appariement se fait par INDICE, comme le prevoit l'API : le serveur renvoie
 * un resultat par element envoye, dans l'ordre. Un resultat sans indice
 * correspondant est ignore plutot que devine — appliquer un verdict a la
 * mauvaise ligne serait pire que ne rien appliquer.
 *
 * Un element en echec cote serveur (`error`) reste NON confirme : il repartira
 * au prochain envoi. C'est la difference entre une ligne perdue et une ligne
 * retentee.
 */
export function matchResults(sent: LocalScan[], results: ServerScanResult[]): ScanOutcome[] {
  const outcomes: ScanOutcome[] = [];

  for (const result of results) {
    const scan = sent[result.index];

    if (!scan) {
      continue;
    }

    if (result.error || !result.result) {
      outcomes.push({
        localId: scan.id,
        result: null,
        confirmed: false,
        holderName: scan.holderName,
        ticketType: scan.ticketType,
        reference: scan.reference,
      });

      continue;
    }

    outcomes.push({
      localId: scan.id,
      result: result.result,
      confirmed: true,
      // Le serveur connait le porteur meme quand l'application ne l'avait pas
      // en base — un billet achete apres la derniere synchronisation.
      holderName: result.holder_name ?? scan.holderName,
      ticketType: result.ticket_type ?? scan.ticketType,
      reference: result.reference ?? scan.reference,
    });
  }

  return outcomes;
}

/**
 * Un verdict a-t-il ete retourne par le serveur ?
 *
 * Sert a prevenir l'agent : il a lu « entree autorisee », et l'arbitrage du
 * double scan a fait perdre son controle. Le lui cacher le laisserait croire
 * que quelqu'un est entre alors que le journal dit le contraire.
 */
export function verdictChanged(local: ScanResultCode, server: ScanResultCode): boolean {
  return local !== server;
}
