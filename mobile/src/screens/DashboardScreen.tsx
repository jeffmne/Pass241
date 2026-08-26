import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';

import { fetchDashboard, type DashboardResponse } from '../api/agent';
import { NetworkError } from '../api/client';
import { ErrorNote } from '../components/ui';
import { StatusStrip } from '../components/StatusStrip';
import { localCounters } from '../db/scans';
import { useSession } from '../state/session';
import { useSync } from '../state/useSync';
import { colors, radius, spacing, typography } from '../theme';
import type { RootStackParamList } from '../navigation';

/**
 * Les compteurs (section 27.14).
 *
 * DEUX SOURCES, ET C'EST VOULU :
 *
 * - LES MIENS viennent de la base locale. Ils s'affichent toujours, meme sans
 *   reseau, et comptent ce que CET agent a fait sur CET appareil.
 *
 * - L'ENTREE vient du serveur. C'est la seule chose que le telephone ne peut
 *   pas savoir : ce que font les autres portes. A une entree tenue par quatre
 *   agents, « combien de personnes sont entrees » n'a de reponse que la-bas.
 *
 * Quand le serveur est injoignable, la moitie haute disparait et la moitie
 * basse reste. Un ecran a moitie rempli dit la verite ; un ecran qui bloque
 * jusqu'a la reponse du serveur ne sert a rien a une porte sans reseau.
 */

type Props = NativeStackScreenProps<RootStackParamList, 'Dashboard'>;

type Local = Awaited<ReturnType<typeof localCounters>>;

export function DashboardScreen({ route }: Props) {
  const { eventId } = route.params;
  const { token, noteServerTime } = useSession();
  const sync = useSync(eventId);

  const [remote, setRemote] = useState<DashboardResponse | null>(null);
  const [local, setLocal] = useState<Local | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    setLocal(await localCounters(eventId));

    if (!token) {
      setLoading(false);

      return;
    }

    try {
      const response = await fetchDashboard(token, eventId);

      setRemote(response);
      // Chaque reponse porte l'heure du serveur : c'est l'occasion de
      // remesurer l'ecart d'horloge sans requete supplementaire.
      noteServerTime(response.server_time);
    } catch (caught) {
      if (!(caught instanceof NetworkError)) {
        setError(caught instanceof Error ? caught.message : 'Compteurs indisponibles.');
      }
    } finally {
      setLoading(false);
    }
  }, [eventId, noteServerTime, token]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <View style={styles.container}>
      <StatusStrip sync={sync} />

      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => void load()} />}
      >
        <ErrorNote message={error} />

        {remote ? (
          <View style={styles.block}>
            <Text style={styles.blockTitle}>Toute l'entree</Text>

            <View style={styles.row}>
              <Stat label="Entres" value={remote.tickets.admitted} tone={colors.success} />
              <Stat label="Attendus" value={remote.tickets.expected} />
              <Stat label="Restants" value={remote.tickets.remaining} />
            </View>

            <View style={styles.row}>
              <Stat label="Controles" value={remote.counters.scanned} />
              <Stat label="Deja utilises" value={remote.counters.already_used} tone={colors.warning} />
              <Stat label="Invalides" value={remote.counters.invalid} tone={colors.danger} />
            </View>
          </View>
        ) : (
          <Text style={styles.offline}>
            Compteurs de l'entree indisponibles hors connexion. Vos propres
            controles restent comptes ci-dessous.
          </Text>
        )}

        {local ? (
          <View style={styles.block}>
            <Text style={styles.blockTitle}>Vos controles sur cet appareil</Text>

            <View style={styles.row}>
              <Stat label="Scannes" value={local.scanned} />
              <Stat label="Valides" value={local.valid} tone={colors.success} />
              <Stat label="Refuses" value={local.alreadyUsed + local.invalid} tone={colors.danger} />
            </View>

            <Text style={styles.pending}>
              {sync.pending > 0
                ? `${sync.pending} controle${sync.pending > 1 ? 's' : ''} en attente de synchronisation`
                : 'Tous vos controles sont remontes'}
            </Text>
          </View>
        ) : null}
      </ScrollView>
    </View>
  );
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <View style={styles.stat}>
      <Text style={[styles.statValue, tone ? { color: tone } : null]}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { backgroundColor: colors.canvas, flex: 1 },
  content: { gap: spacing.md, padding: spacing.md },
  block: {
    backgroundColor: colors.white,
    borderColor: colors.grey200,
    borderRadius: radius.md,
    borderWidth: 1,
    gap: spacing.sm,
    padding: spacing.md,
  },
  blockTitle: { color: colors.grey500, fontSize: typography.small, fontWeight: '700', textTransform: 'uppercase' },
  row: { flexDirection: 'row', gap: spacing.sm },
  stat: { alignItems: 'center', flex: 1, gap: 2 },
  statValue: { color: colors.ink, fontSize: 26, fontWeight: '800' },
  statLabel: { color: colors.grey500, fontSize: typography.small, textAlign: 'center' },
  pending: { color: colors.grey500, fontSize: typography.small, textAlign: 'center' },
  offline: { color: colors.grey500, fontSize: typography.small, paddingHorizontal: spacing.sm },
});
