import * as Crypto from 'expo-crypto';

import { decideLocally } from '../domain/validate';
import type { ScanVerdict } from '../domain/types';
import { findByReference, findByTokenHash, markUsedLocally } from '../db/tickets';
import { recordScan } from '../db/scans';

/**
 * Le controle d'un billet, du QR au verdict (sections 27.6, 27.7).
 *
 * C'est le chemin le plus emprunte de l'application : des centaines de fois par
 * soiree, souvent en quelques secondes chacun. Trois etapes, dans cet ordre, et
 * l'ordre compte :
 *
 * 1. DECIDER localement. L'agent voit un verdict immediatement, meme sans
 *    reseau. Attendre le serveur ferait dependre la file d'attente d'une
 *    connexion mobile.
 *
 * 2. ECRIRE en base AVANT toute tentative reseau. Un controle enregistre ne se
 *    perd pas si l'application est tuee ou si la batterie lache.
 *
 * 3. REMONTER plus tard, par la file. Le serveur tranchera — et pourra
 *    contredire ce verdict (section 31.1).
 *
 * L'EMPREINTE, JAMAIS LE JETON. Le QR contient un jeton signe ; l'application
 * en calcule l'empreinte SHA-256 et cherche celle-la dans sa base. Meme
 * fonction que le serveur (`Ticket::tokenHash()`), donc meme resultat, sans
 * qu'aucun secret n'ait eu besoin de descendre sur l'appareil.
 */

export async function hashToken(token: string): Promise<string> {
  const digest = await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, token);

  /*
   * Mise en minuscules explicite.
   *
   * PHP rend un hexadecimal minuscule ; la documentation d'expo-crypto promet
   * de l'hexadecimal mais ne dit rien de la casse. Une divergence ici ne
   * produirait aucune erreur — juste des billets valides declares « invalides »
   * a une porte de concert, ce qui est le pire mode de defaillance possible.
   * Une normalisation coute une fonction, le doute couterait une soiree.
   */
  return digest.toLowerCase();
}

/** Controle par lecture du QR. */
export async function controlByToken(
  eventId: number,
  token: string,
  scannedAt: Date = new Date(),
): Promise<ScanVerdict> {
  const hash = await hashToken(token);
  const ticket = await findByTokenHash(eventId, hash);
  const decision = decideLocally(ticket);

  await persist({
    eventId,
    scannedAt,
    decision,
    ticketId: ticket?.id ?? null,
    reference: ticket?.reference ?? null,
    // Conserve le temps de prouver la signature au serveur, puis efface.
    token,
    manual: false,
  });

  return decision.verdict;
}

/**
 * Controle a la reference, quand le QR ne se lit pas (section 27.15).
 *
 * Le billet doit exister dans la base locale : on n'invente pas une entree pour
 * une reference inconnue. Un billet achete apres la derniere synchronisation
 * passe, lui, par la recherche cote serveur — qui exige donc du reseau, ce qui
 * est le cas ou il y en a puisqu'on vient d'en trouver un.
 */
export async function controlByReference(
  eventId: number,
  reference: string,
  scannedAt: Date = new Date(),
): Promise<ScanVerdict> {
  const ticket = await findByReference(eventId, reference);
  const decision = decideLocally(ticket, { manual: true });

  await persist({
    eventId,
    scannedAt,
    decision,
    ticketId: ticket?.id ?? null,
    reference: ticket?.reference ?? reference.trim().toUpperCase(),
    token: null,
    manual: true,
  });

  return decision.verdict;
}

async function persist(params: {
  eventId: number;
  scannedAt: Date;
  decision: ReturnType<typeof decideLocally>;
  ticketId: number | null;
  reference: string | null;
  token: string | null;
  manual: boolean;
}): Promise<void> {
  const at = params.scannedAt.toISOString();

  await recordScan({
    eventId: params.eventId,
    ticketId: params.ticketId,
    reference: params.reference,
    token: params.token,
    result: params.decision.verdict.code,
    scannedAt: at,
    manual: params.manual,
    holderName: params.decision.verdict.holderName,
    ticketType: params.decision.verdict.ticketType,
  });

  /*
   * Le passage est marque localement des qu'il est accorde : sans cela, le
   * meme billet repasserait la meme porte tant que le reseau n'est pas revenu.
   */
  if (params.decision.marksUsed && params.ticketId !== null) {
    await markUsedLocally(params.ticketId, at);
  }
}
