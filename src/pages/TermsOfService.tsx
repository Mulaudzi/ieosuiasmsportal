import { Link } from "react-router-dom";
import { ArrowLeft } from "lucide-react";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";

export default function TermsOfService() {
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

        <h1 className="text-3xl font-bold text-foreground mb-6">Terms of Service</h1>
        <p className="text-muted-foreground mb-8">Last updated: January 13, 2026</p>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-6">
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">1. Acceptance of Terms</h2>
            <p className="text-muted-foreground leading-relaxed">
              By accessing and using IEOSUIA SMS Portal ("the Service"), you accept and agree to be bound by 
              the terms and provision of this agreement. If you do not agree to abide by the above, 
              please do not use this service.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">2. Description of Service</h2>
            <p className="text-muted-foreground leading-relaxed">
              IEOSUIA SMS Portal provides SMS and email messaging services that allow users to send text messages 
              and emails to their contacts. The service includes features such as contact management, message templates, 
              campaign analytics, and delivery reports.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">3. User Responsibilities</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">As a user of this service, you agree to:</p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>Provide accurate and complete registration information</li>
              <li>Maintain the security of your account credentials</li>
              <li>Use the service only for lawful purposes</li>
              <li>Comply with all applicable laws and regulations regarding SMS and email communications</li>
              <li>Obtain proper consent before sending messages to recipients</li>
              <li>Honor opt-out requests from recipients promptly</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">4. Prohibited Uses</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">You may not use the service to:</p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>Send spam or unsolicited messages</li>
              <li>Send messages containing illegal, harmful, or offensive content</li>
              <li>Violate any applicable anti-spam laws or regulations</li>
              <li>Impersonate any person or entity</li>
              <li>Interfere with or disrupt the service</li>
              <li>Attempt to gain unauthorized access to any systems</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">5. Credits and Payment</h2>
            <p className="text-muted-foreground leading-relaxed">
              The service operates on a credit-based pay-as-you-go system. Credits are required to send messages 
              and must be purchased in advance. Credits are non-refundable and non-transferable. 
              Unused credits do not expire unless the account is terminated.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">6. Service Availability</h2>
            <p className="text-muted-foreground leading-relaxed">
              We strive to maintain high availability of the service but do not guarantee uninterrupted access. 
              The service may be temporarily unavailable for maintenance, upgrades, or due to circumstances 
              beyond our control.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">7. Limitation of Liability</h2>
            <p className="text-muted-foreground leading-relaxed">
              IEOSUIA SMS Portal shall not be liable for any indirect, incidental, special, consequential, 
              or punitive damages resulting from your use of or inability to use the service. 
              Our total liability shall not exceed the amount paid by you for the service in the 
              preceding twelve months.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">8. Account Termination</h2>
            <p className="text-muted-foreground leading-relaxed">
              We reserve the right to suspend or terminate your account at any time for violation of 
              these terms or for any other reason at our discretion. Upon termination, your right to 
              use the service will immediately cease.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">9. Changes to Terms</h2>
            <p className="text-muted-foreground leading-relaxed">
              We reserve the right to modify these terms at any time. We will notify users of any 
              material changes via email or through the service. Your continued use of the service 
              after such modifications constitutes acceptance of the updated terms.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">10. Governing Law</h2>
            <p className="text-muted-foreground leading-relaxed">
              These terms shall be governed by and construed in accordance with the laws of South Africa. 
              Any disputes arising from these terms shall be subject to the exclusive jurisdiction of the 
              courts of South Africa.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">11. Contact Information</h2>
            <p className="text-muted-foreground leading-relaxed">
              If you have any questions about these Terms of Service, please contact us at{" "}
              <a href="mailto:support@ieosuia.com" className="text-primary hover:underline">support@ieosuia.com</a>{" "}
              or visit our <Link to="/contact" className="text-primary hover:underline">Contact page</Link>.
            </p>
          </section>
        </div>
      </div>

      <Footer variant="simple" />
    </div>
  );
}