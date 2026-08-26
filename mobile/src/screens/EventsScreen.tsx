import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';

import { fetchEvents } from '../api/agent';
import { NetworkError } from '../api/client';
import { Button, EmptyNote, ErrorNote } from '../components/ui';
import type { AgentEvent } from '../domain/types';
import { countTickets, savedEvents, saveEvents } from '../db/tickets';
import { downloadTickets } from '../services/sync';
import { useSession } from '../state/session';
import { colors, radius, spacing, typography } from '../theme';
import type { RootStackParamList } from '../navigation';

/**
 * Les evenements autorises (sections 27.4, 30).
 *
 * « Un agent ne voit que les evenements auxquels il est affecte » : la liste
 * vient du serveur, jamais d'un choix dans l'application. Le point d'entree
 * affiche est celui de l'AFFECTATION — c'est l'organisateur qui repartit ses
 * agents, pas l'agent qui se place.
 *
 * LA LISTE EST CONSERVEE localement. Une application ouverte sans reseau au
 * pied d'une porte doit pouvoir entrer dans son evenement : elle affiche ce
 * qu'elle sait, avec le nombre de billets deja telecharges.
 *
 * Le telechargement des billets est explicite et montre sa progression. Sur un
 * evenement de plusieurs milliers de billets, une barre qui avance est la
 * difference entre « ca charge » et « c'est bloque ».
 */

type Props = NativeStackScreenProps<RootStackParamList, 'Events'>;

export function EventsScreen({ navigation }: Props) {
  const { token, agent, signOut } = useSession();

  const [events, setEvents] = useState<AgentEvent[]>([]);
  const [counts, setCounts] = useState<Record<number, number>>({});
  const [loading, setLoading] = useState(true);
  const [preparing, setPreparing] = useState<number | null>(null);
  const [progress, setProgress] = useState(0);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    // La base locale d'abord : l'ecran s'affiche meme si le reseau ne repond
    // pas, ce qui est le cas normal a l'entree d'un site.
    const stored = await savedEvents();
    setEvents(stored);
    await refreshCounts(stored, setCounts);

    if (!token) {
      setLoading(false);

      return;
    }

    try {
      const response = await fetchEvents(token);

      await saveEvents(response.events);
      setEvents(response.events);
      await refreshCounts(response.events, setCounts);
    } catch (caught) {
      if (!(caught instanceof NetworkError)) {
        setError(caught instanceof Error ? caught.message : 'Chargement impossible.');
      }
    } finally {
      setLoading(false);
    }
  }, [token]);

  useEffect(() => {
    void load();
  }, [load]);

  async function open(event: AgentEvent) {
    if (!token) return;

    setPreparing(event.id);
    setProgress(0);
    setError(null);

    try {
      await downloadTickets(token, event.id, setProgress);
    } catch (caught) {
      /*
       * Un telechargement qui echoue n'empeche pas d'entrer dans l'evenement :
       * ce qui est deja en base reste utilisable, et la synchronisation
       * reessaiera. Interdire l'acces laisserait l'agent dehors alors qu'il a
       * peut-etre 90 % des billets.
       */
      if (!(caught instanceof NetworkError)) {
        setError(caught instanceof Error ? caught.message : 'Telechargement incomplet.');
      }
    } finally {
      setPreparing(null);
    }

    navigation.navigate('Scan', { eventId: event.id, eventName: event.name });
  }

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <View style={styles.headerText}>
          <Text style={styles.title}>Vos evenements</Text>
          {agent ? <Text style={styles.agent}>{agent.name}</Text> : null}
        </View>
        <Button label="Deconnexion" variant="ghost" onPress={() => void signOut()} />
      </View>

      <ErrorNote message={error} />

      <FlatList
        data={events}
        keyExtractor={(event) => String(event.id)}
        contentContainerStyle={styles.list}
        refreshing={loading}
        onRefresh={() => void load()}
        ListEmptyComponent={
          loading ? null : (
            <EmptyNote
              title="Aucun evenement"
              detail="Votre organisateur doit vous affecter a un evenement depuis la plateforme."
            />
          )
        }
        renderItem={({ item }) => (
          <Pressable
            style={styles.card}
            onPress={() => void open(item)}
            accessibilityRole="button"
            accessibilityLabel={`Ouvrir ${item.name}`}
          >
            <Text style={styles.eventName}>{item.name}</Text>

            <Text style={styles.meta}>
              {[formatDate(item.startsAt), item.venue].filter(Boolean).join(' · ')}
            </Text>

            <Text style={styles.meta}>
              {item.entryPoint ? item.entryPoint.name : 'Toutes les entrees'}
            </Text>

            <Text style={styles.tickets}>
              {preparing === item.id
                ? `Telechargement — ${progress} billets`
                : `${counts[item.id] ?? 0} billet${(counts[item.id] ?? 0) > 1 ? 's' : ''} en local sur ${item.ticketsCount}`}
            </Text>
          </Pressable>
        )}
      />
    </View>
  );
}

async function refreshCounts(
  events: AgentEvent[],
  apply: (counts: Record<number, number>) => void,
): Promise<void> {
  const entries = await Promise.all(
    events.map(async (event) => [event.id, await countTickets(event.id)] as const),
  );

  apply(Object.fromEntries(entries));
}

function formatDate(value: string | null): string {
  if (!value) return '';

  return new Date(value).toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
  });
}

const styles = StyleSheet.create({
  container: { backgroundColor: colors.canvas, flex: 1 },
  header: {
    alignItems: 'center',
    flexDirection: 'row',
    gap: spacing.md,
    padding: spacing.md,
  },
  headerText: { flex: 1 },
  title: { color: colors.ink, fontSize: typography.title, fontWeight: '800' },
  agent: { color: colors.grey500, fontSize: typography.small },
  list: { gap: spacing.sm, padding: spacing.md },
  card: {
    backgroundColor: colors.white,
    borderColor: colors.grey200,
    borderRadius: radius.md,
    borderWidth: 1,
    gap: spacing.xs,
    padding: spacing.md,
  },
  eventName: { color: colors.ink, fontSize: typography.body, fontWeight: '700' },
  meta: { color: colors.grey500, fontSize: typography.small },
  tickets: { color: colors.azure700, fontSize: typography.small, fontWeight: '600' },
});
