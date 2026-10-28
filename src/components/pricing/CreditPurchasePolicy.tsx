import { creditPricing } from '@/lib/credit-purchases';

export function CreditPurchasePolicy() {
  return <p className="text-sm">Purchases above {creditPricing.online_maximum.toLocaleString('en-GB')} credits are payable by direct bank-transfer EFT only (not online card or Instant EFT). For more than {creditPricing.maximum.toLocaleString('en-GB')} credits, contact sales at <a className="underline" href={`mailto:${creditPricing.sales_email}`}>{creditPricing.sales_email}</a>.</p>;
}
