import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { StatusBar } from 'expo-status-bar';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { database } from './src/db/database';
import type { RootStackParamList } from './src/navigation';
import { DashboardScreen } from './src/screens/DashboardScreen';
import { EventsScreen } from './src/screens/EventsScreen';
import { HistoryScreen } from './src/screens/HistoryScreen';
import { LoginScreen } from './src/screens/LoginScreen';
import { ScanScreen } from './src/screens/ScanScreen';
import { SearchScreen } from './src/screens/SearchScreen';
import { NetworkProvider } from './src/state/network';
import { SessionProvider, useSession } from './src/state/session';
import { colors } from './src/theme';

/**
 * L'application agent Pass241 (section 27).
 *
 * Volontairement simple : elle ne vend pas de billets, ne gere pas
 * d'evenements, n'administre rien. Elle controle des entrees, et rien d'autre
 * (section 27.18).
 *
 * La base locale est ouverte AVANT le premier ecran. Une migration a moitie
 * faite pendant que l'agent scanne serait le pire moment possible ; mieux vaut
 * une demi-seconde au lancement.
 */

const Stack = createNativeStackNavigator<RootStackParamList>();

export default function App() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <NetworkProvider>
          <StatusBar style="light" />
          <Root />
        </NetworkProvider>
      </SessionProvider>
    </SafeAreaProvider>
  );
}

function Root() {
  const { ready, token } = useSession();
  const [databaseReady, setDatabaseReady] = useState(false);

  useEffect(() => {
    void database().then(() => setDatabaseReady(true));
  }, []);

  if (!ready || !databaseReady) {
    return (
      <View style={{ alignItems: 'center', backgroundColor: colors.canvas, flex: 1, justifyContent: 'center' }}>
        <ActivityIndicator color={colors.azure600} size="large" />
      </View>
    );
  }

  if (!token) {
    return <LoginScreen />;
  }

  return (
    <NavigationContainer>
      <Stack.Navigator
        screenOptions={{
          headerStyle: { backgroundColor: colors.azure700 },
          headerTintColor: colors.white,
          headerTitleStyle: { fontWeight: '700' },
        }}
      >
        <Stack.Screen name="Events" component={EventsScreen} options={{ headerShown: false }} />
        <Stack.Screen name="Scan" component={ScanScreen} options={{ title: 'Controle' }} />
        <Stack.Screen name="History" component={HistoryScreen} options={{ title: 'Historique' }} />
        <Stack.Screen name="Search" component={SearchScreen} options={{ title: 'Recherche' }} />
        <Stack.Screen name="Dashboard" component={DashboardScreen} options={{ title: 'Compteurs' }} />
      </Stack.Navigator>
    </NavigationContainer>
  );
}
