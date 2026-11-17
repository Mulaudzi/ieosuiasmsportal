import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { CashAdjustmentPanel } from './CashAdjustmentPanel';
import { FinancePasswordField } from './FinancePasswordField';

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), configured: true }));
vi.mock('@/lib/api', () => ({ api: { get: mocks.get, post: mocks.post } }));
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: '3' } }) }));
vi.mock('@/hooks/use-toast', () => ({ toast: vi.fn() }));
vi.mock('./CreditApprovalSettings', () => ({ useCreditApprovalStatus: () => ({ data: { configured: mocks.configured } }) }));
const customer = { id: 1, name: 'Test Customer', email: 'customer@example.invalid' };
const id = 'a'.repeat(32); const base = '/admin/operations/cash-adjustments';
const quote = { id, user_id: 1, customer, source_kind: 'overpayment', order_reference: 'ORDER-ONE', bank_reference: 'BANK-ONE', cleared_cents: 15000, recorded_cents: 10000, amount_cents: 5000, reason: 'Verified extra funds', expires_at: Math.floor(Date.now()/1000)+600, cash_balance: { balance_cents: 500, reserved_cents: 0, available_cents: 500 } };
const receipt = { ...quote, reference: 'RAND-' + id.toUpperCase(), status: 'completed', balance_before_cents: 500, balance_after_cents: 5500, created_at: '2026-11-02 12:00:00' };
function wrap(node = <CashAdjustmentPanel customer={customer} />) { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{node}</QueryClientProvider>); }
beforeEach(() => {
  vi.clearAllMocks(); sessionStorage.clear(); mocks.configured = true;
  mocks.get.mockImplementation(async (path: string) => ({ success: true, data: path.endsWith('/'+id) ? { status: 'not_completed', quote } : path.endsWith('/accounts') ? { accounts: [customer] } : { enabled: true, adjustments: [], total: 0, page: 1, cash_balance: quote.cash_balance } }));
  mocks.post.mockImplementation(async (path: string) => ({ success: true, data: path.endsWith('/quote') ? { quote } : { adjustment: receipt } }));
});
afterEach(cleanup);
async function enter() {
  wrap(); await screen.findByText('No standalone rand credits recorded.'); fireEvent.click(screen.getByRole('button', { name: 'Add rand credit' }));
  fireEvent.change(screen.getByLabelText('Completed EFT order reference'), { target: { value: 'ORDER-ONE' } });
  fireEvent.change(screen.getByLabelText('Total cleared amount (ZAR)'), { target: { value: '150.00' } });
  fireEvent.change(screen.getByLabelText('Unique bank transaction ID'), { target: { value: 'BANK-ONE' } });
  fireEvent.change(screen.getByLabelText('Reason / reconciliation note'), { target: { value: 'Verified extra funds' } });
  fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(screen.getByRole('button', { name: 'Review rand credit' }));
  await screen.findByRole('heading', { name: 'Review rand credit' });
}
async function confirm() {
  fireEvent.change(screen.getByLabelText('Finance master password'), { target: { value: 'synthetic-finance-password' } });
  fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(screen.getByRole('button', { name: 'Credit R50.00' }));
}
it('opening the customer panel reads real cash balances and never moves money', async () => {
  wrap(); await screen.findByText('No standalone rand credits recorded.');
  expect(mocks.get).toHaveBeenCalledWith(base, { user_id: '1', page: '1' });
  fireEvent.click(screen.getByRole('button', { name: 'Add rand credit' }));
  expect(screen.getByRole('button', { name: 'Review rand credit' })).toBeDisabled(); expect(mocks.post).not.toHaveBeenCalled();
});
it('reviews the server-calculated difference and commits only an immutable quote ID', async () => {
  await enter(); expect(screen.getByText('New rand credit')).toBeInTheDocument(); expect(screen.getByText('R150.00')).toBeInTheDocument(); expect(screen.getByText('R100.00')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Credit R50.00' })).toBeDisabled();
  expect(sessionStorage.getItem('rand-credit-review:3:1')).toBe(id);
  expect(sessionStorage.getItem('rand-credit-review:3:1')).not.toContain('BANK');
  await confirm(); await screen.findByText('R50.00 credited');
  expect(mocks.post).toHaveBeenLastCalledWith(base+'/commit', { quote_id: id, master_password: 'synthetic-finance-password', confirmed: true });
  expect(sessionStorage.length).toBe(0); expect(screen.queryByLabelText('Finance master password')).not.toBeInTheDocument();
});
it('a lost response blocks another action until read-only status is checked', async () => {
  await enter(); mocks.post.mockRejectedValueOnce(new Error('Connection lost')); await confirm();
  await screen.findByRole('heading', { name: 'Check the saved review first' });
  expect(screen.queryByRole('button', { name: 'Review rand credit' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Credit R50.00' })).not.toBeInTheDocument();
  mocks.get.mockResolvedValueOnce({ success: true, data: { status: 'completed', adjustment: receipt } });
  fireEvent.click(screen.getByRole('button', { name: 'Check review status' })); await screen.findByText('R50.00 credited');
  expect(mocks.post.mock.calls.filter(([path]) => path.endsWith('/commit'))).toHaveLength(1);
});
it('recovers after remount without issuing another financial request', async () => {
  sessionStorage.setItem('rand-credit-review:3:1', id);
  mocks.get.mockImplementation(async (path: string) => ({ success: true, data: path.endsWith('/'+id) ? { status: 'completed', adjustment: receipt } : { enabled: true, adjustments: [], total: 0, page: 1 } }));
  wrap(); fireEvent.click(await screen.findByRole('button', { name: 'Check review status' }));
  await screen.findByText('R50.00 credited'); expect(mocks.post).not.toHaveBeenCalled();
});
it('master-password configuration is mandatory and expired quotes cannot commit', async () => {
  mocks.configured = false; await enter();
  fireEvent.change(screen.getByLabelText('Finance master password'), { target: { value: 'synthetic' } }); fireEvent.click(screen.getByRole('checkbox'));
  expect(screen.getByRole('button', { name: 'Credit R50.00' })).toBeDisabled();
});
it('hiding the finance form clears its password and final authorization', async () => {
  await enter(); fireEvent.change(screen.getByLabelText('Finance master password'), { target: { value: 'synthetic' } }); fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(screen.getByRole('button', { name: 'Hide credit form' })); fireEvent.click(screen.getByRole('button', { name: 'Add rand credit' }));
  expect(screen.getByLabelText('Finance master password')).toHaveValue(''); expect(screen.getByRole('checkbox')).not.toBeChecked();
});
it('handles expired recovery without silently recording another amount', async () => {
  sessionStorage.setItem('rand-credit-review:3:1', id);
  mocks.get.mockResolvedValue({ success: true, data: { enabled: true, adjustments: [], status: 'not_completed', quote, expired: true } });
  wrap(); fireEvent.click(await screen.findByRole('button', { name: 'Check review status' })); await screen.findByText(/This review expired/);
  expect(screen.getByRole('button', { name: 'Credit R50.00' })).toBeDisabled(); expect(mocks.post).not.toHaveBeenCalled();
});
it('allows customer lookup and unallocated deposits without requiring an order', async () => {
  wrap(<CashAdjustmentPanel />); await screen.findByText('No standalone rand credits recorded.'); fireEvent.click(screen.getByRole('button', { name: 'Add rand credit' }));
  fireEvent.change(screen.getByLabelText('Customer'), { target: { value: 'customer' } }); fireEvent.click(screen.getByRole('button', { name: 'Find customer for rand credit' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Test Customer · customer@example.invalid' }));
  fireEvent.change(screen.getByLabelText('Source'), { target: { value: 'deposit' } }); expect(screen.queryByLabelText('Completed EFT order reference')).not.toBeInTheDocument();
  expect(mocks.get).toHaveBeenCalledWith(base+'/accounts', { search: 'customer' }); expect(mocks.post).not.toHaveBeenCalled();
});
it('feature disabled prevents new reviews while preserving read-only history', async () => {
  mocks.get.mockResolvedValue({ success: true, data: { enabled: false, adjustments: [receipt], total: 1, page: 1 } }); wrap();
  await screen.findByText(/Standalone rand credits are not active/); expect(screen.getByText(receipt.reference+' · '+receipt.created_at)).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Add rand credit' })); expect(screen.getByRole('button', { name: 'Review rand credit' })).toBeDisabled();
});
it('password can be inspected without trimming spaces or exposing it to storage', () => {
  const onChange = vi.fn(); wrap(<FinancePasswordField value="  synthetic password  " onChange={onChange} />);
  const field = screen.getByLabelText('Finance master password'); expect(field).toHaveAttribute('type', 'password');
  fireEvent.click(screen.getByRole('button', { name: 'Show finance password' })); expect(field).toHaveAttribute('type', 'text'); expect(field).toHaveValue('  synthetic password  ');
  fireEvent.change(field, { target: { value: ' spaces ' } }); expect(onChange).toHaveBeenCalledWith(' spaces '); expect(sessionStorage.length).toBe(0);
});
