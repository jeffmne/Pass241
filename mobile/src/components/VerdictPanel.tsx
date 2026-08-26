import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { ScanVerdict } from '../domain/types';
import { toneFor } from '../domain/verdict';
import { colors, radius, spacing, typography, verdictColors } from '../theme';

/**
 * Le verdict, pleine page (section 27.8).
 *
 * L'agent doit comprendre en MOINS D'UNE SECONDE, a bout de bras, dans le bruit
 * et sous un mauvais eclairage. D'ou trois partis pris :
 *
 * - la couleur porte la decision avant le texte, et occupe tout l'ecran ;
 * - la premiere ligne dit ce qu'il faut faire — « Entree autorisee » — et non
 *   l'etat technique du billet ;
 * - le nom du porteur vient apres, en second, parce qu'il ne sert qu'a
 *   verifier une fois la decision prise.
 *
 * TROIS COULEURS ET NON DEUX. « Deja utilise » n'est pas un refus comme un
 * autre : le billet est authentique, quelqu'un est entre avec, et l'agent doit
 * chercher le vrai porteur plutot que de soupconner une contrefacon.
 *
 * Le panneau se ferme au toucher, n'importe ou : dans une file, viser un petit
 * bouton fait perdre plus de temps que le scan lui-meme.
 */
export function VerdictPanel({ verdict, onDismiss }: { verdict: ScanVerdict; onDismiss: () => void }) {
  const tone = verdictColors[toneFor(verdict.code)];

  return (
    <Pressable
      style={[styles.panel, { backgroundColor: tone }]}
      onPress={onDismiss}
      accessibilityRole="button"
      accessibilityLabel={`${verdict.label}. ${verdict.instruction}. Toucher pour continuer.`}
    >
      <Text style={styles.label}>{verdict.label.toUpperCase()}</Text>
      <Text style={styles.instruction}>{verdict.instruction}</Text>

      {verdict.holderName ? (
        <View style={styles.identity}>
          <Text style={styles.holder}>{verdict.holderName}</Text>
          {verdict.ticketType ? <Text style={styles.type}>{verdict.ticketType}</Text> : null}
          {verdict.reference ? <Text style={styles.reference}>{verdict.reference}</Text> : null}
        </View>
      ) : null}

      {/* Une entree accordee sans lecture du QR est marquee jusque sur
          l'ecran de l'agent : c'est ce qu'il vient de faire, il doit le voir. */}
      {verdict.manual && verdict.accepted ? (
        <Text style={styles.manual}>Entree accordee sans lecture du QR</Text>
      ) : null}

      <Text style={styles.hint}>Toucher pour scanner le suivant</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  panel: {
    bottom: 0,
    left: 0,
    position: 'absolute',
    right: 0,
    top: 0,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.lg,
  },
  label: {
    color: colors.white,
    fontSize: typography.verdict,
    fontWeight: '800',
    letterSpacing: 0.5,
    textAlign: 'center',
  },
  instruction: {
    color: colors.white,
    fontSize: typography.body,
    marginTop: spacing.sm,
    textAlign: 'center',
  },
  identity: {
    alignItems: 'center',
    backgroundColor: 'rgba(255,255,255,0.16)',
    borderRadius: radius.md,
    marginTop: spacing.lg,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
  },
  holder: { color: colors.white, fontSize: typography.title, fontWeight: '700' },
  type: { color: colors.white, fontSize: typography.body, marginTop: spacing.xs },
  reference: { color: colors.white, fontSize: typography.small, marginTop: spacing.xs, opacity: 0.85 },
  manual: {
    color: colors.white,
    fontSize: typography.small,
    fontWeight: '700',
    marginTop: spacing.md,
  },
  hint: {
    bottom: spacing.xl,
    color: colors.white,
    fontSize: typography.small,
    opacity: 0.8,
    position: 'absolute',
  },
});
