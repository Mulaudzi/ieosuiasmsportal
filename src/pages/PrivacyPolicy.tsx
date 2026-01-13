import { Link } from "react-router-dom";
import { ArrowLeft } from "lucide-react";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";

export default function PrivacyPolicy() {
  return (
    <div className="min-h-screen bg-background flex flex-col">
      <div className="flex-1 max-w-4xl mx-auto px-6 py-12">
        <div className="mb-8">
          <Logo size="md" linkTo="/landing" />
        </div>

        <Link to="/landing" className="inline-flex items-center gap-2 text-primary hover:underline mb-8">
          <ArrowLeft className="h-4 w-4" />
          Back to Home
        </Link>

        <h1 className="text-3xl font-bold text-foreground mb-6">Privacy Policy</h1>
        <p className="text-muted-foreground mb-8">Last updated: January 13, 2026</p>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-6">
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">1. Introduction</h2>
            <p className="text-muted-foreground leading-relaxed">
              IEOSUIA SMS Portal ("we", "our", or "us") is committed to protecting your privacy. 
              This Privacy Policy explains how we collect, use, disclose, and safeguard your information 
              when you use our SMS and email messaging service.
            </p>
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
              If you have any questions about this Privacy Policy or our data practices, please contact 
              us at <a href="mailto:support@ieosuia.com" className="text-primary hover:underline">support@ieosuia.com</a>{" "}
              or visit our <Link to="/contact" className="text-primary hover:underline">Contact page</Link>.
            </p>
          </section>
        </div>
      </div>

      <Footer variant="simple" />
    </div>
  );
}