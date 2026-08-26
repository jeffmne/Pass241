import type { LocalTicket, ScanResultCode, ScanVerdict } from './types';
import { allowsEntry, instructionFor, labelFor } from './verdict';

/**
 * La decision locale, hors connexion (section 27.7).
 *
 * Fonction PURE, volontairement : c'est le seul endroit de l'application ou une
 * erreur laisse entrer quelqu'un qui ne devrait pas, ou laisse dehors quelqu'un
 * qui a paye. Elle ne touche ni la base, ni le reseau, ni l'horloge — tout lui
 * est donne, et elle se teste entierement.
 *
 * ELLE REPRODUIT L'ORDRE DES REFUS DU SERVEUR (`App\Actions\Access\
 * ValidateTicket`). L'ordre compte : un billet rembourse ET deja utilise doit
 * dire « annule » des deux cotes, sinon l'agent lit une chose et le journal en
 * garde une autre.
 *
 * DEUX ECARTS CONNUS, tous deux inoffensifs pour l'agent :
 *
 * 1. Un billet d'un AUTRE evenement est absent de la base locale : la decision
 *    locale dit « invalide » la ou le serveur dira « autre evenement ». Les deux
 *    refusent l'entree, donc le geste de l'agent est le meme ; l'historique se
 *    corrige a la synchronisation.
 *
 * 2. Un double scan simultane a deux portes n'est pas arbitrable ici — aucun
 *    telephone ne sait ce que fait l'autre. Les deux agents voient « entree
 *    autorisee », et le serveur declasse le plus recent (section 31.1). C'est
 *    exactement le compromis que le mode hors connexion impose : mieux vaut
 *    laisser passer deux fois dans un cas rare que bloquer une file entiere
 *    des que le reseau tombe.
 */

export type LocalDecision = {
  verdict: ScanVerdict;
  /** Le billet doit-il passer a « utilise » dans la base locale ? */
  marksUsed: boolean;
};

export function decideLocally(
  ticket: LocalTicket | null,
  options: { manual?: boolean } = {},
): LocalDecision {
  const manual = options.manual ?? false;

  if (ticket === null) {
    return decision('INVALID', null, manual, false);
  }

  switch (ticket.status) {
    case 'REFUNDED':
      return decision('REFUNDED', ticket, manual, false);

    case 'CANCELLED':
    case 'EXPIRED':
      return decision('CANCELLED', ticket, manual, false);

    case 'USED':
      return decision('ALREADY_USED', ticket, manual, false);

    default:
      return decision('ACCEPTED', ticket, manual, true);
  }
}

function decision(
  code: ScanResultCode,
  ticket: LocalTicket | null,
  manual: boolean,
  marksUsed: boolean,
): LocalDecision {
  return {
    marksUsed,
    verdict: {
      code,
      accepted: allowsEntry(code),
      label: labelFor(code),
      instruction: instructionFor(code),
      holderName: ticket?.holderName ?? null,
      ticketType: ticket?.ticketType ?? null,
      reference: ticket?.reference ?? null,
      manual,
    },
  };
}
