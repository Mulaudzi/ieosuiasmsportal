import { Link } from "react-router-dom";
import { ArrowLeft } from "lucide-react";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";

export default function CookiePolicy() {
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

        <h1 className="text-3xl font-bold text-foreground mb-6">Cookie Policy</h1>
        <p className="text-muted-foreground mb-8">Last updated: January 13, 2026</p>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-6">
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">1. What Are Cookies?</h2>
            <p className="text-muted-foreground leading-relaxed">
              Cookies are small text files that are placed on your computer or mobile device when you visit 
              a website. They are widely used to make websites work more efficiently and to provide information 
              to website owners. Cookies help us understand how you interact with our platform and enable us 
              to provide you with a better experience.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">2. How We Use Cookies</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              IEOSUIA SMS Portal uses cookies for the following purposes:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Authentication:</strong> To recognize you when you sign in and keep you logged in</li>
              <li><strong>Security:</strong> To protect your account and prevent fraudulent activity</li>
              <li><strong>Preferences:</strong> To remember your settings and preferences</li>
              <li><strong>Analytics:</strong> To understand how visitors use our platform</li>
              <li><strong>Performance:</strong> To improve the speed and performance of our service</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">3. Types of Cookies We Use</h2>
            
            <div className="bg-muted/50 rounded-lg p-4 mb-4">
              <h3 className="font-semibold text-foreground mb-2">Strictly Necessary Cookies</h3>
              <p className="text-muted-foreground text-sm mb-2">
                These cookies are essential for the website to function properly. They enable core functionality 
                such as security, authentication, and session management. You cannot opt out of these cookies.
              </p>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border">
                      <th className="text-left py-2 text-foreground">Cookie Name</th>
                      <th className="text-left py-2 text-foreground">Purpose</th>
                      <th className="text-left py-2 text-foreground">Duration</th>
                    </tr>
                  </thead>
                  <tbody className="text-muted-foreground">
                    <tr className="border-b border-border/50">
                      <td className="py-2">auth_token</td>
                      <td className="py-2">Maintains your login session</td>
                      <td className="py-2">7 days</td>
                    </tr>
                    <tr className="border-b border-border/50">
                      <td className="py-2">csrf_token</td>
                      <td className="py-2">Security protection against cross-site attacks</td>
                      <td className="py-2">Session</td>
                    </tr>
                    <tr>
                      <td className="py-2">ieosuia_cookie_consent</td>
                      <td className="py-2">Stores your cookie preferences</td>
                      <td className="py-2">1 year</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div className="bg-muted/50 rounded-lg p-4 mb-4">
              <h3 className="font-semibold text-foreground mb-2">Functional Cookies</h3>
              <p className="text-muted-foreground text-sm mb-2">
                These cookies enable enhanced functionality and personalization, such as remembering your 
                preferences and settings.
              </p>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border">
                      <th className="text-left py-2 text-foreground">Cookie Name</th>
                      <th className="text-left py-2 text-foreground">Purpose</th>
                      <th className="text-left py-2 text-foreground">Duration</th>
                    </tr>
                  </thead>
                  <tbody className="text-muted-foreground">
                    <tr className="border-b border-border/50">
                      <td className="py-2">theme_preference</td>
                      <td className="py-2">Remembers your dark/light mode preference</td>
                      <td className="py-2">1 year</td>
                    </tr>
                    <tr>
                      <td className="py-2">language</td>
                      <td className="py-2">Stores your language preference</td>
                      <td className="py-2">1 year</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div className="bg-muted/50 rounded-lg p-4 mb-4">
              <h3 className="font-semibold text-foreground mb-2">Analytics Cookies</h3>
              <p className="text-muted-foreground text-sm mb-2">
                These cookies help us understand how visitors interact with our website by collecting and 
                reporting information anonymously.
              </p>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border">
                      <th className="text-left py-2 text-foreground">Cookie Name</th>
                      <th className="text-left py-2 text-foreground">Purpose</th>
                      <th className="text-left py-2 text-foreground">Duration</th>
                    </tr>
                  </thead>
                  <tbody className="text-muted-foreground">
                    <tr className="border-b border-border/50">
                      <td className="py-2">_ga</td>
                      <td className="py-2">Google Analytics - distinguishes users</td>
                      <td className="py-2">2 years</td>
                    </tr>
                    <tr className="border-b border-border/50">
                      <td className="py-2">_gid</td>
                      <td className="py-2">Google Analytics - distinguishes users</td>
                      <td className="py-2">24 hours</td>
                    </tr>
                    <tr>
                      <td className="py-2">_gat</td>
                      <td className="py-2">Google Analytics - throttles request rate</td>
                      <td className="py-2">1 minute</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">4. Third-Party Cookies</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              Some cookies are placed by third-party services that appear on our pages. We use the following 
              third-party services that may set cookies:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Google Analytics:</strong> For website analytics and performance monitoring</li>
              <li><strong>Google reCAPTCHA:</strong> For security and spam prevention</li>
              <li><strong>Payment Processors:</strong> For secure payment processing</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">5. Managing Your Cookie Preferences</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              You can manage your cookie preferences in several ways:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Cookie Banner:</strong> When you first visit our site, you can choose which types of cookies to accept</li>
              <li><strong>Browser Settings:</strong> Most browsers allow you to refuse or delete cookies through their settings</li>
              <li><strong>Opt-Out Links:</strong> Many third-party advertisers offer opt-out mechanisms</li>
            </ul>
            <p className="text-muted-foreground leading-relaxed mt-4">
              Please note that disabling certain cookies may affect the functionality of our website and your 
              user experience.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">6. How to Delete Cookies</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              You can delete cookies that have already been set by following these instructions for your browser:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Chrome:</strong> Settings → Privacy and security → Clear browsing data</li>
              <li><strong>Firefox:</strong> Options → Privacy & Security → Clear Data</li>
              <li><strong>Safari:</strong> Preferences → Privacy → Manage Website Data</li>
              <li><strong>Edge:</strong> Settings → Privacy, search, and services → Clear browsing data</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">7. Local Storage and Similar Technologies</h2>
            <p className="text-muted-foreground leading-relaxed">
              In addition to cookies, we may use other similar technologies such as local storage and session 
              storage to store data on your device. These technologies work similarly to cookies but can store 
              larger amounts of data. The same preferences you set for cookies will apply to these technologies.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">8. Updates to This Policy</h2>
            <p className="text-muted-foreground leading-relaxed">
              We may update this Cookie Policy from time to time to reflect changes in our practices or for 
              legal, operational, or regulatory reasons. We will notify you of any material changes by posting 
              the updated policy on this page with a new "Last updated" date.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">9. Contact Us</h2>
            <p className="text-muted-foreground leading-relaxed">
              If you have any questions about our use of cookies or this Cookie Policy, please contact us at{" "}
              <a href="mailto:support@ieosuia.com" className="text-primary hover:underline">support@ieosuia.com</a>{" "}
              or visit our <Link to="/contact" className="text-primary hover:underline">Contact page</Link>.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">10. Related Policies</h2>
            <p className="text-muted-foreground leading-relaxed">
              For more information about how we handle your data, please read our:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mt-3">
              <li><Link to="/privacy-policy" className="text-primary hover:underline">Privacy Policy</Link></li>
              <li><Link to="/terms-of-service" className="text-primary hover:underline">Terms of Service</Link></li>
              <li><Link to="/popia-compliance" className="text-primary hover:underline">POPIA Compliance</Link></li>
            </ul>
          </section>
        </div>
      </div>

      <Footer variant="simple" />
    </div>
  );
}