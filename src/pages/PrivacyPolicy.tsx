import { Link } from "react-router-dom";
import { ArrowLeft } from "lucide-react";
import { SignedInPublicHeader } from "@/components/layout/PublicChrome";
import { Footer } from "@/components/layout/Footer";

export default function PrivacyPolicy() {
  return (
    <div className="min-h-screen bg-background flex flex-col">
      <div className="flex-1 max-w-4xl mx-auto px-6 py-12">
        <SignedInPublicHeader />

        <Link to="/landing" className="inline-flex items-center gap-2 text-primary hover:underline mb-8">
          <ArrowLeft className="h-4 w-4" />
          Back to Home
        </Link>

        <h1 className="text-3xl font-bold text-foreground mb-6">Privacy Policy</h1>
        <p className="text-muted-foreground mb-8">Last updated: November 13, 2026</p>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-6">
          <section><h2 className="text-xl font-semibold text-foreground mb-3">Responsible party</h2><p className="text-muted-foreground leading-relaxed">IEOSUIA (Pty) Ltd, 26 Rock Alder, Naturena, Johannesburg, 2095, South Africa. For privacy and deletion requests, contact <a href="mailto:sms@ieosuia.com" className="text-primary hover:underline">sms@ieosuia.com</a>.</p></section>
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">1. Introduction</h2>
            <p className="text-muted-foreground leading-relaxed">
              IEOSUIA SMS Portal ("we", "our", or "us") is committed to protecting your privacy. 
              This Privacy Policy explains how we collect, use, disclose, and safeguard your information 
              when you use our SMS, email and available WhatsApp Business messaging features. Our customer service currently supports South Africa.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">Meta and WhatsApp Business data</h2>
            <p className="text-muted-foreground leading-relaxed">When you choose to connect WhatsApp Business, Facebook Login for Business and Meta Embedded Signup let you authorize access to your business assets. This connection is separate from your IEOSUIA portal sign-in. We receive the authorization code, business access token and authorization details, WhatsApp Business Account and phone number identifiers, business/display names, phone numbers and token expiry information. We do not receive your Facebook password.</p>
            <p className="text-muted-foreground leading-relaxed mt-3">Where the WhatsApp workspace is enabled, we process template names, categories, languages, text and review status; the recipient number, template parameters and opt-in details you submit; Meta message identifiers; and signed webhook payloads, including incoming message content and sending, delivery, read or failure updates. We use this information to connect your chosen business, manage its templates, perform your requested messaging operations, show results, protect accounts and investigate errors. Connecting a number alone does not send messages.</p>
            <p className="text-muted-foreground leading-relaxed mt-3">Messages and template requests are sent to Meta to provide these features. Recipient contact information and message content needed for delivery are shared with Meta and the receiving party. Your business is responsible for having permission to contact its recipients and honoring opt-outs. WhatsApp access tokens are encrypted in portal storage and are not displayed to other customers.</p>
            <p className="text-muted-foreground leading-relaxed mt-3">Axxess DSL (Pty) Ltd provides hosting for the portal and its database. Serving South African customers does not mean all processing occurs only in South Africa: Meta and hosting providers may process data or provide support from other countries. This page does not promise a complete provider processing-country list.</p>
            <p className="text-muted-foreground leading-relaxed mt-3">Disconnecting WhatsApp from portal settings removes the stored connection credentials and cancels pending authorization attempts. Connection metadata, the account ownership reservation, operation history and webhook records may remain until reviewed for deletion or retention. WhatsApp records are not subject to an automatic 90-day deletion guarantee. Follow our <Link to="/data-deletion" className="text-primary hover:underline">data deletion instructions</Link> to request deletion of applicable records. We will verify your authority and explain the scope, expected timing and any justified retention or backup limitations. Disconnecting the portal does not delete your Meta/WhatsApp account or messages already held by Meta or recipients.</p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">Email campaign data</h2>
            <p className="text-muted-foreground leading-relaxed">Where email campaigns are enabled, Amazon Web Services (AWS), including Amazon SES, processes approved sender details, recipient email addresses, campaign content, consent evidence, delivery events and email preference records to provide the service. The campaign service is configured in the AWS Europe (Stockholm) region. Delivery also involves recipients' email providers, and provider support or other processing may occur outside South Africa or that region.</p>
            <p className="text-muted-foreground leading-relaxed mt-3">The portal retains account and financial authority. AWS campaign processing receives the account identifier, verified Reply-To address and the limited authorization information needed for the requested campaign. Customer messages include unsubscribe and abuse-report links. We retain suppression information to honor those choices and retain campaign, review and accounting evidence for service operation, reconciliation and abuse prevention.</p>
            <p className="text-muted-foreground leading-relaxed mt-3">Uploaded logos, images and attachments are stored privately in Amazon S3 in the Stockholm region. Amazon GuardDuty checks quarantined uploads for malware, and the service validates file types, inspects readable PDF text and records content-risk signals for independent review. Automated checks cannot identify all harmful or unwanted content. Authorized reviewers can inspect the exact files associated with a campaign through temporary links. Quarantined files are scheduled for deletion after 7 days and accepted files after 90 days; storage lifecycle deletion may take additional time. File access and sending eligibility expire separately from retained campaign and accounting evidence.</p>
            <p className="text-muted-foreground leading-relaxed mt-3">Email campaign records do not currently have automatic 90-day deletion. Use our <Link to="/data-deletion" className="text-primary hover:underline">data deletion instructions</Link> to request deletion. We will assess applicable records and explain any justified retention or backup limitations. Deletion cannot recall email already sent to recipients.</p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">2. Information We Collect</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">We collect the following types of information:</p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Account Information:</strong> Name, email address, phone number, company name</li>
              <li><strong>Contact Data:</strong> Phone numbers and email addresses of your message recipients</li>
              <li><strong>Message Content:</strong> The content of messages you send through our service</li>
              <li><strong>Usage Data:</strong> Campaign statistics, delivery reports, login history</li>
              <li><strong>Payment Information:</strong> Transaction records for credit purchases</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">3. How We Use Your Information</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">We use the collected information to:</p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>Provide and maintain our SMS and email messaging service</li>
              <li>Process transactions and send billing information</li>
              <li>Send service-related notifications</li>
              <li>Improve and personalize the user experience</li>
              <li>Monitor usage patterns and prevent abuse</li>
              <li>Comply with legal obligations</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">4. Data Retention</h2>
            <p className="text-muted-foreground leading-relaxed">
              We retain your personal information for as long as your account is active or as needed 
              to provide services. Message content is typically retained for 90 days for delivery 
              reporting purposes. We may retain certain information longer as required by law or for 
              legitimate business purposes.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">5. Data Sharing</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              We may share your information with:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>SMS Gateway Providers:</strong> To deliver your messages to recipients</li>
              <li><strong>Email Service Providers:</strong> To deliver your email campaigns</li>
              <li><strong>Payment Processors:</strong> To process credit purchases</li>
              <li><strong>Service Providers:</strong> Who assist in operating our service</li>
              <li><strong>Legal Authorities:</strong> When required by law or to protect our rights</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">6. Data Security</h2>
            <p className="text-muted-foreground leading-relaxed">
              We implement appropriate technical and organizational measures to protect your personal 
              information against unauthorized access, alteration, disclosure, or destruction. 
              This includes encryption, secure servers, and regular security assessments.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">7. Your Rights</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">Under POPIA and other applicable laws, you have the right to:</p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>Access your personal information</li>
              <li>Correct inaccurate data</li>
              <li>Request deletion of your data</li>
              <li>Export your data in a portable format</li>
              <li>Opt-out of marketing communications</li>
              <li>Withdraw consent where applicable</li>
              <li>Lodge a complaint with the Information Regulator</li>
            </ul>
            <p className="text-muted-foreground leading-relaxed mt-3">To request deletion of portal or Meta/WhatsApp data, follow our <Link to="/data-deletion" className="text-primary hover:underline">data deletion instructions</Link>.</p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">8. Cookies and Tracking</h2>
            <p className="text-muted-foreground leading-relaxed">
              We use cookies and similar technologies to maintain your session, remember your preferences, 
              and analyze usage patterns. You can control cookie settings through your browser. 
              For more information, please see our <Link to="/cookie-policy" className="text-primary hover:underline">Cookie Policy</Link>.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">9. Children's Privacy</h2>
            <p className="text-muted-foreground leading-relaxed">
              Our service is not intended for individuals under the age of 18. We do not knowingly 
              collect personal information from children. If we become aware that we have collected 
              data from a child, we will take steps to delete such information.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">10. International Transfers</h2>
            <p className="text-muted-foreground leading-relaxed">
              Your information may be transferred to and processed in countries other than your own. 
              We ensure appropriate safeguards are in place to protect your data in accordance with 
              POPIA and other applicable data protection laws.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">11. Changes to This Policy</h2>
            <p className="text-muted-foreground leading-relaxed">
              We may update this Privacy Policy from time to time. We will notify you of any material 
              changes by posting the new policy on this page and updating the "Last updated" date. 
              Your continued use of the service constitutes acceptance of the updated policy.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">12. Contact Us</h2>
            <p className="text-muted-foreground leading-relaxed">
              If you have any questions about this Privacy Policy or our data practices, please visit 
              our <Link to="/contact?purpose=support" className="text-primary hover:underline">Contact page</Link> to 
              reach our support team.
            </p>
          </section>
        </div>
      </div>

      <Footer variant="simple" />
    </div>
  );
}
