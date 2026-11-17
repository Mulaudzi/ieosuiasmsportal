import { creditPricing } from '@/lib/credit-purchases';

export function CreditPurchasePolicy({ credits }: { credits?: number }) {
  if (credits !== undefined && credits <= creditPricing.maximum) return null;
  return <p className="text-sm">Purchases above {creditPricing.maximum.toLocaleString('en-GB')} credits require direct bank deposit (not online card or Instant EFT). Contact sales at <a className="underline" href={`mailto:${creditPricing.sales_email}`}>{creditPricing.sales_email}</a> for a quote before paying.</p>;
}
