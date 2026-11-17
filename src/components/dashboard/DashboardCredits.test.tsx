import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { DashboardCredits } from './DashboardCredits';

const mocks = vi.hoisted(() => ({ get: vi.fn(), user: { id: 'customer-a' } as { id: string } | null }));
vi.mock('@/lib/api', () => ({ api: { get: mocks.get } }));
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: mocks.user }) }));
vi.mock('@/components/wallet/CustomerCreditTransfer', () => ({CustomerCreditTransfer:({availableCredits}:{availableCredits:number})=><button>Transfer credits ({availableCredits})</button>}));

const clients: QueryClient[] = [];
function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  clients.push(client);
  const view = () => <QueryClientProvider client={client}><MemoryRouter><DashboardCredits /></MemoryRouter></QueryClientProvider>;
  return { ...render(view()), client, view };
}
beforeEach(() => {
  mocks.get.mockReset();
  mocks.user = { id: 'customer-a' };
  mocks.get.mockResolvedValue({ success: true, wallet: { sms_credits: 487, balance: 175, reserved: 4.55 } });
});
afterEach(() => { cleanup(); clients.forEach(client => client.clear()); clients.length = 0; vi.useRealTimers(); });

it('shows the server available-credit count, not the monetary or reserved balance', async () => {
  mount();
  expect(await screen.findByLabelText('487 available SMS credits')).toHaveTextContent('487');
  expect(mocks.get).toHaveBeenCalledWith('/wallet');
  expect(screen.getByText(/Reserved credits are excluded/)).toBeVisible();
  expect(screen.getByRole('link', { name: 'Buy credits' })).toHaveAttribute('href', '/wallet');
  expect(screen.getByRole('button', { name: 'Transfer credits (487)' })).toBeVisible();
});
it('accepts a wrapped API response and a genuine zero balance', async () => {
  mocks.get.mockResolvedValue({ success: true, data: { wallet: { sms_credits: 0 } } });
  mount();
  expect(await screen.findByLabelText('0 available SMS credits')).toHaveTextContent('0');
});
it('uses a loading skeleton instead of flashing zero credits', async () => {
  let resolve!: (result: unknown) => void;
  mocks.get.mockReturnValue(new Promise(done => { resolve = done; }));
  mount();
  expect(screen.getByRole('status', { name: 'Loading available credits' })).toBeVisible();
  expect(screen.queryByLabelText('0 available SMS credits')).toBeNull();
  resolve({ success: true, wallet: { sms_credits: 23 } });
  expect(await screen.findByLabelText('23 available SMS credits')).toBeVisible();
});
it.each([
  { success: false, wallet: { sms_credits: 50 } },
  { success: true },
  { success: true, wallet: { sms_credits: -1 } },
  { success: true, wallet: { sms_credits: '50' } },
  { success: true, wallet: { sms_credits: 1.5 } },
])('does not invent a balance from malformed data: %j', async response => {
  mocks.get.mockResolvedValue(response);
  mount();
  expect(await screen.findByRole('alert')).toHaveTextContent('Credit balance unavailable');
  expect(screen.queryByLabelText('0 available SMS credits')).toBeNull();
});
it('allows a failed balance request to be retried independently', async () => {
  mocks.get.mockRejectedValueOnce(new Error('Offline'));
  mount();
  await screen.findByRole('alert');
  fireEvent.click(screen.getByRole('button', { name: 'Retry credits' }));
  expect(await screen.findByLabelText('487 available SMS credits')).toBeVisible();
});
it('hides a stale balance after refresh fails and allows recovery', async () => {
  mount();
  await screen.findByLabelText('487 available SMS credits');
  mocks.get.mockRejectedValueOnce(new Error('Unavailable'));
  fireEvent.click(screen.getByRole('button', { name: 'Refresh credits' }));
  await screen.findByRole('alert');
  expect(screen.queryByLabelText('487 available SMS credits')).toBeNull();
  mocks.get.mockResolvedValue({ success: true, wallet: { sms_credits: 480 } });
  fireEvent.click(screen.getByRole('button', { name: 'Retry credits' }));
  expect(await screen.findByLabelText('480 available SMS credits')).toBeVisible();
});
it('does not expose another customer cached balance when the signed-in account changes', async () => {
  const { rerender, view } = mount();
  await screen.findByLabelText('487 available SMS credits');
  mocks.user = { id: 'customer-b' };
  let resolve!: (result: unknown) => void;
  mocks.get.mockReturnValueOnce(new Promise(done => { resolve = done; }));
  rerender(view());
  expect(screen.queryByLabelText('487 available SMS credits')).toBeNull();
  resolve({ success: true, wallet: { sms_credits: 6 } });
  expect(await screen.findByLabelText('6 available SMS credits')).toBeVisible();
});
it('does not fetch or reuse cached credits without a signed-in account', async () => {
  mocks.user = null;
  mount();
  expect(screen.getByRole('alert')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Refresh credits' })).toBeDisabled();
  expect(mocks.get).not.toHaveBeenCalled();
});
it('refreshes available credits automatically while the dashboard is open', async () => {
  vi.useFakeTimers();
  mount();
  await act(async () => { await vi.advanceTimersByTimeAsync(100); });
  expect(screen.getByLabelText('487 available SMS credits')).toBeVisible();
  mocks.get.mockResolvedValue({ success: true, wallet: { sms_credits: 450 } });
  await act(async () => { await vi.advanceTimersByTimeAsync(15100); });
  expect(screen.getByLabelText('450 available SMS credits')).toBeVisible();
  expect(mocks.get).toHaveBeenCalledTimes(2);
});
