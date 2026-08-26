import NetInfo from '@react-native-community/netinfo';
import React, { createContext, useContext, useEffect, useState } from 'react';

/**
 * L'etat du reseau (section 27.9).
 *
 * Affiche en permanence, parce que l'agent doit savoir dans quel monde il est :
 * un controle hors connexion est valable, mais son verdict est provisoire
 * jusqu'a la synchronisation (section 31.1).
 *
 * `isInternetReachable` et non `isConnected` : un telephone accroche a un Wi-Fi
 * de salle qui ne route rien est « connecte » et pourtant inutilisable. La
 * nuance change ce que l'agent voit, donc ce qu'il attend.
 *
 * LE CHANGEMENT D'ETAT N'INTERROMPT JAMAIS UN CONTROLE EN COURS. Rien ici ne
 * declenche de navigation ni de blocage : c'est un indicateur, pas un aiguillage.
 */

type NetworkValue = {
  online: boolean;
  /** Vrai tant qu'on ne sait pas encore — evite un rouge trompeur au demarrage. */
  unknown: boolean;
};

const NetworkContext = createContext<NetworkValue>({ online: false, unknown: true });

export function NetworkProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<NetworkValue>({ online: false, unknown: true });

  useEffect(() => {
    const unsubscribe = NetInfo.addEventListener((status) => {
      const reachable = status.isInternetReachable;

      setState({
        online: status.isConnected === true && reachable !== false,
        unknown: reachable === null && status.isConnected === null,
      });
    });

    return unsubscribe;
  }, []);

  return <NetworkContext.Provider value={state}>{children}</NetworkContext.Provider>;
}

export function useNetwork(): NetworkValue {
  return useContext(NetworkContext);
}
