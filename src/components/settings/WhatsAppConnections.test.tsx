import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { WhatsAppConnections } from './WhatsAppConnections';
const mocks = vi.hoisted(() => ({ getWhatsAppConnections: vi.fn(), startWhatsAppConnection: vi.fn(),
  confirmWhatsAppConnection: vi.fn(), cancelWhatsAppConnection: vi.fn(), disconnectWhatsAppConnection: vi.fn(), owner: 'owner-1' }));
vi.mock('@/lib/whatsapp-connect', () => mocks);
vi.mock('@/lib/whatsapp-workspace', () => ({ getWhatsAppWorkspace: vi.fn().mockResolvedValue({ available: false, sending_enabled: false, operations: [] }), getWhatsAppTemplates: vi.fn(), submitWhatsAppOperation: vi.fn() }));
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: mocks.owner } }) }));
const number = { phone_number_id: '222222222', waba_id: '111111111', display_phone_number: '+27 00 000 0000', verified_name: 'Fixture business', business_name: 'Fixture business' };
const ready = { available: true, sending_enabled: false, connections: [], pending: null };
const clients: QueryClient[] = [];
function mount() { const client = new QueryClient({ defaultOptions: { queries: { retry: false } } }); clients.push(client);
  const ui = () => <QueryClientProvider client={client}><WhatsAppConnections/></QueryClientProvider>;
  return { ...render(ui()), client, ui }; }
beforeEach(() => { vi.clearAllMocks(); mocks.owner = 'owner-1'; mocks.getWhatsAppConnections.mockResolvedValue(ready); });
afterEach(() => { cleanup(); clients.forEach(client => client.clear()); clients.length = 0; });
it('keeps the connection action hidden while server setup is unavailable', async () => {
  mocks.getWhatsAppConnections.mockResolvedValue({ ...ready, available: false }); mount();
  expect(await screen.findByText(/connections are awaiting setup/)).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Connect WhatsApp Business' })).toBeNull();
  expect(mocks.startWhatsAppConnection).not.toHaveBeenCalled();
});
it('requires a customer action to start signup and states sending is not enabled', async () => {
  mount(); const button = await screen.findByRole('button', { name: 'Connect WhatsApp Business' });
  expect(screen.getByText(/Linking a number does not send messages/)).toBeVisible();
  expect(mocks.startWhatsAppConnection).not.toHaveBeenCalled(); fireEvent.click(button);
  await waitFor(() => expect(mocks.startWhatsAppConnection).toHaveBeenCalledTimes(1));
});
it('requires selection and explicit confirmation of a Meta-authorized number', async () => {
  mocks.getWhatsAppConnections.mockResolvedValue({ ...ready, pending: { attempt: 'fixture-attempt', options: [number], expires_at: 1800000600 } });
  mount(); const confirm = await screen.findByRole('button', { name: 'Confirm connection' });
  expect(confirm).toBeDisabled(); expect(mocks.confirmWhatsAppConnection).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Business number'), { target: { value: number.phone_number_id } }); fireEvent.click(confirm);
  await waitFor(() => expect(mocks.confirmWhatsAppConnection).toHaveBeenCalledWith('fixture-attempt', number.phone_number_id));
});
it('cancels pending review without connecting a number', async () => {
  mocks.getWhatsAppConnections.mockResolvedValue({ ...ready, pending: { attempt: 'fixture-attempt', options: [number], expires_at: 1800000600 } });
  mount(); fireEvent.click(await screen.findByRole('button', { name: 'Cancel connection' }));
  await waitFor(() => expect(mocks.cancelWhatsAppConnection).toHaveBeenCalledTimes(1)); expect(mocks.confirmWhatsAppConnection).not.toHaveBeenCalled();
});
it('requires confirmation before deleting saved connection credentials', async () => {
  mocks.getWhatsAppConnections.mockResolvedValue({ ...ready, connections: [{ ...number, status: 'authorized', expires_at: 1805184000 }] });
  mount(); fireEvent.click(await screen.findByRole('button', { name: `Disconnect ${number.display_phone_number}` }));
  expect(mocks.disconnectWhatsAppConnection).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Confirm disconnect' }));
  await waitFor(() => expect(mocks.disconnectWhatsAppConnection).toHaveBeenCalledWith(number.phone_number_id));
});
it('reports a failed signup action without automatically retrying it', async () => {
  mocks.startWhatsAppConnection.mockRejectedValue(new Error('Start a new connection attempt.')); mount();
  fireEvent.click(await screen.findByRole('button', { name: 'Connect WhatsApp Business' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Start a new connection attempt.');
  expect(mocks.startWhatsAppConnection).toHaveBeenCalledTimes(1);
});
it('uses a separate query cache for each signed-in portal owner', async () => {
  const mounted = mount(); await screen.findByRole('button', { name: 'Connect WhatsApp Business' });
  mocks.owner = 'owner-2'; mounted.rerender(mounted.ui());
  await waitFor(() => expect(mocks.getWhatsAppConnections).toHaveBeenCalledTimes(2));
  expect(mounted.client.getQueryCache().getAll().map(query => query.queryKey)).toContainEqual(['whatsapp-connections', 'owner-2']);
});
