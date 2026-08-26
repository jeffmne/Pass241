/**
 * Ecart entre l'horloge de l'appareil et celle du serveur (section 31.1).
 *
 * L'arbitrage du double scan fait gagner l'horodatage LOCAL le plus ancien. La
 * regle suppose donc une horloge fiable — et un telephone d'agent ne l'est pas
 * toujours : batterie retiree, fuseau mal regle, appareil emprunte.
 *
 * L'application ne corrige RIEN. Elle mesure l'ecart a chaque echange avec le
 * serveur et le remonte avec ses scans. Corriger l'heure a la volee donnerait
 * des horodatages inventes, impossibles a defendre six mois plus tard ; mesurer
 * l'ecart permet de demontrer apres coup pourquoi tel scan a gagne.
 *
 * L'avertissement affiche a l'agent, lui, sert a autre chose : un appareil
 * deregle de plusieurs minutes va perdre des arbitrages qu'il aurait du gagner.
 * Autant que quelqu'un puisse regler l'heure avant l'ouverture des portes.
 */

/** Au-dela, l'ecart est signale a l'agent. Meme tolerance que le serveur. */
export const CLOCK_TOLERANCE_SECONDS = 120;

export function clockOffsetSeconds(serverTime: string, deviceNow: Date = new Date()): number {
  const server = new Date(serverTime).getTime();

  if (Number.isNaN(server)) {
    return 0;
  }

  // Positif : l'appareil est en AVANCE sur le serveur.
  return Math.round((deviceNow.getTime() - server) / 1000);
}

export function isClockSuspicious(offsetSeconds: number | null): boolean {
  return offsetSeconds !== null && Math.abs(offsetSeconds) > CLOCK_TOLERANCE_SECONDS;
}

/** Message court, lisible dans le bandeau d'etat. */
export function clockWarning(offsetSeconds: number | null): string | null {
  if (!isClockSuspicious(offsetSeconds)) {
    return null;
  }

  const minutes = Math.round(Math.abs(offsetSeconds as number) / 60);
  const sens = (offsetSeconds as number) > 0 ? 'avance' : 'retard';

  return `L'horloge de cet appareil a ${minutes} min de ${sens}. Reglez-la avant l'ouverture.`;
}
