/**
 * Vocabulaire du controle d'acces, cote application.
 *
 * Les codes de resultat sont EXACTEMENT ceux du serveur (`App\Enums\ScanResult`)
 * : l'application affiche un verdict provisoire, le serveur rend le verdict
 * definitif, et les deux doivent pouvoir se comparer sans traduction. Une table
 * de correspondance entre deux vocabulaires serait le premier endroit ou les
 * deux cotes divergeraient.
 */

export type ScanResultCode =
  | 'ACCEPTED'
  | 'ALREADY_USED'
  | 'INVALID'
  | 'REFUNDED'
  | 'CANCELLED'
  | 'WRONG_EVENT'
  | 'CONFLICT_LOST';

/** Statut d'un billet, tel que le serveur le descend (`App\Enums\TicketStatus`). */
export type TicketStatus = 'ACTIVE' | 'USED' | 'REFUNDED' | 'CANCELLED' | 'EXPIRED';

/**
 * Billet dans la base locale.
 *
 * `tokenHash` et jamais le jeton : un telephone d'agent se perd a l'entree d'un
 * concert. Avec les jetons, celui qui le ramasse repart avec de quoi fabriquer
 * tous les QR de l'evenement ; avec les empreintes, il ne peut que reconnaitre
 * un billet (section 27.5).
 */
export type LocalTicket = {
  id: number;
  reference: string;
  tokenHash: string;
  holderName: string | null;
  ticketType: string | null;
  status: TicketStatus;
  /** Heure du passage, quand il a eu lieu — locale ou venue du serveur. */
  usedAt: string | null;
  updatedAt: string | null;
};

/** Controle enregistre localement, en attente ou deja confirme. */
export type LocalScan = {
  id: number;
  ticketId: number | null;
  /** Reference saisie a la main quand le QR n'a pas pu etre lu (section 27.15). */
  reference: string | null;
  /** Contenu brut du QR. Efface des que le serveur a repondu (voir `queue`). */
  token: string | null;
  /** Verdict local, remplace par celui du serveur des qu'il arrive. */
  result: ScanResultCode;
  /** Le serveur a-t-il tranche ? */
  confirmed: boolean;
  /** Horodatage LOCAL de l'appareil, au format ISO 8601. */
  scannedAt: string;
  manual: boolean;
  holderName: string | null;
  ticketType: string | null;
};

export type AgentEvent = {
  id: number;
  name: string;
  startsAt: string | null;
  endsAt: string | null;
  venue: string | null;
  organization: string | null;
  entryPoint: { id: number; name: string } | null;
  ticketsCount: number;
};

/** Ce que l'agent lit a l'ecran, a bout de bras et dans le bruit (section 28). */
export type ScanVerdict = {
  code: ScanResultCode;
  accepted: boolean;
  label: string;
  instruction: string;
  holderName: string | null;
  ticketType: string | null;
  reference: string | null;
  /** Vrai quand l'entree a ete accordee sans lecture du QR. */
  manual: boolean;
};
