import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Copy, RefreshCw } from 'lucide-react';
import { Footer } from '@/components/layout/Footer';
import { SignedInPublicHeader } from '@/components/layout/PublicChrome';
import { Button } from '@/components/ui/button';
import { creditPricing } from '@/lib/credit-purchases';
import '@/seo/public-seo.css';

interface Banking { available: boolean; bank_details: Record<string, string> | null }
export default function BankingDetails() {
 const [banking, setBanking] = useState<Banking | null>(null); const [error, setError] = useState(false);
 const [attempt, setAttempt] = useState(0); const [copied, setCopied] = useState('');
 // Static public snapshots contain instructions, never potentially stale account
 // numbers. Real visitors load the same active configuration used by checkout.
 const prerender = typeof window !== 'undefined' && (window as Window & { __IEOSUIA_PUBLIC_PRERENDER__?: boolean }).__IEOSUIA_PUBLIC_PRERENDER__;
 useEffect(() => {
  if (prerender) return;
  const controller = new AbortController(); setError(false); setBanking(null);
  const timeout = setTimeout(() => controller.abort(), 15000);
  // Keep the host's same-site verification cookie. This is still a public read:
  // no Authorization header, app token or account/order identifier is sent.
  void fetch('/api/public/banking-details', { signal: controller.signal, cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(async response => {
   if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Banking details unavailable');
   const json = await response.json(); const data = json.data ?? json;
   if (!json.success || typeof data.available !== 'boolean' || (data.available && !['bank_name','account_name','account_number','branch_code'].every(key => typeof data.bank_details?.[key] === 'string' && data.bank_details[key].trim()))) throw new Error('Invalid banking details');
   if (!controller.signal.aborted) setBanking(data);
  }).catch(() => { if (!controller.signal.aborted) setError(true); }).finally(() => clearTimeout(timeout));
  // A timed-out request must show a recovery action, never an endless loader.
  const abort = () => setError(true); controller.signal.addEventListener('abort', abort);
  return () => { clearTimeout(timeout); controller.signal.removeEventListener('abort', abort); controller.abort(); };
 }, [attempt, prerender]);
 const copy = async (label: string, value: string) => { try { await navigator.clipboard.writeText(value); setCopied(`${label} copied`); } catch { setCopied('Please select and copy the displayed value.'); } };
 const bank = banking?.bank_details;
 return <><SignedInPublicHeader/><main className="public-guide"><h1>Banking Details &amp; EFT Payments</h1><p className="guide-lead">View our direct-transfer details without signing in. Create an SMS credit purchase first so you have the correct amount and a unique payment reference.</p>
 <section aria-labelledby="bank-account-heading"><h2 id="bank-account-heading">IEOSUIA bank account</h2>
 {prerender ? <p>Open this page with JavaScript enabled to load the current banking details securely. For assistance, email <a href="mailto:sms@ieosuia.com">sms@ieosuia.com</a>.</p> : error ? <div role="alert"><p>We could not load the current banking details. Do not use details from an old screenshot. Please retry or contact <a href="mailto:sms@ieosuia.com">sms@ieosuia.com</a>.</p><Button variant="outline" onClick={() => setAttempt(value => value + 1)}><RefreshCw className="mr-2 h-4 w-4"/>Retry banking details</Button></div> : !banking ? <p role="status">Loading current banking details...</p> : !banking.available ? <p role="status">Direct EFT instructions are currently unavailable. Contact <a href="mailto:sms@ieosuia.com">sms@ieosuia.com</a> before making a transfer.</p> : <dl className="space-y-3">{Object.entries({ Bank: bank?.bank_name, 'Account holder': bank?.account_name, 'Account number': bank?.account_number, 'Account type': bank?.account_type, 'Branch code': bank?.branch_code, 'SWIFT code': bank?.swift_code }).filter(([, value]) => value).map(([label, value]) => <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-3" key={label}><dt>{label}</dt><dd className="flex min-w-0 items-center gap-2"><strong className="break-all">{value}</strong><Button variant="ghost" size="icon" aria-label={`Copy ${label}`} onClick={() => void copy(label, value!)}><Copy className="h-4 w-4"/></Button></dd></div>)}</dl>}
 <p role="status" aria-live="polite" className="text-sm">{copied}</p></section>
 <section><h2>What payment reference should I use?</h2><p><strong>Use the unique SMS purchase reference starting with SMS-.</strong> Find it in Wallet and your bank-transfer instruction email after creating a credit purchase. Copy it exactly; do not use a generic reference such as “SMS” or “IEOSUIA”.</p><p>If your bank cannot accept the order reference, use your <strong>registered SMS Portal email address</strong> and include the SMS order reference with your proof of payment.</p><p>Do not have a reference? <Link to="/wallet#buy-credits">Create a credit purchase</Link> before paying. New customers can <Link to="/register">create an account</Link>. Do not pay against a voided or rejected request.</p></section>
 <section><h2>Send proof and confirm your credits</h2><p>Upload your proof in Wallet or email it to <a href="mailto:sms@ieosuia.com?subject=SMS%20proof%20of%20payment">sms@ieosuia.com</a>. Include your SMS order reference, registered account email and amount paid.</p><p><strong>Credits are added after cleared funds have been verified.</strong> Proof of payment alone does not confirm clearance. Pay the exact quoted order total, including any bank-transfer saving. We do not charge VAT. Your bank may charge an immediate-transfer fee.</p><p>If you already paid against a closed request, contact sms@ieosuia.com with your proof before creating or paying another request.</p></section>
 <section><h2>Larger credit purchases</h2><p>Purchases above 50,000 credits require direct bank-transfer EFT. For more than 500,000 credits, contact <a href={`mailto:${creditPricing.sales_email}`}>{creditPricing.sales_email}</a> for a quote before transferring money.</p><Link to="/pricing">Review SMS prices and calculate your purchase</Link></section>
 <nav className="guide-links" aria-label="Payment help"><Link to="/wallet#buy-credits">Create a credit purchase</Link><Link to="/support">Payment support</Link><Link to="/contact">Contact us</Link></nav></main><Footer variant="simple"/></>;
}
