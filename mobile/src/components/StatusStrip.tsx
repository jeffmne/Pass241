import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { clockWarning } from '../domain/clock';
import { colors, radius, spacing, typography } from '../theme';
import { useNetwork } from '../state/network';
import { useSession } from '../state/session';
import type { SyncState } from '../state/useSync';

/**
 * Le bandeau d'etat (sections 27.9, 27.11, 27.14).
 *
 * Trois informations, toujours visibles, jamais plus : l'etat du reseau, le
 * nombre de controles en attente, et la derniere synchronisation. L'agent doit
 * pouvoir repondre en un coup d'oeil a « est-ce que ce que je fais est bien
 * remonte ? ».
 *
 * PASTILLE DE COULEUR ET NON PICTOGRAMME : la regle 80.6 interdit les emoji
 * partout dans le logiciel, y compris ici ou la spec les employait pour
 * illustrer.
 *
 * Le bandeau est TAPABLE : c'est la synchronisation manuelle de la section
 * 27.11. Un agent qui veut s'assurer que sa file est partie avant de rendre le
 * telephone n'a pas a chercher un ecran de reglages.
 */
export function StatusStrip({ sync }: { sync: SyncState }) {
  const { online, unknown } = useNetwork();
  const { clockOffset } = useSession();

  const warning = clockWarning(clockOffset);

  const tone = unknown ? colors.grey400 : online ? colors.success : colors.danger;
  const label = unknown ? 'Reseau inconnu' : online ? 'En ligne' : 'Hors connexion';

  return (
    <View>
      <Pressable
        onPress={() => void sync.sync()}
        accessibilityRole="button"
        accessibilityLabel="Synchroniser maintenant"
        style={styles.strip}
      >
        <View style={[styles.dot, { backgroundColor: tone }]} />
        <Text style={styles.label}>{label}</Text>

        <View style={styles.spacer} />

        {sync.syncing ? (
          <ActivityIndicator size="small" color={colors.azure300} />
        ) : (
          <Text style={styles.detail}>
            {sync.pending > 0
              ? `${sync.pending} en attente`
              : sync.lastSyncAt
                ? `Synchronise a ${formatTime(sync.lastSyncAt)}`
                : 'Jamais synchronise'}
          </Text>
        )}
      </Pressable>

      {warning ? <Text style={styles.warning}>{warning}</Text> : null}

      {/* Un verdict retourne par le serveur ne doit pas passer inapercu :
          l'agent a lu « entree autorisee » et le journal dit autre chose. */}
      {sync.overturned > 0 ? (
        <Text style={styles.warning}>
          {sync.overturned} controle{sync.overturned > 1 ? 's' : ''} arbitre
          {sync.overturned > 1 ? 's' : ''} par le serveur — voir l'historique.
        </Text>
      ) : null}
    </View>
  );
}

function formatTime(date: Date): string {
  return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  strip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    backgroundColor: colors.ink,
  },
  dot: { width: 10, height: 10, borderRadius: radius.pill },
  label: { color: colors.white, fontSize: typography.small, fontWeight: '600' },
  spacer: { flex: 1 },
  detail: { color: colors.grey400, fontSize: typography.small },
  warning: {
    backgroundColor: colors.warning,
    color: colors.ink,
    fontSize: typography.small,
    fontWeight: '600',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
});
