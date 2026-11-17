import { api } from '@/lib/api';

export interface CashCustomer { id: number | string; name: string; email: string }
export interface RandBalance { balance_cents: number; reserved_cents: number; available_cents: number }
export interface CashReview {
  id: string; user_id: number; customer: CashCustomer; source_kind: 'overpayment' | 'deposit';
  order_reference: string | null; bank_reference: string; cleared_cents: number;
  recorded_cents: number; amount_cents: number; reason: string; expires_at: number; cash_balance: RandBalance;
}
export interface RandReceipt extends Omit<CashReview, 'customer' | 'expires_at' | 'cash_balance'> {
  reference: string; status: 'completed'; balance_before_cents: number; balance_after_cents: number;
  created_at: string; customer_name?: string; customer_email?: string;
}
export interface RandHistory { enabled: boolean; adjustments: RandReceipt[]; total: number; total_credited_cents?: number; page: number; cash_balance: RandBalance | null }
export interface RandDraft {
  user_id: string; source_kind: 'overpayment' | 'deposit'; order_reference: string;
  bank_reference: string; cleared_amount: string; reason: string; funds_verified: boolean; request_id: string;
}
const base = '/admin/operations/cash-adjustments';
async function unwrap<T>(r: { data?: T }): Promise<T> { return (r.data ?? r) as T; }
export const cashAdjustmentsApi = {
  history: async (user?: string, page = 1) => unwrap<RandHistory>(await api.get<RandHistory>(base, { ...(user ? { user_id: user } : {}), page: String(page) })),
  accounts: async (search: string) => (await unwrap<{ accounts: CashCustomer[] }>(await api.get<{ accounts: CashCustomer[] }>(base + '/accounts', { search }))).accounts,
  quote: async (input: RandDraft) => (await unwrap<{ quote: CashReview }>(await api.post<{ quote: CashReview }>(base + '/quote', input))).quote,
  commit: async (quote_id: string, master_password: string) => (await unwrap<{ adjustment: RandReceipt }>(await api.post<{ adjustment: RandReceipt }>(base + '/commit', { quote_id, master_password, confirmed: true }))).adjustment,
  status: async (id: string) => unwrap<{ status: 'completed' | 'not_completed'; adjustment?: RandReceipt; quote?: CashReview; expired?: boolean }>(await api.get(base + '/' + id)),
};
