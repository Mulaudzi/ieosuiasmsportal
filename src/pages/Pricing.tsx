import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Footer } from '@/components/layout/Footer';
import { SignedInPublicHeader } from '@/components/layout/PublicChrome';
import '@/seo/public-seo.css';
export default function Pricing() {
 const [segments, setSegments] = useState('1000');
 const count = Math.max(0, Math.floor(Number(segments) || 0));
 return <><SignedInPublicHeader/><main className="public-guide">
 <h1>Prepaid SMS pricing in South Africa</h1><p className="guide-lead">R0.35 per billable SMS segment. Start with a minimum R10 credit purchase, then review each campaign's requirement before sending. SMS usage is prepaid, with no monthly subscription.</p>
 <section><h2>Messages and credits are different counts</h2><p>One SMS credit covers one billable segment for one recipient. Three recipients receiving two segments each require six credits, costing R2.10. A minimum R10 purchase covers 28 whole credits at the current rate; the remaining wallet value is retained.</p><p>Longer text, Unicode, personalisation and opt-out text can increase segments. The campaign preview calculates the requirement using the actual content and audience. Use that result rather than a character-count guess before confirming a send.</p></section>
 <section><h2>Estimate your SMS cost</h2><p>Enter the total billable segments across all recipients. This estimates sending cost, not handset deliveries. The minimum wallet purchase remains R10 even when a campaign costs less.</p><label htmlFor="sms-volume">Total billable SMS segments</label><input id="sms-volume" type="number" min="0" step="1" value={segments} onChange={event => setSegments(event.target.value)}/><output htmlFor="sms-volume" aria-live="polite">Estimated cost: R{(count * .35).toFixed(2)}</output><p>For personalised messages, review the final server-calculated requirement. No discount tiers or separate subscription packages are advertised here.</p></section>
 <section><h2>Buy credits and confirm payment</h2><p>Sign in and open Wallet to purchase through PayOS using the methods offered at checkout. Returning from checkout may initially show Payment Processing. Wait for the confirmed status and updated balance.</p><p>If payment status and balance do not reconcile, contact support with the reference. Do not make another payment merely to retry a callback or send anyone your card details or password.</p></section>
 <section><h2>When the campaign exceeds your balance</h2><p>Purchase more credits, remove selected recipients or fit the campaign to available credits. Excluded contacts remain in their saved groups. Recalculate after changes to content or audience.</p><p>A pending delivery report does not mean the provider did not accept the SMS. Review delivery details before retrying. This page does not promise automatic refunds for a particular delivery outcome.</p></section>
 <section><h2>Email Campaigns: Coming Soon</h2><p>Email campaign sending is unavailable in this release. There are no active email campaign prices or email-volume calculators to use.</p></section>
 <nav className="guide-links" aria-label="Pricing help"><Link to="/register">Start Messaging</Link><Link to="/delivery-reports">Understand delivery outcomes</Link><Link to="/faq">Read credit and payment questions</Link><Link to="/terms-of-service">Review service terms</Link><Link to="/contact?purpose=sales">Ask a pricing question</Link></nav>
 </main><Footer variant="simple"/></>;
}
