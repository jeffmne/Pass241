import { CameraView, useCameraPermissions } from 'expo-camera';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Button } from '../components/ui';
import { StatusStrip } from '../components/StatusStrip';
import { VerdictPanel } from '../components/VerdictPanel';
import type { ScanVerdict } from '../domain/types';
import { controlByToken } from '../services/control';
import { useSync } from '../state/useSync';
import { colors, radius, spacing, typography } from '../theme';
import type { RootStackParamList } from '../navigation';

/**
 * Le scanner — l'ecran principal (sections 27.6, 27.19).
 *
 * Tout est regle pour la repetition : presenter, lire, decider, suivant. Des
 * centaines de fois, parfois des milliers, par une personne debout qui tient
 * une file.
 *
 * TROIS DECISIONS D'USAGE :
 *
 * 1. LE RETOUR AU SCANNER EST AUTOMATIQUE. Le verdict s'efface seul apres
 *    deux secondes et demie, ou au premier toucher. Demander un geste pour
 *    chaque personne ajouterait des centaines de gestes a la soiree.
 *
 * 2. UN VERROU ENTRE DEUX LECTURES. La camera relit le meme QR dix fois par
 *    seconde tant qu'il est devant l'objectif : sans verrou, une seule
 *    presentation produirait dix controles.
 *
 * 3. AUCUN BLOCAGE SUR LE RESEAU. La decision est locale (section 27.7) ; la
 *    remontee se fait derriere, par la file. Le scanner ne s'arrete jamais
 *    parce que la connexion est tombee.
 */

type Props = NativeStackScreenProps<RootStackParamList, 'Scan'>;

/** Duree d'affichage d'un verdict avant retour automatique au scanner. */
const VERDICT_MS = 2_500;

export function ScanScreen({ navigation, route }: Props) {
  const { eventId, eventName } = route.params;

  const [permission, requestPermission] = useCameraPermissions();
  const [verdict, setVerdict] = useState<ScanVerdict | null>(null);

  const busy = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const sync = useSync(eventId);

  useEffect(() => {
    navigation.setOptions({ title: eventName });
  }, [eventName, navigation]);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const dismiss = useCallback(() => {
    if (timer.current) {
      clearTimeout(timer.current);
      timer.current = null;
    }

    setVerdict(null);
    busy.current = false;
  }, []);

  const onScanned = useCallback(
    async (data: string) => {
      if (busy.current) {
        return;
      }

      busy.current = true;

      const result = await controlByToken(eventId, data);

      setVerdict(result);
      void sync.refreshPending();

      // La remontee part tout de suite si le reseau est la, mais personne ne
      // l'attend : le scanner est deja pret pour la personne suivante.
      void sync.sync();

      timer.current = setTimeout(dismiss, VERDICT_MS);
    },
    [dismiss, eventId, sync],
  );

  if (!permission) {
    return <View style={styles.container} />;
  }

  if (!permission.granted) {
    return (
      <View style={styles.permission}>
        <Text style={styles.permissionTitle}>Acces a la camera</Text>
        <Text style={styles.permissionText}>
          L'application a besoin de la camera pour lire les QR Codes des billets.
          Elle ne prend aucune photo et n'enregistre aucune image.
        </Text>
        <Button label="Autoriser la camera" onPress={() => void requestPermission()} />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <StatusStrip sync={sync} />

      <View style={styles.viewfinder}>
        <CameraView
          style={StyleSheet.absoluteFill}
          facing="back"
          barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
          // Coupee pendant l'affichage d'un verdict : inutile de faire tourner
          // la reconnaissance quand personne ne presente de billet.
          onBarcodeScanned={verdict ? undefined : ({ data }) => void onScanned(data)}
        />

        {/* Repere de visee. Un cadre suffit : l'agent doit savoir ou pointer,
            pas apprendre a cadrer. */}
        <View pointerEvents="none" style={styles.reticle} />

        {verdict ? <VerdictPanel verdict={verdict} onDismiss={dismiss} /> : null}
      </View>

      <View style={styles.actions}>
        <Action
          label="Rechercher"
          hint="QR illisible"
          onPress={() => navigation.navigate('Search', { eventId, eventName })}
        />
        <Action
          label="Historique"
          hint="Derniers controles"
          onPress={() => navigation.navigate('History', { eventId, eventName })}
        />
        <Action
          label="Compteurs"
          hint="Etat de l'entree"
          onPress={() => navigation.navigate('Dashboard', { eventId, eventName })}
        />
      </View>
    </View>
  );
}

function Action({ label, hint, onPress }: { label: string; hint: string; onPress: () => void }) {
  return (
    <Pressable style={styles.action} onPress={onPress} accessibilityRole="button">
      <Text style={styles.actionLabel}>{label}</Text>
      <Text style={styles.actionHint}>{hint}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { backgroundColor: colors.ink, flex: 1 },
  viewfinder: { backgroundColor: '#000', flex: 1, overflow: 'hidden' },
  reticle: {
    alignSelf: 'center',
    borderColor: 'rgba(255,255,255,0.85)',
    borderRadius: radius.lg,
    borderWidth: 3,
    height: '46%',
    marginTop: '22%',
    width: '78%',
  },
  actions: {
    backgroundColor: colors.ink,
    flexDirection: 'row',
    paddingBottom: spacing.lg,
    paddingTop: spacing.sm,
  },
  action: { alignItems: 'center', flex: 1, gap: 2, paddingVertical: spacing.sm },
  actionLabel: { color: colors.white, fontSize: typography.body, fontWeight: '700' },
  actionHint: { color: colors.grey400, fontSize: typography.tiny },

  permission: {
    backgroundColor: colors.canvas,
    flex: 1,
    gap: spacing.md,
    justifyContent: 'center',
    padding: spacing.lg,
  },
  permissionTitle: { color: colors.ink, fontSize: typography.title, fontWeight: '800' },
  permissionText: { color: colors.grey500, fontSize: typography.body, lineHeight: 22 },
});
