import type { ScanResultCode } from './types';

/**
 * Les deux lignes que l'agent lit sur son telephone (section 28).
 *
 * Copie fidele des libelles du serveur (`App\Enums\ScanResult`). Ils sont
 * dupliques ici pour une seule raison : hors connexion, il n'y a personne pour
 * les fournir. La duplication est donc assumee, et un test verrouille la liste
 * — si le serveur ajoute un code, l'application doit cesser de compiler plutot
 * que d'afficher un ecran vide a une porte de concert.
 *
 * Les libelles sont courts, sans jargon, et la decision — entree autorisee ou
 * refusee — se lit avant le detail. L'agent doit comprendre en moins d'une
 * seconde (section 27.8).
 */

const LABELS: Record<ScanResultCode, string> = {
  ACCEPTED: 'Billet valide',
  ALREADY_USED: 'Billet deja utilise',
  INVALID: 'Billet invalide',
  REFUNDED: 'Billet annule',
  CANCELLED: 'Billet annule',
  WRONG_EVENT: 'Autre evenement',
  CONFLICT_LOST: 'Billet deja utilise',
};

const INSTRUCTIONS: Record<ScanResultCode, string> = {
  ACCEPTED: 'Entree autorisee',
  ALREADY_USED: 'Entree refusee — ce billet est deja passe',
  CONFLICT_LOST: 'Entree refusee — ce billet est deja passe',
  INVALID: 'Entree refusee — QR Code non reconnu',
  REFUNDED: 'Entree refusee — billet annule',
  CANCELLED: 'Entree refusee — billet annule',
  WRONG_EVENT: 'Entree refusee — billet emis pour un autre evenement',
};

export function labelFor(code: ScanResultCode): string {
  return LABELS[code];
}

export function instructionFor(code: ScanResultCode): string {
  return INSTRUCTIONS[code];
}

export function allowsEntry(code: ScanResultCode): boolean {
  return code === 'ACCEPTED';
}

/**
 * Couleur du verdict.
 *
 * Trois etats et non deux : « deja utilise » n'est pas un refus comme un autre.
 * Le billet est authentique, quelqu'un est deja entre avec — l'agent doit aller
 * chercher le vrai porteur, pas signaler une contrefacon (section 27.8).
 */
export type VerdictTone = 'valid' | 'used' | 'invalid';

export function toneFor(code: ScanResultCode): VerdictTone {
  if (code === 'ACCEPTED') return 'valid';
  if (code === 'ALREADY_USED' || code === 'CONFLICT_LOST') return 'used';

  return 'invalid';
}
