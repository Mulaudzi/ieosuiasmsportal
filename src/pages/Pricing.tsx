import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Footer } from '@/components/layout/Footer';
import { SignedInPublicHeader } from '@/components/layout/PublicChrome';
import '@/seo/public-seo.css';
import { creditPricing, creditQuote, rand } from '@/lib/credit-purchases';
import { CreditPricingTable } from '@/components/pricing/CreditPricingTable';
export default function Pricing() {
 const [segments, setSegments] = useState('1000');
 const count = Number(segments) || 0;
 const directOnly = count > creditPricing.online_maximum;
 const quote = creditQuote(count, directOnly ? 'eft' : 'payos');
 const transfer = creditQuote(count, 'eft');
 return <><SignedInPublicHeader/><main className="public-guide">
 <h1>Prepaid SMS pricing in South Africa</h1><p className="guide-lead">Buy from 30 credits for R10.50, with volume prices from R0.35 to R0.22 per credit. No monthly subscription. We do not charge VAT.</p>
 <section aria-label="Pay by EFT"><h2>Pay by EFT</h2><p>Our banking details and payment-reference instructions are available without signing in. Create your credit purchase before paying so the amount and reference match your order.</p><Link className="inline-flex rounded-lg border border-primary px-4 py-3 font-semibold text-primary" to="/banking-details">View banking details</Link></section>
 <section><h2>Messages and credits are different counts</h2><p>One SMS credit covers one billable segment for one recipient. Three recipients receiving two segments each require six credits. Your purchase quantity determines the price per credit; the rate for your range applies to every credit in that purchase.</p><p>Longer text, Unicode, personalisation and opt-out text can increase segments. The campaign preview calculates the credit requirement using the actual content and audience.</p></section>
 <section><h2>Volume pricing</h2><p>These prices are before the additional direct FNB transfer saving. Buy at least {creditPricing.minimum} credits, with no VAT added.</p><CreditPricingTable/></section>
 <section><h2>Calculate your credit purchase</h2><label htmlFor="sms-volume">SMS credits to buy</label><input id="sms-volume" type="number" min={creditPricing.minimum} max={creditPricing.maximum} step="1" value={segments} onChange={event => setSegments(event.target.value)}/>
 <output htmlFor="sms-volume" aria-live="polite">{quote ? `${count.toLocaleString()} credits: ${rand(quote.subtotal_cents)} at ${rand(quote.unit_cents)} each${directOnly ? ' before the bank transfer saving. Direct EFT only.' : ''}` : count > creditPricing.maximum ? `For more than 500,000 credits, contact sales at ${creditPricing.sales_email}.` : 'Choose a whole number between 30 and 500,000 credits.'}</output>
 {count > creditPricing.maximum && <p><a href={`mailto:${creditPricing.sales_email}`}>Contact sales: {creditPricing.sales_email}</a></p>}
 {transfer && <p>{directOnly ? 'Pay by direct FNB transfer only: ' : 'Where direct FNB transfers are available at checkout, pay '}{rand(transfer.total_cents)} and save {rand(transfer.discount_cents)} for the same credits. Credits are added after cleared funds are verified. Your bank may charge an immediate-transfer fee.</p>}<p>Volume discounts can make a larger purchase cost less at a price break. Review your exact total before paying.</p></section>
 <section><h2>Buy credits and confirm payment</h2><p>Sign in and open Wallet. Up to 50,000 credits, choose direct bank transfer or the methods offered by PayOS checkout. Above 50,000 credits, only direct bank-transfer EFT is available. Above 500,000 credits, contact sms@ieosuia.com. Returning from online checkout may initially show Payment Processing. Wait for the confirmed status and updated balance.</p><p>If payment status and balance do not reconcile, contact support with the reference. Do not make another payment merely to retry a callback or send anyone your card details or password.</p></section>
 <section><h2>When the campaign exceeds your balance</h2><p>Purchase more credits, remove selected recipients or fit the campaign to available credits. Excluded contacts remain in their saved groups. Recalculate after changes to content or audience.</p><p>A pending delivery report does not mean the provider did not accept the SMS. Review delivery details before retrying. This page does not promise automatic refunds for a particular delivery outcome.</p></section>
 <section><h2>Email Campaigns: Coming Soon</h2><p>Email campaign sending is unavailable in this release. There are no active email campaign prices or email-volume calculators to use.</p></section>
 <nav className="guide-links" aria-label="Pricing help"><Link to="/register">Start Messaging</Link><Link to="/delivery-reports">Understand delivery outcomes</Link><Link to="/faq">Read credit and payment questions</Link><Link to="/terms-of-service">Review service terms</Link><Link to="/contact?purpose=sales">Ask a pricing question</Link></nav>
 </main><Footer variant="simple"/></>;
}
