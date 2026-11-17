import rules from '../../api/domain/credit-purchase-pricing.json';
export { rules as creditPricing };
export type PaymentMethod = 'payos' | 'eft';
export function creditQuote(credits: number, method: PaymentMethod = 'payos') {
  if (!Number.isSafeInteger(credits) || credits < rules.minimum || credits > rules.maximum) return null;
  if (method === 'payos' && credits > rules.online_maximum) return null;
  const tier = rules.tiers.find(t => credits <= t.max)!;
  const subtotal = credits * tier.cents;
  const discount = method === 'eft' ? Math.floor(Math.max(200, Math.round(subtotal * .02)) / 2) : 0;
  return { credits, unit_cents: tier.cents, subtotal_cents: subtotal, discount_cents: discount,
    total_cents: subtotal - discount, pricing_version: rules.version, payment_method: method };
}
export const rand = (cents: number) => `R${(cents / 100).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
export const purchaseStatus = (status: string) => ({ awaiting_payment: 'Awaiting transfer', awaiting_verification: 'Awaiting verification', completed: 'Credits added', rejected: 'Needs attention', voided: 'Voided - unpaid request' })[status] || status;
export interface CashBalance { enabled: boolean; balance_cents: number; reserved_cents: number; available_cents: number }
export interface CashPurchaseQuote { unit_cents: number; subtotal_cents: number; discount_cents: number; credits: number; total_cents: number; sms_total_cents: number; cash_applied_cents: number; balance_choice: 'keep_credits' | 'keep_payment' }
export interface PurchaseOrder {
  cash_adjustment?: { choice: string; applied_cents: number; sms_total_cents: number; payment_cents: number; received_cents: number | null; status: string } | null;
  id: number; reference: string; credits: number; unit_cents: number; subtotal_cents: number;
  discount_cents: number; total_cents: number; status: string; payment_method: PaymentMethod;
  created_at: string; expires_at: string; review_note?: string; customer_email?: string; customer_name?: string;
  expired?: boolean;
  reviewed_at?: string | null; reviewer_name?: string | null; bank_reference?: string | null;
  bank_details: { bank_name: string; account_name: string; account_number: string; branch_code: string } | null;
  proof_email: string; proofs: { id: number; mime_type: string; byte_size: number; created_at: string }[];
}
export interface PurchaseOptions { cash_balance?: CashBalance; purchases_available: boolean; direct_transfer_available: boolean; proof_email: string }
