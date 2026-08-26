import * as Crypto from 'expo-crypto';
import * as SecureStore from 'expo-secure-store';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { login as loginRequest, logout as logoutRequest } from '../api/agent';
import { clockOffsetSeconds } from '../domain/clock';
import { closeDatabase, wipeLocalData } from '../db/database';

/**
 * La session de l'agent (sections 27.3, 27.16).
 *
 * Le jeton vit dans SecureStore, chiffre par le systeme, et JAMAIS dans la base
 * SQLite : un telephone perdu ne doit pas livrer de quoi appeler l'API. C'est
 * la meme raison qui fait que la base locale ne contient que des empreintes.
 *
 * L'IDENTIFIANT D'APPAREIL est genere une fois et conserve. Il sert a deux
 * choses cote serveur : reconnaitre un rejeu de la file apres une coupure, et
 * retrouver quel terminal a laisse passer un billet en cas de litige. Le
 * regenerer a chaque lancement ferait perdre les deux.
 *
 * L'ECART D'HORLOGE est mesure a la connexion et conserve avec la session : la
 * regle du double scan repose sur l'horodatage de l'appareil (section 31.1), et
 * cet ecart est ce qui permet d'expliquer un arbitrage apres coup.
 */

const TOKEN_KEY = 'pass241.agent.token';
const AGENT_KEY = 'pass241.agent.identity';
const DEVICE_KEY = 'pass241.agent.device';
const CLOCK_KEY = 'pass241.agent.clock';

export type Agent = { id: number; name: string; email: string };

type SessionValue = {
  ready: boolean;
  token: string | null;
  agent: Agent | null;
  deviceId: string;
  clockOffset: number | null;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  /** Met a jour l'ecart d'horloge a chaque reponse du serveur qui le porte. */
  noteServerTime: (serverTime: string) => void;
};

const SessionContext = createContext<SessionValue | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [ready, setReady] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [agent, setAgent] = useState<Agent | null>(null);
  const [deviceId, setDeviceId] = useState<string>('');
  const [clockOffset, setClockOffset] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      const [storedToken, storedAgent, storedDevice, storedClock] = await Promise.all([
        SecureStore.getItemAsync(TOKEN_KEY),
        SecureStore.getItemAsync(AGENT_KEY),
        SecureStore.getItemAsync(DEVICE_KEY),
        SecureStore.getItemAsync(CLOCK_KEY),
      ]);

      let device = storedDevice;

      if (!device) {
        device = Crypto.randomUUID();
        await SecureStore.setItemAsync(DEVICE_KEY, device);
      }

      if (cancelled) return;

      setToken(storedToken);
      setAgent(storedAgent ? (JSON.parse(storedAgent) as Agent) : null);
      setDeviceId(device);
      setClockOffset(storedClock === null ? null : Number(storedClock));
      setReady(true);
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const noteServerTime = useCallback((serverTime: string) => {
    const offset = clockOffsetSeconds(serverTime);

    setClockOffset(offset);
    void SecureStore.setItemAsync(CLOCK_KEY, String(offset));
  }, []);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const response = await loginRequest(email.trim(), password, deviceId);

      await Promise.all([
        SecureStore.setItemAsync(TOKEN_KEY, response.token),
        SecureStore.setItemAsync(AGENT_KEY, JSON.stringify(response.agent)),
      ]);

      setToken(response.token);
      setAgent(response.agent);
      noteServerTime(response.server_time);
    },
    [deviceId, noteServerTime],
  );

  /**
   * Deconnexion : le jeton est revoque cote serveur ET les donnees locales sont
   * effacees. Un telephone rendu en fin de soiree ne garde pas la liste des
   * participants (section 27.16).
   *
   * La revocation reseau peut echouer — reseau absent, jeton deja expire — et
   * cela ne doit pas empecher l'effacement local, qui est la partie que l'on
   * maitrise.
   */
  const signOut = useCallback(async () => {
    if (token) {
      try {
        await logoutRequest(token);
      } catch {
        // Sans importance : le jeton local part de toute facon.
      }
    }

    await Promise.all([
      SecureStore.deleteItemAsync(TOKEN_KEY),
      SecureStore.deleteItemAsync(AGENT_KEY),
    ]);

    await wipeLocalData();
    await closeDatabase();

    setToken(null);
    setAgent(null);
  }, [token]);

  const value = useMemo<SessionValue>(
    () => ({ ready, token, agent, deviceId, clockOffset, signIn, signOut, noteServerTime }),
    [ready, token, agent, deviceId, clockOffset, signIn, signOut, noteServerTime],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);

  if (!value) {
    throw new Error('useSession doit etre utilise dans un SessionProvider.');
  }

  return value;
}
