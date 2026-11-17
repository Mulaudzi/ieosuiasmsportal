import { Link } from 'react-router-dom';
import { ArrowLeft, Mail } from 'lucide-react';
import { SignedInPublicHeader } from '@/components/layout/PublicChrome';
import { Footer } from '@/components/layout/Footer';
import { Button } from '@/components/ui/button';

const requestEmail = 'mailto:sms@ieosuia.com?subject=Data%20deletion%20request%20-%20IEOSUIA%20Messaging';
const sectionStyle = 'space-y-4 rounded-2xl border bg-card p-5 shadow-sm sm:p-7';
const headingStyle = 'text-xl font-semibold text-foreground';

export default function DataDeletion() {
  return <div className="flex min-h-screen flex-col bg-background">
    <SignedInPublicHeader />
    <div className="mx-auto w-full max-w-4xl flex-1 px-6 py-12">
      <Link to="/" className="mb-8 inline-flex items-center gap-2 text-primary hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />Back to Home
      </Link>
      <h1 className="mb-6 text-3xl font-bold text-foreground">User data deletion</h1>
      <p className="mb-4 leading-relaxed text-muted-foreground">Request deletion of personal data held by IEOSUIA Messaging, the messaging service operated by <strong className="text-foreground">IEOSUIA (Pty) Ltd</strong> in South Africa. These instructions cover portal data and data received through our Meta and WhatsApp integration.</p>
      <p className="mb-8 text-sm text-muted-foreground">Last updated: 4 November 2026</p>
      <div className="space-y-6 leading-relaxed text-muted-foreground">
        <section aria-labelledby="request-title" className={sectionStyle}>
          <h2 id="request-title" className={headingStyle}>1. Send a deletion request</h2>
          <p>Email <a href="mailto:sms@ieosuia.com" className="text-primary underline">sms@ieosuia.com</a>, preferably from the email address registered to your IEOSUIA portal account. You do not need to sign in to view these instructions or send a request.</p>
          <div><p>Use this subject:</p><p className="mt-2 break-words rounded-xl border bg-muted/50 p-4 font-medium text-foreground">Data deletion request - IEOSUIA Messaging</p></div>
          <p>Include only the details needed to find the relevant data:</p>
          <ul className="list-disc space-y-2 pl-6">
            <li>Your portal account email address and, if applicable, your business name.</li>
            <li>The relevant WhatsApp business number, including its country code, if the request concerns a connected number.</li>
            <li>What you want deleted: your Meta/WhatsApp connection data, specific personal records, or your whole IEOSUIA portal account.</li>
          </ul>
          <Button asChild className="h-auto min-h-11 w-full whitespace-normal sm:w-auto"><a href={requestEmail}><Mail className="mr-2 h-4 w-4 shrink-0" aria-hidden="true" />Email a deletion request</a></Button>
          <p className="text-sm">The button opens your email application. Send the email to submit the request; opening this page or the email application does not submit anything.</p>
          <p className="rounded-xl border bg-muted/50 p-4">Do not include passwords, Meta access tokens, app secrets, verification codes or identity documents in your initial request. We will ask for any additional verification needed to protect your account.</p>
          <h3 className="font-semibold text-foreground">If you received messages but do not have a portal account</h3>
          <p>Use the same email address above. Provide the number that received the message, the sending business name or number, and an approximate message date so we can identify the relevant records. You may also contact the sending business about data it holds.</p>
        </section>
        <section aria-labelledby="review-title" className={sectionStyle}>
          <h2 id="review-title" className={headingStyle}>2. Ownership verification and review</h2>
          <p>Requests are reviewed manually. Before deleting account or business data, we verify the requester's identity and authority over the relevant account, number or personal records. Sending a request does not authorize access to another customer's data.</p>
          <p>We reply by email with any verification steps, the scope of the request and the expected completion time. If you need an update, reply in the same email thread.</p>
        </section>
        <section aria-labelledby="scope-title" className={sectionStyle}>
          <h2 id="scope-title" className={headingStyle}>3. What deletion covers</h2>
          <p>Depending on your verified request and the data we hold, the review covers:</p>
          <ul className="list-disc space-y-2 pl-6">
            <li>Saved Meta authorization credentials and pending connection approvals.</li>
            <li>WhatsApp business-account and phone identifiers, business names, connection metadata and associated portal records.</li>
            <li>Personal information in stored WhatsApp messages, webhook receipts and delivery-status records, where applicable.</li>
            <li>Other portal account, contact and messaging data if your request includes those records.</li>
          </ul>
          <p>Where information must be retained for a legal obligation, security, fraud prevention or a dispute, we explain the information retained and the reason. We also explain any backup-related limits or delayed removal that apply to the request. We do not promise that all backup copies are erased immediately.</p>
          <p>Our completion response confirms the action taken and identifies any applicable retention limits. Deletion from IEOSUIA does not delete your Facebook or WhatsApp account, data independently held by Meta, or messages already held by recipients.</p>
        </section>
        <section aria-labelledby="disconnect-title" className={sectionStyle}>
          <h2 id="disconnect-title" className={headingStyle}>Disconnecting a WhatsApp number</h2>
          <p>If your account has access to WhatsApp connections, open its WhatsApp settings, choose the number, select <strong className="text-foreground">Disconnect</strong> and then <strong className="text-foreground">Confirm disconnect</strong>. Administrators use <Link to="/guymhan/whatsapp" className="text-primary underline">Guymhan → WhatsApp setup</Link>; customer connections, when available, are in <Link to="/settings?tab=whatsapp" className="text-primary underline">Settings → WhatsApp</Link>.</p>
          <p>Disconnecting clears the saved authorization credential for that number and cancels pending connection attempts. Connection records remain, so this is not a full personal-data deletion. Email a deletion request for the records you want removed.</p>
          <p>Your WhatsApp Business app account remains in place. Portal disconnect does not itself revoke permissions in Meta; you can also review and remove the app's authorization in Meta's business settings.</p>
        </section>
      </div>
    </div>
    <Footer variant="simple" />
  </div>;
}
