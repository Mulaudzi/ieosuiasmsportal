import { Link } from "react-router-dom";
import { Zap, ArrowLeft, Shield, CheckCircle, AlertCircle } from "lucide-react";

export default function PopiaCompliance() {
  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-4xl mx-auto px-6 py-12">
        <div className="flex items-center gap-3 mb-8">
          <Link to="/landing" className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary">
              <Zap className="h-6 w-6 text-primary-foreground" />
            </div>
            <span className="text-xl font-bold text-foreground">IEOSUIA SMS PORTAL</span>
          </Link>
        </div>

        <Link to="/landing" className="inline-flex items-center gap-2 text-primary hover:underline mb-8">
          <ArrowLeft className="h-4 w-4" />
          Back to Home
        </Link>

        <div className="flex items-center gap-3 mb-6">
          <Shield className="h-8 w-8 text-primary" />
          <h1 className="text-3xl font-bold text-foreground">POPIA Compliance</h1>
        </div>
        <p className="text-muted-foreground mb-8">
          Protection of Personal Information Act (POPIA) Compliance Statement
          <br />
          <span className="text-sm">Last updated: January 10, 2026</span>
        </p>

        <div className="bg-primary/10 border border-primary/20 rounded-xl p-6 mb-8">
          <div className="flex items-start gap-3">
            <CheckCircle className="h-6 w-6 text-primary shrink-0 mt-0.5" />
            <div>
              <h3 className="font-semibold text-foreground mb-2">Our Commitment to POPIA</h3>
              <p className="text-muted-foreground">
                IEOSUIA SMS PORTAL is committed to ensuring the protection of personal information in accordance 
                with the Protection of Personal Information Act 4 of 2013 (POPIA). We respect your privacy rights 
                and are dedicated to handling your personal information responsibly and transparently.
              </p>
            </div>
          </div>
        </div>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-6">
          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">1. What is POPIA?</h2>
            <p className="text-muted-foreground leading-relaxed">
              The Protection of Personal Information Act (POPIA) is South Africa's data protection law that 
              came into full effect on 1 July 2021. It regulates how organisations collect, store, process, 
              and share personal information. POPIA gives data subjects (individuals) specific rights regarding 
              their personal information and places obligations on responsible parties (organisations) to protect 
              this information.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">2. Key Definitions Under POPIA</h2>
            <div className="bg-muted/50 rounded-lg p-4 space-y-3">
              <div>
                <strong className="text-foreground">Personal Information:</strong>
                <span className="text-muted-foreground"> Information relating to an identifiable, living, natural person or juristic person, including names, contact details, ID numbers, and online identifiers.</span>
              </div>
              <div>
                <strong className="text-foreground">Data Subject:</strong>
                <span className="text-muted-foreground"> The person to whom personal information relates (you, our users).</span>
              </div>
              <div>
                <strong className="text-foreground">Responsible Party:</strong>
                <span className="text-muted-foreground"> The organisation that determines the purpose and means of processing personal information (IEOSUIA SMS PORTAL).</span>
              </div>
              <div>
                <strong className="text-foreground">Operator:</strong>
                <span className="text-muted-foreground"> A third party that processes personal information on behalf of the responsible party.</span>
              </div>
              <div>
                <strong className="text-foreground">Information Officer:</strong>
                <span className="text-muted-foreground"> The person responsible for ensuring POPIA compliance within the organisation.</span>
              </div>
            </div>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">3. The Eight Conditions for Lawful Processing</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              POPIA establishes eight conditions that we adhere to when processing your personal information:
            </p>
            
            <div className="space-y-4">
              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">1. Accountability</h3>
                <p className="text-muted-foreground text-sm">
                  We take responsibility for complying with POPIA and have appointed an Information Officer 
                  to ensure compliance.
                </p>
              </div>

              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">2. Processing Limitation</h3>
                <p className="text-muted-foreground text-sm">
                  We only collect personal information that is necessary for our services, with your consent 
                  or as permitted by law. We do not process information in ways that infringe on your privacy.
                </p>
              </div>

              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">3. Purpose Specification</h3>
                <p className="text-muted-foreground text-sm">
                  We collect personal information only for specific, explicitly defined, and lawful purposes. 
                  We inform you of these purposes at the time of collection.
                </p>
              </div>

              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">4. Further Processing Limitation</h3>
                <p className="text-muted-foreground text-sm">
                  We do not process your personal information for purposes incompatible with the original 
                  purpose for which it was collected, unless you provide additional consent.
                </p>
              </div>

              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">5. Information Quality</h3>
                <p className="text-muted-foreground text-sm">
                  We take reasonable steps to ensure that personal information is complete, accurate, 
                  not misleading, and updated when necessary.
                </p>
              </div>

              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">6. Openness</h3>
                <p className="text-muted-foreground text-sm">
                  We are transparent about our information practices. This policy and our Privacy Policy 
                  document how we handle your personal information.
                </p>
              </div>

              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">7. Security Safeguards</h3>
                <p className="text-muted-foreground text-sm">
                  We implement appropriate technical and organisational measures to protect personal 
                  information against loss, damage, unauthorised access, or unlawful processing.
                </p>
              </div>

              <div className="bg-muted/50 rounded-lg p-4">
                <h3 className="font-semibold text-foreground mb-2">8. Data Subject Participation</h3>
                <p className="text-muted-foreground text-sm">
                  You have the right to access, correct, and request deletion of your personal information. 
                  We provide mechanisms for you to exercise these rights.
                </p>
              </div>
            </div>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">4. Your Rights Under POPIA</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              As a data subject, you have the following rights:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Right to Access:</strong> Request confirmation of what personal information we hold about you</li>
              <li><strong>Right to Correction:</strong> Request correction of inaccurate or incomplete personal information</li>
              <li><strong>Right to Deletion:</strong> Request deletion of your personal information in certain circumstances</li>
              <li><strong>Right to Object:</strong> Object to the processing of your personal information for direct marketing</li>
              <li><strong>Right to Withdraw Consent:</strong> Withdraw consent previously given for processing</li>
              <li><strong>Right to Lodge a Complaint:</strong> Lodge a complaint with the Information Regulator</li>
              <li><strong>Right to be Informed:</strong> Be informed of the collection and use of your personal information</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">5. How We Protect Your Information</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              We implement comprehensive security measures to protect your personal information:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li>Encryption of data in transit and at rest using industry-standard protocols</li>
              <li>Secure authentication mechanisms including multi-factor authentication options</li>
              <li>Regular security assessments and vulnerability testing</li>
              <li>Access controls limiting who can view and process personal information</li>
              <li>Employee training on data protection and security practices</li>
              <li>Incident response procedures for potential data breaches</li>
              <li>Secure data centres with physical access controls</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">6. SMS and Direct Marketing Compliance</h2>
            <p className="text-muted-foreground leading-relaxed mb-3">
              As an SMS messaging platform, we have specific obligations under POPIA regarding direct marketing:
            </p>
            <div className="bg-amber-500/10 border border-amber-500/20 rounded-lg p-4 mb-4">
              <div className="flex items-start gap-3">
                <AlertCircle className="h-5 w-5 text-amber-500 shrink-0 mt-0.5" />
                <div>
                  <h4 className="font-semibold text-foreground mb-1">Important for Our Users</h4>
                  <p className="text-muted-foreground text-sm">
                    When using our platform to send SMS messages, you are the responsible party for POPIA 
                    compliance regarding your recipients. You must ensure you have proper consent before 
                    sending marketing messages.
                  </p>
                </div>
              </div>
            </div>
            <ul className="list-disc list-inside text-muted-foreground space-y-2">
              <li><strong>Consent Required:</strong> Direct marketing via SMS requires prior consent from recipients</li>
              <li><strong>Opt-Out Mechanism:</strong> All marketing messages must include an easy opt-out option</li>
              <li><strong>Opt-Out Honour:</strong> Opt-out requests must be processed promptly</li>
              <li><strong>Record Keeping:</strong> Maintain records of consent and opt-out requests</li>
              <li><strong>Existing Customers:</strong> You may contact existing customers about similar products/services</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">7. Data Retention</h2>
            <p className="text-muted-foreground leading-relaxed">
              We retain personal information only for as long as necessary to fulfil the purposes for which 
              it was collected, or as required by law. When personal information is no longer needed, we 
              securely destroy or de-identify it. Specific retention periods include:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mt-3">
              <li>Account information: Duration of account plus 5 years</li>
              <li>Transaction records: 7 years as required by tax legislation</li>
              <li>Message logs: 90 days for delivery reporting purposes</li>
              <li>Opt-out records: Indefinitely to ensure compliance</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">8. Cross-Border Data Transfers</h2>
            <p className="text-muted-foreground leading-relaxed">
              In some cases, your personal information may be transferred to countries outside South Africa 
              for processing (e.g., cloud hosting services). When this occurs, we ensure that:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mt-3">
              <li>The recipient country has adequate data protection laws, or</li>
              <li>We have binding agreements with the recipient ensuring POPIA-level protection, or</li>
              <li>You have provided consent for the transfer</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">9. Information Officer</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              Our designated Information Officer is responsible for ensuring compliance with POPIA and 
              handling requests related to personal information:
            </p>
            <div className="bg-muted/50 rounded-lg p-4">
              <p className="text-muted-foreground">
                <strong className="text-foreground">Information Officer:</strong> IEOSUIA PTY LTD<br />
                <strong className="text-foreground">Email:</strong> privacy@ieosuia.com<br />
                <strong className="text-foreground">Address:</strong> 106 Harry Street, Robertsham, Johannesburg, 2190<br />
                <strong className="text-foreground">Phone:</strong> +27 11 123 4567
              </p>
            </div>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">10. Lodging a Complaint</h2>
            <p className="text-muted-foreground leading-relaxed mb-4">
              If you believe that we have not complied with POPIA, you have the right to lodge a complaint 
              with the Information Regulator of South Africa:
            </p>
            <div className="bg-muted/50 rounded-lg p-4">
              <p className="text-muted-foreground">
                <strong className="text-foreground">Information Regulator (South Africa)</strong><br />
                <strong className="text-foreground">Physical Address:</strong> SALU Building, 316 Thabo Sehume Street, Pretoria<br />
                <strong className="text-foreground">Email:</strong> complaints.IR@justice.gov.za<br />
                <strong className="text-foreground">Website:</strong>{" "}
                <a href="https://www.justice.gov.za/inforeg/" target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                  www.justice.gov.za/inforeg/
                </a>
              </p>
            </div>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-foreground mb-3">11. Related Policies</h2>
            <p className="text-muted-foreground leading-relaxed">
              For more detailed information about how we handle your data, please also review:
            </p>
            <ul className="list-disc list-inside text-muted-foreground space-y-2 mt-3">
              <li><Link to="/privacy-policy" className="text-primary hover:underline">Privacy Policy</Link> - Detailed information about data collection and use</li>
              <li><Link to="/terms-of-service" className="text-primary hover:underline">Terms of Service</Link> - Terms and conditions of using our service</li>
              <li><Link to="/cookie-policy" className="text-primary hover:underline">Cookie Policy</Link> - Information about cookies and tracking technologies</li>
            </ul>
          </section>
        </div>

        {/* Footer */}
        <div className="mt-12 pt-8 border-t border-border text-center">
          <div className="flex items-center justify-center gap-2 mb-4">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary">
              <Zap className="h-5 w-5 text-primary-foreground" />
            </div>
            <span className="text-lg font-bold text-foreground">IEOSUIA SMS PORTAL</span>
          </div>
          <div className="flex flex-wrap justify-center gap-4 text-sm text-muted-foreground">
            <Link to="/terms-of-service" className="hover:text-foreground">Terms of Service</Link>
            <Link to="/privacy-policy" className="hover:text-foreground">Privacy Policy</Link>
            <Link to="/cookie-policy" className="hover:text-foreground">Cookie Policy</Link>
            <Link to="/support" className="hover:text-foreground">Support</Link>
          </div>
          <p className="mt-4 text-sm text-muted-foreground">
            © {new Date().getFullYear()} IEOSUIA SMS PORTAL. All rights reserved.
          </p>
        </div>
      </div>
    </div>
  );
}
