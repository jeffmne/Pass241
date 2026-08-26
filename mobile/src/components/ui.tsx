import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  type TextInputProps,
  View,
} from 'react-native';

import { colors, radius, spacing, typography } from '../theme';

/**
 * Les quelques briques d'interface de l'application agent.
 *
 * Volontairement minces. L'application n'a que six ecrans, tous utilitaires :
 * une bibliotheque de composants y couterait plus a maintenir qu'elle ne
 * rapporterait. Ce qui est partage l'est parce qu'il est repete, pas par
 * principe.
 *
 * Les zones tapables sont GENEREUSES (48 points au moins) : l'agent vise d'une
 * main, debout, souvent en mouvement.
 */

export function Button({
  label,
  onPress,
  busy = false,
  variant = 'primary',
  disabled = false,
}: {
  label: string;
  onPress: () => void;
  busy?: boolean;
  variant?: 'primary' | 'ghost';
  disabled?: boolean;
}) {
  const inactive = disabled || busy;

  return (
    <Pressable
      onPress={onPress}
      disabled={inactive}
      accessibilityRole="button"
      style={({ pressed }) => [
        styles.button,
        variant === 'primary' ? styles.primary : styles.ghost,
        inactive && styles.inactive,
        pressed && styles.pressed,
      ]}
    >
      {busy ? (
        <ActivityIndicator color={variant === 'primary' ? colors.white : colors.azure700} />
      ) : (
        <Text style={variant === 'primary' ? styles.primaryLabel : styles.ghostLabel}>{label}</Text>
      )}
    </Pressable>
  );
}

export function Field({
  label,
  ...props
}: TextInputProps & { label: string }) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        {...props}
        style={styles.input}
        placeholderTextColor={colors.grey400}
        accessibilityLabel={label}
      />
    </View>
  );
}

/** Message d'erreur : rouge, mais jamais alarmant pour rien. */
export function ErrorNote({ message }: { message: string | null }) {
  if (!message) return null;

  return <Text style={styles.error}>{message}</Text>;
}

export function EmptyNote({ title, detail }: { title: string; detail?: string }) {
  return (
    <View style={styles.empty}>
      <Text style={styles.emptyTitle}>{title}</Text>
      {detail ? <Text style={styles.emptyDetail}>{detail}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  button: {
    alignItems: 'center',
    borderRadius: radius.pill,
    justifyContent: 'center',
    minHeight: 52,
    paddingHorizontal: spacing.lg,
  },
  primary: { backgroundColor: colors.azure600 },
  ghost: { backgroundColor: colors.white, borderColor: colors.grey200, borderWidth: 1 },
  inactive: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
  primaryLabel: { color: colors.white, fontSize: typography.body, fontWeight: '700' },
  ghostLabel: { color: colors.azure700, fontSize: typography.body, fontWeight: '600' },

  field: { gap: spacing.xs },
  fieldLabel: { color: colors.ink, fontSize: typography.small, fontWeight: '600' },
  input: {
    backgroundColor: colors.white,
    borderColor: colors.grey200,
    borderRadius: radius.md,
    borderWidth: 1,
    color: colors.ink,
    fontSize: typography.body,
    minHeight: 52,
    paddingHorizontal: spacing.md,
  },

  error: {
    backgroundColor: '#fdecec',
    borderRadius: radius.sm,
    color: colors.danger,
    fontSize: typography.small,
    fontWeight: '600',
    padding: spacing.md,
  },

  empty: { alignItems: 'center', gap: spacing.sm, padding: spacing.xl },
  emptyTitle: { color: colors.ink, fontSize: typography.body, fontWeight: '700' },
  emptyDetail: { color: colors.grey500, fontSize: typography.small, textAlign: 'center' },
});
