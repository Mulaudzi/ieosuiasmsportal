import { Link } from "react-router-dom";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  MessageSquare,
  Mail,
  Check,
  Calculator,
  ChevronDown,
  ChevronUp,
  MessageCircle,
  HelpCircle,
} from "lucide-react";
import { cn } from "@/lib/utils";

const plans = [
  {
    name: "Free",
    price: "R0",
    description: "Try our Free plan and get started instantly. Perfect for exploring all the SMS Portal features.",
    features: [
      "27 Free SMS",
      "IEOSUIA branding included",
      "Test the platform risk-free",
      "No credit card required",
      "Basic contact management",
      "Basic dashboard",
      "Email support",
    ],
    cta: "Try Now",
    popular: false,
    ctaLink: "/register",
  },
  {
    name: "Pro",
    price: "R0.18",
    period: "/SMS",
    description: "Send SMS Pay-as-you-Go, starting from R0.18 per message. No subscriptions, no contracts.",
    features: [
      "Pay-as-you-Go pricing",
      "No subscriptions or contracts",
      "Volume discounts available",
      "SMS & Email sending",
      "Priority support",
      "No watermark",
      "Full template customization",
      "Advanced reports",
      "A/B Testing",
      "Schedule recommendations",
      "Campaign comparison",
    ],
    cta: "Sign Up Now",
    popular: true,
    ctaLink: "/register",
  },
  {
    name: "Enterprise",
    price: "Custom",
    description: "For high-volume senders who need full features and VIP support.",
    features: [
      "Custom pricing",
      "Priority account management",
      "Exclusive features",
      "Multi-user access",
      "Multi-business support",
      "Dedicated support",
      "White-labeling",
      "Custom integrations",
      "SLA guarantee",
      "Role-based permissions",
    ],
    cta: "Contact Sales",
    popular: false,
    ctaLink: "/contact",
  },
];

const smsPricing = [
  { volume: "500 – 1,000", price: 0.27, priceDisplay: "R0.27" },
  { volume: "1,001 – 5,000", price: 0.25, priceDisplay: "R0.25" },
  { volume: "5,001 – 10,000", price: 0.23, priceDisplay: "R0.23" },
  { volume: "10,001 – 50,000", price: 0.20, priceDisplay: "R0.20" },
  { volume: "50,001 – 250,000", price: 0.19, priceDisplay: "R0.19" },
  { volume: "250,001 – 500,000", price: 0.18, priceDisplay: "R0.18" },
  { volume: "500,000+", price: null, priceDisplay: "Contact Sales" },
];

const emailPricing = [
  { volume: "< 500", price: 0.13, priceDisplay: "R0.13" },
  { volume: "500 – 1,000", price: 0.12, priceDisplay: "R0.12" },
  { volume: "1,001 – 5,000", price: 0.10, priceDisplay: "R0.10" },
  { volume: "5,001 – 10,000", price: 0.09, priceDisplay: "R0.09" },
  { volume: "10,001 – 50,000", price: 0.08, priceDisplay: "R0.08" },
  { volume: "50,001 – 250,000", price: 0.07, priceDisplay: "R0.07" },
  { volume: "250,001 – 500,000", price: 0.06, priceDisplay: "R0.06" },
  { volume: "500,000+", price: null, priceDisplay: "Contact Sales" },
];

const featureComparison = [
  { feature: "SMS Sending", free: "27 messages", pro: "Unlimited", enterprise: "Unlimited" },
  { feature: "Email Sending", free: "—", pro: "Unlimited", enterprise: "Unlimited" },
  { feature: "IEOSUIA Branding", free: "Yes", pro: "No", enterprise: "No" },
  { feature: "Contact Management", free: "Basic", pro: "Advanced", enterprise: "Advanced" },
  { feature: "Templates", free: "3 Basic", pro: "Unlimited", enterprise: "Unlimited" },
  { feature: "A/B Testing", free: "—", pro: "✓", enterprise: "✓" },
  { feature: "Schedule Recommendations", free: "—", pro: "✓", enterprise: "✓" },
  { feature: "Campaign Comparison", free: "—", pro: "✓", enterprise: "✓" },
  { feature: "Reports & Analytics", free: "Basic", pro: "Advanced", enterprise: "Custom" },
  { feature: "Multi-user Access", free: "—", pro: "—", enterprise: "✓" },
  { feature: "White-labeling", free: "—", pro: "—", enterprise: "✓" },
  { feature: "Custom Integrations", free: "—", pro: "—", enterprise: "✓" },
  { feature: "SLA Guarantee", free: "—", pro: "—", enterprise: "✓" },
  { feature: "Support", free: "Email", pro: "Priority", enterprise: "Dedicated" },
];

const faqs = [
  {
    question: "How does the Pay-as-you-Go pricing work?",
    answer: "You only pay for what you send. Buy credits in advance and use them whenever you need. No monthly subscriptions or contracts required. The more you send, the lower your per-message cost.",
  },
  {
    question: "Are there any hidden fees?",
    answer: "No hidden fees. The prices shown include carrier costs. VAT/BST/Tax may be added at checkout depending on your location.",
  },
  {
    question: "What's included in the Free plan?",
    answer: "The Free plan includes 27 SMS credits to test the platform. Free plan messages include IEOSUIA branding. No credit card required to start.",
  },
  {
    question: "How do volume discounts work?",
    answer: "As your sending volume increases, your per-message cost decreases. For example, SMS costs R0.27 for 500-1,000 messages, but drops to R0.18 for 250,001-500,000 messages.",
  },
  {
    question: "Do credits expire?",
    answer: "No, your credits never expire as long as your account remains active. Use them whenever you need.",
  },
  {
    question: "Can I upgrade or downgrade my plan?",
    answer: "With Pay-as-you-Go pricing, there's no plan to upgrade or downgrade. Simply buy more credits as needed. Enterprise customers can contact sales for custom arrangements.",
  },
  {
    question: "What payment methods do you accept?",
    answer: "We accept all major credit and debit cards, EFT/bank transfers, and various local payment methods popular in South Africa.",
  },
  {
    question: "Is there a minimum purchase amount?",
    answer: "Yes, the minimum purchase is 500 credits. This ensures you get the best value and can test the platform thoroughly.",
  },
  {
    question: "Can I get a refund on unused credits?",
    answer: "Credits are non-refundable once purchased. However, they never expire so you can use them at any time.",
  },
  {
    question: "What happens if I need more than 500,000 messages?",
    answer: "For volumes exceeding 500,000 messages, please contact our sales team for custom Enterprise pricing tailored to your needs.",
  },
];

export default function Pricing() {
  const [smsVolume, setSmsVolume] = useState<string>("1000");
  const [emailVolume, setEmailVolume] = useState<string>("1000");
  const [expandedFaq, setExpandedFaq] = useState<number | null>(null);

  const calculateSmsPrice = (volume: number): { price: number; tier: string } => {
    if (volume <= 0) return { price: 0, tier: "Enter volume" };
    if (volume < 500) return { price: volume * 0.27, tier: "Minimum 500" };
    if (volume <= 1000) return { price: volume * 0.27, tier: "R0.27/SMS" };
    if (volume <= 5000) return { price: volume * 0.25, tier: "R0.25/SMS" };
    if (volume <= 10000) return { price: volume * 0.23, tier: "R0.23/SMS" };
    if (volume <= 50000) return { price: volume * 0.20, tier: "R0.20/SMS" };
    if (volume <= 250000) return { price: volume * 0.19, tier: "R0.19/SMS" };
    if (volume <= 500000) return { price: volume * 0.18, tier: "R0.18/SMS" };
    return { price: 0, tier: "Contact Sales" };
  };

  const calculateEmailPrice = (volume: number): { price: number; tier: string } => {
    if (volume <= 0) return { price: 0, tier: "Enter volume" };
    if (volume < 500) return { price: volume * 0.13, tier: "R0.13/Email" };
    if (volume <= 1000) return { price: volume * 0.12, tier: "R0.12/Email" };
    if (volume <= 5000) return { price: volume * 0.10, tier: "R0.10/Email" };
    if (volume <= 10000) return { price: volume * 0.09, tier: "R0.09/Email" };
    if (volume <= 50000) return { price: volume * 0.08, tier: "R0.08/Email" };
    if (volume <= 250000) return { price: volume * 0.07, tier: "R0.07/Email" };
    if (volume <= 500000) return { price: volume * 0.06, tier: "R0.06/Email" };
    return { price: 0, tier: "Contact Sales" };
  };

  const smsResult = calculateSmsPrice(parseInt(smsVolume) || 0);
  const emailResult = calculateEmailPrice(parseInt(emailVolume) || 0);

  return (
    <div className="min-h-screen bg-background">
      {/* Navigation */}
      <nav className="fixed top-0 left-0 right-0 z-50 bg-background/80 backdrop-blur-md border-b border-border">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <Logo size="md" linkTo="/landing" />
          <div className="hidden md:flex items-center gap-8">
            <Link to="/landing#features" className="text-muted-foreground hover:text-foreground transition-colors">Features</Link>
            <Link to="/pricing" className="text-foreground font-medium">Pricing</Link>
            <Link to="/contact" className="text-muted-foreground hover:text-foreground transition-colors">Contact</Link>
          </div>
          <div className="flex items-center gap-3">
            <Link to="/login">
              <Button variant="ghost">Login</Button>
            </Link>
            <Link to="/register">
              <Button>Get Started</Button>
            </Link>
          </div>
        </div>
      </nav>

      {/* Hero Section */}
      <section className="pt-32 pb-12 px-4 bg-gradient-to-b from-primary/5 to-background">
        <div className="container mx-auto text-center max-w-4xl">
          <h1 className="text-4xl md:text-5xl font-bold text-foreground leading-tight mb-4">
            Simple, Transparent Pricing
          </h1>
          <p className="text-xl text-muted-foreground mb-4 max-w-2xl mx-auto">
            Pay only for what you send. No hidden fees, no monthly subscriptions, no contracts.
          </p>
          <p className="text-sm text-muted-foreground">
            Excl. VAT/BST/Tax (Includes carrier costs)
          </p>
        </div>
      </section>

      {/* Plans Section */}
      <section className="py-12 px-4">
        <div className="container mx-auto max-w-6xl">
          <div className="grid gap-8 md:grid-cols-3">
            {plans.map((plan) => (
              <div
                key={plan.name}
                className={cn(
                  "relative bg-card rounded-xl p-8 border-2 transition-shadow hover:shadow-lg",
                  plan.popular ? "border-primary shadow-md" : "border-border"
                )}
              >
                {plan.popular && (
                  <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                    <span className="bg-primary text-primary-foreground text-sm font-medium px-4 py-1 rounded-full">
                      Recommended
                    </span>
                  </div>
                )}
                <div className="text-center mb-6">
                  <h3 className="text-xl font-semibold text-foreground mb-2">{plan.name}</h3>
                  <div className="flex items-baseline justify-center gap-1">
                    <span className="text-4xl font-bold text-foreground">{plan.price}</span>
                    {plan.period && <span className="text-muted-foreground">{plan.period}</span>}
                  </div>
                  <p className="mt-2 text-sm text-muted-foreground">{plan.description}</p>
                </div>
                <ul className="space-y-3 mb-8">
                  {plan.features.map((feature) => (
                    <li key={feature} className="flex items-start gap-3">
                      <Check className="h-5 w-5 text-success shrink-0 mt-0.5" />
                      <span className="text-sm text-muted-foreground">{feature}</span>
                    </li>
                  ))}
                </ul>
                <Link to={plan.ctaLink}>
                  <Button className="w-full" variant={plan.popular ? "default" : "outline"}>
                    {plan.cta}
                  </Button>
                </Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Pricing Calculator */}
      <section className="py-16 px-4 bg-muted/30">
        <div className="container mx-auto max-w-4xl">
          <div className="text-center mb-12">
            <div className="inline-flex items-center gap-2 bg-primary/10 rounded-full px-4 py-2 mb-4">
              <Calculator className="h-4 w-4 text-primary" />
              <span className="text-sm font-medium text-primary">Pricing Calculator</span>
            </div>
            <h2 className="text-3xl font-bold text-foreground mb-4">
              Calculate Your Cost
            </h2>
            <p className="text-lg text-muted-foreground">
              Enter your expected volume to see your estimated cost.
            </p>
          </div>

          <div className="grid gap-8 md:grid-cols-2">
            {/* SMS Calculator */}
            <div className="bg-card rounded-xl p-6 border border-border">
              <div className="flex items-center gap-3 mb-6">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                  <MessageSquare className="h-5 w-5 text-primary" />
                </div>
                <h3 className="text-xl font-semibold text-foreground">SMS Calculator</h3>
              </div>
              <div className="space-y-4">
                <div>
                  <Label htmlFor="sms-volume">Number of SMS</Label>
                  <Input
                    id="sms-volume"
                    type="number"
                    min="0"
                    value={smsVolume}
                    onChange={(e) => setSmsVolume(e.target.value)}
                    className="mt-1"
                  />
                </div>
                <div className="bg-muted/50 rounded-lg p-4">
                  <div className="flex justify-between items-center mb-2">
                    <span className="text-sm text-muted-foreground">Rate Applied:</span>
                    <span className="font-medium text-foreground">{smsResult.tier}</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-muted-foreground">Estimated Cost:</span>
                    <span className="text-2xl font-bold text-primary">
                      {smsResult.tier === "Contact Sales" ? "Contact Sales" : `R${smsResult.price.toFixed(2)}`}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            {/* Email Calculator */}
            <div className="bg-card rounded-xl p-6 border border-border">
              <div className="flex items-center gap-3 mb-6">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                  <Mail className="h-5 w-5 text-primary" />
                </div>
                <h3 className="text-xl font-semibold text-foreground">Email Calculator</h3>
              </div>
              <div className="space-y-4">
                <div>
                  <Label htmlFor="email-volume">Number of Emails</Label>
                  <Input
                    id="email-volume"
                    type="number"
                    min="0"
                    value={emailVolume}
                    onChange={(e) => setEmailVolume(e.target.value)}
                    className="mt-1"
                  />
                </div>
                <div className="bg-muted/50 rounded-lg p-4">
                  <div className="flex justify-between items-center mb-2">
                    <span className="text-sm text-muted-foreground">Rate Applied:</span>
                    <span className="font-medium text-foreground">{emailResult.tier}</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-muted-foreground">Estimated Cost:</span>
                    <span className="text-2xl font-bold text-primary">
                      {emailResult.tier === "Contact Sales" ? "Contact Sales" : `R${emailResult.price.toFixed(2)}`}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Detailed Pricing Tables */}
      <section className="py-16 px-4">
        <div className="container mx-auto max-w-6xl">
          <div className="text-center mb-12">
            <h2 className="text-3xl font-bold text-foreground mb-4">
              Volume Discount Tiers
            </h2>
            <p className="text-lg text-muted-foreground">
              The more you send, the less you pay per message.
            </p>
          </div>

          <Tabs defaultValue="sms" className="w-full">
            <TabsList className="grid w-full max-w-md mx-auto grid-cols-2 mb-8">
              <TabsTrigger value="sms" className="gap-2">
                <MessageSquare className="h-4 w-4" />
                SMS Pricing
              </TabsTrigger>
              <TabsTrigger value="email" className="gap-2">
                <Mail className="h-4 w-4" />
                Email Pricing
              </TabsTrigger>
            </TabsList>

            <TabsContent value="sms">
              <div className="bg-card rounded-xl border border-border overflow-hidden">
                <table className="w-full">
                  <thead>
                    <tr className="bg-muted/50">
                      <th className="text-left py-4 px-6 font-semibold text-foreground">Volume (messages)</th>
                      <th className="text-right py-4 px-6 font-semibold text-foreground">Price per SMS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {smsPricing.map((tier, index) => (
                      <tr key={index} className="border-t border-border">
                        <td className="py-4 px-6 text-muted-foreground">{tier.volume}</td>
                        <td className="py-4 px-6 text-right font-semibold text-foreground">
                          {tier.price === null ? (
                            <Link to="/contact" className="text-primary hover:underline">
                              {tier.priceDisplay}
                            </Link>
                          ) : (
                            tier.priceDisplay
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <div className="p-4 bg-muted/30 text-sm text-muted-foreground">
                  * Free plan SMS include IEOSUIA branding. Minimum purchase: 500 SMS.
                </div>
              </div>
            </TabsContent>

            <TabsContent value="email">
              <div className="bg-card rounded-xl border border-border overflow-hidden">
                <table className="w-full">
                  <thead>
                    <tr className="bg-muted/50">
                      <th className="text-left py-4 px-6 font-semibold text-foreground">Volume (emails)</th>
                      <th className="text-right py-4 px-6 font-semibold text-foreground">Price per Email</th>
                    </tr>
                  </thead>
                  <tbody>
                    {emailPricing.map((tier, index) => (
                      <tr key={index} className="border-t border-border">
                        <td className="py-4 px-6 text-muted-foreground">{tier.volume}</td>
                        <td className="py-4 px-6 text-right font-semibold text-foreground">
                          {tier.price === null ? (
                            <Link to="/contact" className="text-primary hover:underline">
                              {tier.priceDisplay}
                            </Link>
                          ) : (
                            tier.priceDisplay
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <div className="p-4 bg-muted/30 text-sm text-muted-foreground">
                  * For 500,000+ emails, <Link to="/contact?purpose=sales" className="text-primary hover:underline">contact our sales team</Link>
                </div>
              </div>
            </TabsContent>
          </Tabs>
        </div>
      </section>

      {/* Feature Comparison */}
      <section className="py-16 px-4 bg-muted/30">
        <div className="container mx-auto max-w-6xl">
          <div className="text-center mb-12">
            <h2 className="text-3xl font-bold text-foreground mb-4">
              Feature Comparison
            </h2>
            <p className="text-lg text-muted-foreground">
              See what's included in each plan.
            </p>
          </div>

          <div className="bg-card rounded-xl border border-border overflow-x-auto">
            <table className="w-full min-w-[600px]">
              <thead>
                <tr className="bg-muted/50">
                  <th className="text-left py-4 px-6 font-semibold text-foreground">Feature</th>
                  <th className="text-center py-4 px-6 font-semibold text-foreground">Free</th>
                  <th className="text-center py-4 px-6 font-semibold text-primary bg-primary/5">Pro</th>
                  <th className="text-center py-4 px-6 font-semibold text-foreground">Enterprise</th>
                </tr>
              </thead>
              <tbody>
                {featureComparison.map((row, index) => (
                  <tr key={index} className="border-t border-border">
                    <td className="py-4 px-6 font-medium text-foreground">{row.feature}</td>
                    <td className="py-4 px-6 text-center text-muted-foreground">{row.free}</td>
                    <td className="py-4 px-6 text-center text-foreground bg-primary/5">{row.pro}</td>
                    <td className="py-4 px-6 text-center text-muted-foreground">{row.enterprise}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </section>

      {/* FAQs */}
      <section className="py-16 px-4">
        <div className="container mx-auto max-w-3xl">
          <div className="text-center mb-12">
            <div className="inline-flex items-center gap-2 bg-primary/10 rounded-full px-4 py-2 mb-4">
              <HelpCircle className="h-4 w-4 text-primary" />
              <span className="text-sm font-medium text-primary">FAQs</span>
            </div>
            <h2 className="text-3xl font-bold text-foreground mb-4">
              Frequently Asked Questions
            </h2>
            <p className="text-lg text-muted-foreground">
              Got questions? We've got answers.
            </p>
          </div>

          <div className="space-y-4">
            {faqs.map((faq, index) => (
              <div
                key={index}
                className="bg-card rounded-xl border border-border overflow-hidden"
              >
                <button
                  onClick={() => setExpandedFaq(expandedFaq === index ? null : index)}
                  className="w-full flex items-center justify-between p-4 text-left hover:bg-muted/50 transition-colors"
                >
                  <span className="font-medium text-foreground pr-4">{faq.question}</span>
                  {expandedFaq === index ? (
                    <ChevronUp className="h-5 w-5 text-muted-foreground shrink-0" />
                  ) : (
                    <ChevronDown className="h-5 w-5 text-muted-foreground shrink-0" />
                  )}
                </button>
                {expandedFaq === index && (
                  <div className="px-4 pb-4">
                    <p className="text-muted-foreground">{faq.answer}</p>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA Section */}
      <section className="py-16 px-4 bg-primary/5">
        <div className="container mx-auto max-w-4xl text-center">
          <h2 className="text-3xl font-bold text-foreground mb-4">
            Ready to Get Started?
          </h2>
          <p className="text-lg text-muted-foreground mb-8">
            Sign up now and get 27 free SMS credits to test the platform.
          </p>
          <div className="flex flex-col sm:flex-row gap-4 justify-center">
            <Link to="/register">
              <Button size="lg" className="gap-2 text-lg px-8">
                Get Started Free
              </Button>
            </Link>
            <Link to="/contact">
              <Button size="lg" variant="outline" className="text-lg px-8">
                Contact Sales
              </Button>
            </Link>
          </div>
        </div>
      </section>

      {/* WhatsApp Button */}
      <a
        href="https://wa.me/27799282775"
        target="_blank"
        rel="noopener noreferrer"
        className="fixed bottom-6 right-6 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg hover:bg-[#128C7E] transition-colors"
        aria-label="Chat on WhatsApp"
      >
        <MessageCircle className="h-7 w-7" />
      </a>

      <Footer variant="full" />
    </div>
  );
}