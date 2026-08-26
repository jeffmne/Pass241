import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';

import { Button, ErrorNote, Field } from '../components/ui';
import { useSession } from '../state/session';
import { colors, spacing, typography } from '../theme';

/**
 * Connexion de l'agent (section 27.3).
 *
 * Deux champs et un bouton. Les comptes agents sont crees depuis la plateforme
 * web, jamais depuis l'application (section 27.2) : il n'y a donc ni
 * inscription, ni mot de passe oublie — l'organisateur regenere les
 * identifiants et les transmet, souvent de vive voix sur place.
 *
 * L'erreur affichee est celle du serveur. Elle ne dit jamais si l'adresse
 * existe : la distinguer d'un mot de passe faux revelerait quels comptes sont
 * enregistres.
 */
export function LoginScreen() {
  const { signIn } = useSession();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setBusy(true);
    setError(null);

    try {
      await signIn(email, password);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Connexion impossible.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={styles.container}
    >
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Text style={styles.brand}>Pass241</Text>
        <Text style={styles.subtitle}>Controle d'acces</Text>

        <View style={styles.form}>
          <Field
            label="Adresse email"
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            textContentType="username"
            placeholder="agent@exemple.ga"
          />

          <Field
            label="Mot de passe"
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            autoCapitalize="none"
            textContentType="password"
            onSubmitEditing={() => void submit()}
          />

          <ErrorNote message={error} />

          <Button label="Se connecter" onPress={() => void submit()} busy={busy} />
        </View>

        <Text style={styles.note}>
          Votre compte est cree par l'organisateur. Cette application ne sert qu'au
          controle des entrees.
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { backgroundColor: colors.canvas, flex: 1 },
  content: { flexGrow: 1, justifyContent: 'center', padding: spacing.lg },
  brand: {
    color: colors.azure700,
    fontSize: 34,
    fontWeight: '800',
    letterSpacing: -0.5,
    textAlign: 'center',
  },
  subtitle: {
    color: colors.grey500,
    fontSize: typography.body,
    marginTop: spacing.xs,
    textAlign: 'center',
  },
  form: { gap: spacing.md, marginTop: spacing.xl },
  note: {
    color: colors.grey400,
    fontSize: typography.small,
    marginTop: spacing.xl,
    textAlign: 'center',
  },
});
