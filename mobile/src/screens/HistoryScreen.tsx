import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import { FlatList, StyleSheet, Text, View } from 'react-native';

import { fetchHistory } from '../api/agent';
import { NetworkError } from '../api/client';
import { EmptyNote, ErrorNote } from '../components/ui';
import type { LocalScan } from '../domain/types';
import { labelFor, toneFor } from '../domain/verdict';
import { recentScans } from '../db/scans';
import { useSession } from '../state/session';
import { colors, radius, spacing, typography, verdictColors } from '../theme';
import type { RootStackParamList } from '../navigation';

/**
 * L'historique des controles (section 27.15).
 *
 * Lu depuis la BASE LOCALE, jamais du reseau : c'est l'ecran qu'on ouvre quand
 * quelqu'un revient contester un refus trente secondes plus tard, et il doit
 * s'afficher instantanement, hors connexion comprise.
 *
 * DEUX MARQUES QUI COMPTENT :
 *
 * - « en attente » : le verdict est encore provisoire, le serveur ne l'a pas
 *   confirme. C'est la difference entre « cette personne est entree » et
 *   « cette personne est entree, et c'est enregistre » ;
 * - « sans QR » : l'entree a ete accordee a la reference. La verification etait
 *   plus faible, et l'agent doit pouvoir le retrouver.
 */

type Props = NativeStackScreenProps<RootStackParamList, 'History'>;

export function HistoryScreen({ route }: Props) {
  const { eventId } = route.params;
  const { token } = useSession();

  const [scans, setScans] = useState<LocalScan[]>([]);
  const [loading, setLoading] = useState(true);
  const [fromServer, setFromServer] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    const local = await recentScans(eventId);

    setScans(local);
    setFromServer(false);

    /*
     * Base locale vide : c'est une application fraichement installee, ou un
     * telephone remplace en pleine soiree. Le serveur garde l'historique de
     * CET agent (section 27.15) — le lui redonner evite de le laisser
     * travailler a l'aveugle apres un incident.
     */
    if (local.length === 0 && token) {
      try {
        const response = await fetchHistory(token, eventId);

        setScans(
          response.scans.map((scan) => ({
            id: scan.scan_id,
            ticketId: null,
            reference: scan.reference,
            token: null,
            result: scan.result,
            confirmed: true,
            scannedAt: scan.scanned_at ?? new Date().toISOString(),
            manual: scan.manual,
            holderName: scan.holder_name,
            ticketType: scan.ticket_type,
          })),
        );
        setFromServer(true);
      } catch (caught) {
        if (!(caught instanceof NetworkError)) {
          setError(caught instanceof Error ? caught.message : 'Historique indisponible.');
        }
      }
    }

    setLoading(false);
  }, [eventId, token]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <FlatList
      style={styles.container}
      contentContainerStyle={styles.list}
      data={scans}
      keyExtractor={(scan) => String(scan.id)}
      refreshing={loading}
      onRefresh={() => void load()}
      ListHeaderComponent={
        <View style={styles.header}>
          <ErrorNote message={error} />
          {fromServer ? (
            <Text style={styles.source}>
              Historique repris du serveur : cet appareil n'a pas encore de
              controle en local.
            </Text>
          ) : null}
        </View>
      }
      ListEmptyComponent={
        loading ? null : (
          <EmptyNote
            title="Aucun controle"
            detail="Les billets scannes apparaitront ici, meme sans reseau."
          />
        )
      }
      renderItem={({ item }) => (
        <View style={styles.row}>
          <View style={[styles.tone, { backgroundColor: verdictColors[toneFor(item.result)] }]} />

          <View style={styles.body}>
            <Text style={styles.name}>{item.holderName ?? 'Billet inconnu'}</Text>
            <Text style={styles.detail}>
              {[labelFor(item.result), item.ticketType, item.reference]
                .filter(Boolean)
                .join(' · ')}
            </Text>

            <View style={styles.tags}>
              {!item.confirmed ? <Tag label="En attente" tone={colors.warning} /> : null}
              {item.manual ? <Tag label="Sans QR" tone={colors.azure600} /> : null}
            </View>
          </View>

          <Text style={styles.time}>{formatTime(item.scannedAt)}</Text>
        </View>
      )}
    />
  );
}

function Tag({ label, tone }: { label: string; tone: string }) {
  return (
    <View style={[styles.tag, { borderColor: tone }]}>
      <Text style={[styles.tagLabel, { color: tone }]}>{label}</Text>
    </View>
  );
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  container: { backgroundColor: colors.canvas, flex: 1 },
  list: { gap: spacing.sm, padding: spacing.md },
  header: { gap: spacing.sm },
  source: { color: colors.grey500, fontSize: typography.small },
  row: {
    alignItems: 'center',
    backgroundColor: colors.white,
    borderColor: colors.grey200,
    borderRadius: radius.md,
    borderWidth: 1,
    flexDirection: 'row',
    gap: spacing.md,
    padding: spacing.md,
  },
  tone: { borderRadius: radius.pill, height: 40, width: 6 },
  body: { flex: 1, gap: 2 },
  name: { color: colors.ink, fontSize: typography.body, fontWeight: '700' },
  detail: { color: colors.grey500, fontSize: typography.small },
  tags: { flexDirection: 'row', gap: spacing.xs, marginTop: spacing.xs },
  tag: { borderRadius: radius.pill, borderWidth: 1, paddingHorizontal: spacing.sm, paddingVertical: 2 },
  tagLabel: { fontSize: typography.tiny, fontWeight: '700' },
  time: { color: colors.grey400, fontSize: typography.small },
});
