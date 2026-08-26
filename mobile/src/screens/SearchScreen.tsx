import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';

import { searchTickets } from '../api/agent';
import { NetworkError } from '../api/client';
import { EmptyNote, ErrorNote, Field } from '../components/ui';
import { VerdictPanel } from '../components/VerdictPanel';
import type { LocalTicket, ScanVerdict } from '../domain/types';
import { searchLocalTickets, upsertTickets } from '../db/tickets';
import { controlByReference } from '../services/control';
import { useSession } from '../state/session';
import { useSync } from '../state/useSync';
import { colors, radius, spacing, typography } from '../theme';
import type { RootStackParamList } from '../navigation';

/**
 * La recherche manuelle (section 27.15).
 *
 * Le QR ne se lit pas — ecran casse, papier trempe, impression trop pale. La
 * personne a pourtant un billet. L'agent le retrouve par sa reference ou son
 * nom, et le laisse entrer.
 *
 * LA BASE LOCALE D'ABORD, TOUJOURS. C'est ce qui rend la recherche utilisable
 * sans reseau, et c'est le cas courant a une porte. Le serveur n'est interroge
 * qu'en complement, pour les deux cas que le local ne couvre pas : une
 * application fraichement installee, et un billet achete apres la derniere
 * synchronisation — ce qui arrive sans arret dans les deux heures qui precedent
 * un concert.
 *
 * ADMETTRE EST UN GESTE EXPLICITE. Trouver un billet ne fait entrer personne :
 * l'agent choisit, et ce controle est enregistre comme accorde sans lecture du
 * QR. La distinction se retrouve jusque dans le journal de l'organisateur.
 */

type Props = NativeStackScreenProps<RootStackParamList, 'Search'>;

export function SearchScreen({ route }: Props) {
  const { eventId } = route.params;
  const { token } = useSession();
  const sync = useSync(eventId);

  const [term, setTerm] = useState('');
  const [results, setResults] = useState<LocalTicket[]>([]);
  const [searching, setSearching] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [verdict, setVerdict] = useState<ScanVerdict | null>(null);

  const search = useCallback(
    async (value: string) => {
      const trimmed = value.trim();

      if (trimmed.length < 3) {
        setResults([]);

        return;
      }

      setSearching(true);
      setError(null);

      const local = await searchLocalTickets(eventId, trimmed);
      setResults(local);

      if (!token) {
        setSearching(false);

        return;
      }

      try {
        const response = await searchTickets(token, eventId, trimmed);

        /*
         * Les billets trouves cote serveur entrent dans la base locale : le
         * suivant qui presente le meme billet sera reconnu meme si le reseau
         * est reparti entre-temps.
         */
        await upsertTickets(
          eventId,
          response.tickets.map((ticket) => ({
            id: ticket.id,
            reference: ticket.reference,
            // La recherche ne descend jamais d'empreinte : ce billet ne sera
            // donc pas reconnu par son QR tant que la synchronisation ne l'a
            // pas apporte. Il reste admissible a la reference, ce qui est
            // exactement le besoin de cet ecran.
            token_hash: '',
            holder_name: ticket.holder_name,
            ticket_type: ticket.ticket_type,
            status: ticket.status,
            updated_at: null,
          })),
        );

        setResults(await searchLocalTickets(eventId, trimmed));
      } catch (caught) {
        // Hors connexion : la recherche locale a deja repondu, il n'y a rien a
        // signaler. C'est le fonctionnement normal, pas une panne.
        if (!(caught instanceof NetworkError)) {
          setError(caught instanceof Error ? caught.message : 'Recherche impossible.');
        }
      } finally {
        setSearching(false);
      }
    },
    [eventId, token],
  );

  useEffect(() => {
    const timer = setTimeout(() => void search(term), 350);

    return () => clearTimeout(timer);
  }, [search, term]);

  async function admit(ticket: LocalTicket) {
    const result = await controlByReference(eventId, ticket.reference);

    setVerdict(result);
    void sync.sync();

    setResults(await searchLocalTickets(eventId, term.trim()));
  }

  return (
    <View style={styles.container}>
      <View style={styles.form}>
        <Field
          label="Reference ou nom"
          value={term}
          onChangeText={setTerm}
          autoCapitalize="characters"
          autoCorrect={false}
          placeholder="T-XXXX-XXXX"
        />
        <Text style={styles.hint}>
          Trois caracteres minimum. Fonctionne sans reseau, sur les billets deja
          telecharges.
        </Text>
        <ErrorNote message={error} />
      </View>

      <FlatList
        data={results}
        keyExtractor={(ticket) => String(ticket.id)}
        contentContainerStyle={styles.list}
        refreshing={searching}
        onRefresh={() => void search(term)}
        ListEmptyComponent={
          term.trim().length < 3 ? null : searching ? null : (
            <EmptyNote
              title="Aucun billet"
              detail="Verifiez la reference, ou essayez le nom du participant."
            />
          )
        }
        renderItem={({ item }) => (
          <View style={styles.row}>
            <View style={styles.body}>
              <Text style={styles.name}>{item.holderName ?? 'Sans nom'}</Text>
              <Text style={styles.detail}>
                {[item.reference, item.ticketType].filter(Boolean).join(' · ')}
              </Text>
              {item.status !== 'ACTIVE' ? (
                <Text style={styles.state}>{stateLabel(item)}</Text>
              ) : null}
            </View>

            <Pressable
              style={[styles.admit, item.status !== 'ACTIVE' && styles.admitMuted]}
              onPress={() => void admit(item)}
              accessibilityRole="button"
              accessibilityLabel={`Controler le billet ${item.reference}`}
            >
              {/* Le bouton reste actif sur un billet deja utilise : c'est le
                  serveur qui refuse, et le refus doit etre journalise comme
                  tel. Le griser ferait disparaitre la tentative. */}
              <Text style={styles.admitLabel}>Controler</Text>
            </Pressable>
          </View>
        )}
      />

      {verdict ? <VerdictPanel verdict={verdict} onDismiss={() => setVerdict(null)} /> : null}
    </View>
  );
}

function stateLabel(ticket: LocalTicket): string {
  switch (ticket.status) {
    case 'USED':
      return ticket.usedAt
        ? `Deja passe a ${new Date(ticket.usedAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`
        : 'Deja passe';
    case 'REFUNDED':
      return 'Rembourse';
    case 'CANCELLED':
      return 'Annule';
    case 'EXPIRED':
      return 'Expire';
    default:
      return '';
  }
}

const styles = StyleSheet.create({
  container: { backgroundColor: colors.canvas, flex: 1 },
  form: { gap: spacing.sm, padding: spacing.md },
  hint: { color: colors.grey500, fontSize: typography.small },
  list: { gap: spacing.sm, padding: spacing.md, paddingTop: 0 },
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
  body: { flex: 1, gap: 2 },
  name: { color: colors.ink, fontSize: typography.body, fontWeight: '700' },
  detail: { color: colors.grey500, fontSize: typography.small },
  state: { color: colors.warning, fontSize: typography.small, fontWeight: '600' },
  admit: {
    backgroundColor: colors.azure600,
    borderRadius: radius.pill,
    minHeight: 48,
    justifyContent: 'center',
    paddingHorizontal: spacing.lg,
  },
  admitMuted: { backgroundColor: colors.grey400 },
  admitLabel: { color: colors.white, fontSize: typography.body, fontWeight: '700' },
});
