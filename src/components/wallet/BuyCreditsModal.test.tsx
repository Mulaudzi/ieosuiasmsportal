import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { BuyCreditsModal } from './BuyCreditsModal';
import { BankTransferReview } from './BankTransferReview';
import { CreditApprovalSettings } from './CreditApprovalSettings';

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), upload: vi.fn(), error: vi.fn() }));
vi.mock('@/lib/api', () => ({ api: { get: mocks.get, post: mocks.post, put: mocks.put, upload: mocks.upload }, handleApiError: mocks.error, downloadAuthenticated: vi.fn() }));
vi.mock('@/hooks/use-toast', () => ({ toast: vi.fn() }));
vi.mock('@/hooks/useAuth', () => ({useAuth: () => ({user: {id: 'purchase-test'}})}));
const order = { id: 1, reference: 'SMS-TEST123', credits: 1000, unit_cents: 31, subtotal_cents: 31000, discount_cents: 310, total_cents: 30690, status: 'awaiting_payment', payment_method: 'eft', bank_details: { bank_name: 'FNB', account_name: 'Test Account', account_number: '123456', branch_code: '255355' }, proof_email: 'sms@ieosuia.com', proofs: [], customer_email: 'test@example.invalid', customer_name: 'Test Customer' };
const approvalStatus = { available: true, configured: true, is_owner: false, owner_email: 'lufuno@ieosuia.com', updated_at: null };
beforeEach(() => { vi.clearAllMocks(); mocks.get.mockImplementation(async (path: string) => path.endsWith('credit-approval-security') ? { success: true, ...approvalStatus } : { success: true, purchases_available: true, direct_transfer_available: true }); mocks.post.mockResolvedValue({ success: true, order, instructions_email_sent: true }); mocks.put.mockResolvedValue({ success: true }); });
afterEach(() => {cleanup();localStorage.clear();});
function wrap(node: React.ReactNode) { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>{node}</QueryClientProvider>); }
it('blocks invalid quantities and suggests a cheaper larger order at a price break', async () => {
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  const next = await screen.findByRole('button', { name: 'Choose payment method' });
  await waitFor(() => expect(next).toBeEnabled());
  expect(screen.getByText('We do not charge VAT.')).toBeVisible();
  expect(screen.queryByText(/VAT[ -]?registered/i)).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: /101 credits cost/ })).toBeVisible();
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '29' } }); expect(next).toBeDisabled();
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '30' } }); expect(next).toBeEnabled();
});
it('shows the direct saving before submitting and freezes the requested quantity', async () => {
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Choose payment method' })).toBeEnabled());
  fireEvent.change(screen.getByRole('spinbutton'), { target: { value: '1000' } });
  fireEvent.click(screen.getByRole('button', { name: 'Choose payment method' }));
  fireEvent.click(screen.getByRole('button', { name: /Pay directly to our FNB account and save R3.10/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Create bank transfer order' }));
  await screen.findAllByText('SMS-TEST123');
  expect(mocks.post).toHaveBeenCalledWith('/wallet/buy', expect.objectContaining({ credits: 1000, payment_method: 'eft', quoted_total_cents: 30690, idempotency_key: expect.any(String) }));
  expect(screen.getByRole('link', { name: 'Or email sms@ieosuia.com' })).toHaveAttribute('href', expect.stringContaining('SMS-TEST123'));
  expect(screen.getByText(/Uploading a receipt does not add credits automatically/)).toBeVisible();
});
it('keeps online checkout available at exactly 50,000 credits', async () => {
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Choose payment method' })).toBeEnabled());
  fireEvent.change(screen.getByRole('spinbutton'), {target: {value: '50000'}});
  fireEvent.click(screen.getByRole('button', {name: 'Choose payment method'}));
  expect(screen.getByRole('button', {name: /Pay online/})).toBeVisible();
});
it.each([50001, 250001, 500000])('requires direct EFT for %i credits and submits no online payment', async credits => {
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  await waitFor(() => expect(screen.getByRole('button', {name: 'Choose payment method'})).toBeEnabled());
  fireEvent.change(screen.getByRole('spinbutton'), {target: {value: String(credits)}});
  if (credits >= 250001) expect(screen.getByText('R0.22', {exact: true})).toBeVisible();
  fireEvent.click(screen.getByRole('button', {name: 'Choose payment method'}));
  expect(screen.queryByRole('button', {name: /Pay online/})).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', {name: 'Create bank transfer order'}));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/wallet/buy', expect.objectContaining({credits, payment_method: 'eft'})));
});
it('blocks self-service above 500,000 and links directly to sales', async () => {
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  await waitFor(() => expect(screen.getByRole('button', {name: 'Choose payment method'})).toBeEnabled());
  fireEvent.change(screen.getByRole('spinbutton'), {target: {value: '500001'}});
  expect(screen.getByRole('button', {name: 'Choose payment method'})).toBeDisabled();
  expect(screen.getByRole('link', {name: 'sms@ieosuia.com'})).toHaveAttribute('href', 'mailto:sms@ieosuia.com');
  expect(screen.queryByText('Total to pay')).not.toBeInTheDocument();
  expect(mocks.post).not.toHaveBeenCalled();
});
it('forces an older online-payment draft above 50,000 to direct EFT', async () => {
  localStorage.setItem('sms-purchase-draft:v1:purchase-test', JSON.stringify({payload: {version: 1, credits: 50001, step: 'payment', method: 'payos', requestKey: 'old-online-draft-0001'}}));
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  const submit = screen.getByRole('button', {name: 'Create bank transfer order'});
  await waitFor(() => expect(submit).toBeEnabled());
  expect(screen.queryByRole('button', {name: /Pay online/})).not.toBeInTheDocument();
  fireEvent.click(submit);
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/wallet/buy', expect.objectContaining({credits: 50001, payment_method: 'eft'})));
});
it('does not fall back to online checkout when required direct EFT is unavailable', async () => {
  mocks.get.mockResolvedValue({purchases_available: true, direct_transfer_available: false});
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  await waitFor(() => expect(screen.getByRole('button', {name: 'Choose payment method'})).toBeEnabled());
  fireEvent.change(screen.getByRole('spinbutton'), {target: {value: '50001'}});
  expect(screen.getByRole('button', {name: 'Choose payment method'})).toBeDisabled();
  expect(screen.getByRole('alert')).toHaveTextContent('Direct bank transfers are currently unavailable');
  expect(mocks.post).not.toHaveBeenCalled();
});
it('retains the request key when retrying a failed initiation', async () => {
  mocks.post.mockRejectedValue(new Error('Connection interrupted'));
  wrap(<BuyCreditsModal open onOpenChange={() => {}} />);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Choose payment method' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Choose payment method' }));
  fireEvent.click(screen.getByRole('button', { name: /Pay directly/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Create bank transfer order' }));
  await waitFor(() => expect(mocks.error).toHaveBeenCalledTimes(1));
  fireEvent.click(screen.getByRole('button', { name: 'Create bank transfer order' }));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledTimes(2));
  expect(mocks.post.mock.calls[0][1].idempotency_key).toBe(mocks.post.mock.calls[1][1].idempotency_key);
});
it('admin approval requires an amount, bank reference, note, funds confirmation and master password', async () => {
  mocks.get.mockImplementation(async (path: string) => path.endsWith('credit-approval-security') ? approvalStatus : { success: true, orders: [order], total: 1 });
  wrap(<BankTransferReview onReviewed={() => {}} />);
  fireEvent.click(await screen.findByRole('button', { name: 'Review payment' }));
  const approve = screen.getByRole('button', { name: 'Approve and add 1,000 credits' }); expect(approve).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Amount cleared in FNB (ZAR)'), { target: { value: '306.90' } });
  fireEvent.change(screen.getByLabelText('Unique bank transaction ID'), { target: { value: 'FNB-001' } });
  fireEvent.change(screen.getByLabelText('Review note / reason for rejection'), { target: { value: 'Matched cleared funds' } });
  expect(approve).toBeDisabled(); fireEvent.click(screen.getByRole('checkbox')); expect(approve).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Master password'), { target: { value: 'fixture-master-approval-123' } }); expect(approve).toBeEnabled();
  fireEvent.click(approve);
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/admin/operations/purchase-orders/1/review', expect.objectContaining({ amount_received: '306.90', funds_verified: true, bank_reference: 'FNB-001', master_password: 'fixture-master-approval-123' })));
});

it('hides master password management from ordinary admins', async () => {
  wrap(<CreditApprovalSettings />);
  await screen.findByText(/Only the verified owner account/);
  expect(screen.queryByRole('button', { name: 'Change master password' })).not.toBeInTheDocument();
});

it('allows owner setup, validates matching passphrases and clears the form after saving', async () => {
  mocks.get.mockResolvedValue({ ...approvalStatus, is_owner: true, configured: false });
  wrap(<CreditApprovalSettings />);
  fireEvent.click(await screen.findByRole('button', { name: 'Set master password' }));
  const save = screen.getByRole('button', { name: 'Save master password' }); expect(save).toBeDisabled();
  fireEvent.change(screen.getByLabelText('New master password'), { target: { value: 'fixture-new-master-123' } });
  fireEvent.change(screen.getByLabelText('Confirm new master password'), { target: { value: 'mismatch' } }); expect(save).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Confirm new master password'), { target: { value: 'fixture-new-master-123' } }); expect(save).toBeEnabled();
  fireEvent.click(save);
  await waitFor(() => expect(mocks.put).toHaveBeenCalledWith('/admin/operations/credit-approval-security', { current_password: '', new_password: 'fixture-new-master-123', confirm_password: 'fixture-new-master-123' }));
  await waitFor(() => expect(screen.queryByLabelText('New master password')).not.toBeInTheDocument());
});

it('requires the current master password for owner rotation', async () => {
  mocks.get.mockResolvedValue({ ...approvalStatus, is_owner: true });
  wrap(<CreditApprovalSettings />);
  fireEvent.click(await screen.findByRole('button', { name: 'Change master password' }));
  fireEvent.change(screen.getByLabelText('New master password'), { target: { value: 'fixture-new-master-123' } });
  fireEvent.change(screen.getByLabelText('Confirm new master password'), { target: { value: 'fixture-new-master-123' } });
  const save = screen.getByRole('button', { name: 'Save master password' }); expect(save).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Current master password'), { target: { value: 'fixture-current-master' } }); expect(save).toBeEnabled();
});

it('does not offer another approval for a completed transfer', async () => {
  mocks.get.mockImplementation(async (path: string) => path.endsWith('credit-approval-security') ? approvalStatus : { orders: [{ ...order, status: 'completed', reviewed_at: '2026-10-19', reviewer_name: 'Reviewer', bank_reference: 'BANK-001' }], total: 1 });
  wrap(<BankTransferReview onReviewed={() => {}} />);
  await screen.findByText(/Reviewed by Reviewer/);
  expect(screen.queryByRole('button', { name: 'Review payment' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Void unpaid request' })).not.toBeInTheDocument();
});

it('voids an unpaid EFT request only after an explicit reason and no-funds confirmation', async () => {
  mocks.get.mockImplementation(async (path: string) => path.endsWith('credit-approval-security') ? approvalStatus : { success: true, orders: [order], total: 1 });
  wrap(<BankTransferReview onReviewed={() => {}} />);
  fireEvent.click(await screen.findByRole('button', { name: 'Void unpaid request' }));
  expect(screen.getByRole('alertdialog')).toHaveTextContent('No credits will be added or removed');
  const confirm = screen.getByRole('button', { name: 'Confirm void unpaid request' }); expect(confirm).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Reason for voiding'), { target: { value: 'No funds received after checking bank' } }); expect(confirm).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: /confirmed no funds were received/ })); expect(confirm).toBeEnabled();
  expect(mocks.post).not.toHaveBeenCalled(); fireEvent.click(confirm);
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/admin/operations/purchase-orders/1/review', { decision: 'void', note: 'No funds received after checking bank', funds_not_received: true }));
  await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument());
});
it('keeps the request when the void dialog is cancelled', async () => {
  mocks.get.mockImplementation(async (path: string) => path.endsWith('credit-approval-security') ? approvalStatus : { orders: [order], total: 1 });
  wrap(<BankTransferReview onReviewed={() => {}} />); fireEvent.click(await screen.findByRole('button', { name: 'Void unpaid request' }));
  fireEvent.click(screen.getByRole('button', { name: 'Keep request' }));
  expect(mocks.post).not.toHaveBeenCalled(); expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
});
it('shows voided history without approval or repeat-void controls', async () => {
  mocks.get.mockImplementation(async (path: string) => path.endsWith('credit-approval-security') ? approvalStatus : { orders: [{ ...order, status: 'voided' }], total: 1 });
  wrap(<BankTransferReview onReviewed={() => {}} />); expect(await screen.findByText('Voided - unpaid request')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Review payment' })).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Void unpaid request' })).not.toBeInTheDocument();
});
