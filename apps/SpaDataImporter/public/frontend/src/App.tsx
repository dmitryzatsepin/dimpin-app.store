import './App.css'
import SmartProcessSelector from './components/SmartProcessSelector';
import { AppShell, Burger, Group, Title, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';

function App() {
  const [opened, { toggle }] = useDisclosure();

  return (
    <AppShell
      header={{ height: 60 }}
      navbar={{ width: 0, breakpoint: 'sm', collapsed: { mobile: !opened } }} // Navbar пока не используем
      padding="md"
    >
      <AppShell.Header>
        <Group h="100%" px="md">
          <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm" />
          <Title order={2}>SPA Data Import</Title>
        </Group>
      </AppShell.Header>
      <AppShell.Main>
        <SmartProcessSelector />
      </AppShell.Main>

      <AppShell.Footer p="xs" style={{ textAlign: 'center' }}>
        <Text size="xs" c="dimmed">© {new Date().getFullYear()} Technopeak SPA Importer</Text>
      </AppShell.Footer>
    </AppShell>
  )
}

export default App