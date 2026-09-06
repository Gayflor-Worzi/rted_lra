import 'react-native-gesture-handler'
import { StatusBar } from 'expo-status-bar'
import { Text, View } from 'react-native'
import { SafeAreaProvider, useSafeAreaInsets } from 'react-native-safe-area-context'
import { NavigationContainer } from '@react-navigation/native'
import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs'
import { AuthProvider, useAuth } from './src/auth'
import { SyncProvider } from './src/sync'
import { theme } from './src/theme'
import { hasAny } from './src/rbac'
import IdleGate from './src/components/IdleGate'
import LoginScreen from './src/screens/LoginScreen'
import ResetPasswordScreen from './src/screens/ResetPasswordScreen'
import TasksScreen from './src/screens/TasksScreen'
import TaskDetailScreen from './src/screens/TaskDetailScreen'
import VisitFormScreen from './src/screens/VisitFormScreen'
import DiscoverScreen from './src/screens/DiscoverScreen'
import NewDiscoveryScreen from './src/screens/NewDiscoveryScreen'
import DiscoveryDetailScreen from './src/screens/DiscoveryDetailScreen'
import HomeScreen from './src/screens/HomeScreen'
import ReceiptScreen from './src/screens/ReceiptScreen'
import OutboxScreen from './src/screens/OutboxScreen'
import ValuationScreen from './src/screens/ValuationScreen'
import NewValuationScreen from './src/screens/NewValuationScreen'
import ValuationDetailScreen from './src/screens/ValuationDetailScreen'
import PaymentsScreen from './src/screens/PaymentsScreen'

const Stack = createNativeStackNavigator()
const Tab = createBottomTabNavigator()

const TABS = {
  Home: { icon: '🏠', label: 'Home' },
  Tasks: { icon: '📋', label: 'Tasks' },
  Discover: { icon: '📍', label: 'Discoveries' },
  Verifications: { icon: '🧾', label: 'Verify' },
  Valuations: { icon: '🏷️', label: 'Valuations' },
  Sync: { icon: '🔄', label: 'Sync' },
}

function TabIcon({ name, color, focused }) {
  return (
    <View style={{ alignItems: 'center', justifyContent: 'center' }}>
      <Text style={{ fontSize: focused ? 24 : 21, opacity: focused ? 1 : 0.55, marginTop: 2 }}>
        {TABS[name].icon}
      </Text>
      {focused && (
        <View style={{ marginTop: 2, width: 6, height: 6, borderRadius: 3, backgroundColor: color || theme.colors.primary }} />
      )}
    </View>
  )
}

function TabNav() {
  const { user } = useAuth()
  const insets = useSafeAreaInsets()

  const tabs = [
    <Tab.Screen name="Home" component={HomeScreen} />,
    <Tab.Screen name="Tasks" component={TasksScreen} />,
    <Tab.Screen name="Discover" component={DiscoverScreen} />,
    ...(hasAny(user, ['payments.verify', 'payments.reject'])
      ? [<Tab.Screen key="verifications" name="Verifications" component={PaymentsScreen} />]
      : []),
    ...(hasAny(user, ['valuation.create', 'valuation.review', 'valuation.view_history'])
      ? [<Tab.Screen key="valuations" name="Valuations" component={ValuationScreen} />]
      : []),
    <Tab.Screen name="Sync" component={OutboxScreen} />,
  ]

  return (
    <Tab.Navigator
      screenOptions={({ route }) => ({
        headerShown: false,
        tabBarActiveTintColor: theme.colors.primary,
        tabBarInactiveTintColor: theme.colors.textLight,
        tabBarStyle: {
          height: 58 + insets.bottom,
          paddingBottom: Math.max(insets.bottom, 8),
          paddingTop: 6,
          borderTopWidth: 2,
          borderTopColor: theme.colors.primary,
          backgroundColor: theme.colors.white,
        },
        tabBarItemStyle: { minHeight: 44 },
        tabBarLabelStyle: { fontSize: 12, fontWeight: '700', marginBottom: 4 },
        tabBarIcon: ({ color, focused }) => <TabIcon name={route.name} color={color} focused={focused} />,
      })}
    >
      {tabs}
    </Tab.Navigator>
  )
}

function RootNav() {
  const { token, user } = useAuth()
  const authenticated = !!token && !!user

  const detailHeader = (title) => ({
    title,
    headerTintColor: theme.colors.navy,
    headerTitleStyle: { fontWeight: '800', color: theme.colors.navy },
    headerStyle: { backgroundColor: theme.colors.white },
    headerShadowVisible: false,
    headerBackButtonDisplayMode: 'minimal',
  })

  const nav = (
    <Stack.Navigator>
      {!authenticated ? (
        <>
          <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
        </>
      ) : user?.must_reset_password ? (
        <Stack.Screen name="ResetPassword" component={ResetPasswordScreen} options={{ headerShown: false }} />
      ) : (
        <>
          <Stack.Screen name="Main" component={TabNav} options={{ headerShown: false }} />
          <Stack.Screen name="TaskDetail" component={TaskDetailScreen} options={detailHeader('Task Detail')} />
          <Stack.Screen name="VisitForm" component={VisitFormScreen} options={detailHeader('Record Field Visit')} />
          <Stack.Screen name="SubmitReceipt" component={ReceiptScreen} options={detailHeader('Submit Receipt')} />
          <Stack.Screen name="NewDiscovery" component={NewDiscoveryScreen} options={detailHeader('New Property')} />
          <Stack.Screen name="DiscoveryDetail" component={DiscoveryDetailScreen} options={detailHeader('Discovery Detail')} />
          <Stack.Screen name="ValuationDetail" component={ValuationDetailScreen} options={detailHeader('Valuation Detail')} />
          <Stack.Screen name="NewValuation" component={NewValuationScreen} options={detailHeader('New Assessment')} />
        </>
      )}
    </Stack.Navigator>
  )

  return authenticated ? <IdleGate>{nav}</IdleGate> : nav
}

export default function App() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <SyncProvider>
          <NavigationContainer>
            <RootNav />
            <StatusBar style="dark" />
          </NavigationContainer>
        </SyncProvider>
      </AuthProvider>
    </SafeAreaProvider>
  )
}