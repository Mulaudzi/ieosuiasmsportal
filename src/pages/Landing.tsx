import { Link } from "react-router-dom";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";
import {
  MessageSquare,
  Mail,
  Users,
  BarChart3,
  Shield,
  Zap,
  Check,
  ArrowRight,
  MessageCircle,
  ChevronDown,
  ChevronUp,
} from "lucide-react";
import { cn } from "@/lib/utils";

const features = [
  {
    icon: MessageSquare,
    title: "Bulk SMS",
    description: "Send thousands of SMS messages instantly with high delivery rates.",
  },
  {
    icon: Mail,
    title: "Email Campaigns",
    description: "Create beautiful email campaigns with our drag-and-drop builder.",
  },
  {
    icon: Users,
    title: "Contact Management",
    description: "Import, organize, and segment your contacts effortlessly.",
  },
  {
    icon: BarChart3,
    title: "Real-time Analytics",
    description: "Track delivery rates, opens, and clicks with detailed reports.",
  },
  {
    icon: Shield,
    title: "Compliance Built-in",
    description: "Automatic opt-out handling and POPIA/GDPR compliance.",
  },
  {
    icon: Zap,
    title: "Instant Delivery",
    description: "Lightning-fast message delivery with carrier-grade infrastructure.",
  },
];

const steps = [
  { step: 1, title: "Create Account", description: "Sign up for free and set up your business profile" },
  { step: 2, title: "Add Contacts", description: "Import your contacts via CSV or add them manually" },
  { step: 3, title: "Create Campaign", description: "Design your message with customizable templates" },
  { step: 4, title: "Send & Track", description: "Send messages and monitor delivery in real-time" },
];

const pricing = [
  {
    name: "Free",
    price: "R0",
    description: "Try our Free plan and get started instantly. Perfect for exploring all the SMS Portal features.",
    features: [
      "27 Free SMS",
      "IEOSUIA branding included",
      "Test the platform risk-free",
      "No credit card required",
    ],
    allFeatures: [
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
    ],
    allFeatures: [
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
    ],
    allFeatures: [
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
  },
];

const smsPricing = [
  { volume: "500 – 1,000", price: "R0.27" },
  { volume: "1,001 – 5,000", price: "R0.25" },
  { volume: "5,001 – 10,000", price: "R0.23" },
  { volume: "10,001 – 50,000", price: "R0.20" },
  { volume: "50,001 – 250,000", price: "R0.19" },
  { volume: "250,001 – 500,000", price: "R0.18" },
  { volume: "500,000+", price: "Contact Sales" },
];

const emailPricing = [
  { volume: "< 500", price: "R0.13" },
  { volume: "500 – 1,000", price: "R0.12" },
  { volume: "1,001 – 5,000", price: "R0.10" },
  { volume: "5,001 – 10,000", price: "R0.09" },
  { volume: "10,001 – 50,000", price: "R0.08" },
  { volume: "50,001 – 250,000", price: "R0.07" },
  { volume: "250,001 – 500,000", price: "R0.06" },
  { volume: "500,000+", price: "Contact Sales" },
];

const pricingFaqs = [
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
];

export default function Landing() {
  const [showAllFeatures, setShowAllFeatures] = useState(false);
  const [showDetailedPricing, setShowDetailedPricing] = useState(false);
  const [expandedFaq, setExpandedFaq] = useState<number | null>(null);

  return (
    <div className="min-h-screen bg-background">
      {/* Navigation */}
      <nav className="fixed top-0 left-0 right-0 z-50 bg-background/80 backdrop-blur-md border-b border-border">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <Logo size="md" linkTo="/landing" />
          <div className="hidden md:flex items-center gap-8">
            <a href="#features" className="text-muted-foreground hover:text-foreground transition-colors">Features</a>
            <a href="#how-it-works" className="text-muted-foreground hover:text-foreground transition-colors">How it Works</a>
            <Link to="/pricing" className="text-muted-foreground hover:text-foreground transition-colors">Pricing</Link>
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
      <section className="pt-32 pb-20 px-4 bg-gradient-to-b from-primary/5 to-background">
        <div className="container mx-auto text-center max-w-4xl">
          <div className="inline-flex items-center gap-2 bg-primary/10 rounded-full px-4 py-2 mb-6">
            <Zap className="h-4 w-4 text-primary" />
            <span className="text-sm font-medium text-primary">Your Story, Beautifully Told</span>
          </div>
          <h1 className="text-4xl md:text-6xl font-bold text-foreground leading-tight mb-6">
            Professional Messaging
            <br />
            <span className="text-primary">Made Simple</span>
          </h1>
          <p className="text-xl text-muted-foreground mb-8 max-w-2xl mx-auto">
            Send bulk SMS and email campaigns, track delivery reports, and grow your business with our powerful messaging platform built for South African businesses.
          </p>
          <div className="flex flex-col sm:flex-row gap-4 justify-center">
            <Link to="/register">
              <Button size="lg" className="gap-2 text-lg px-8">
                Get Started for Free
                <ArrowRight className="h-5 w-5" />
              </Button>
            </Link>
            <Link to="/login">
              <Button size="lg" variant="outline" className="text-lg px-8">
                Login to Dashboard
              </Button>
            </Link>
          </div>
          <p className="mt-6 text-sm text-muted-foreground">
            No credit card required • 27 free SMS credits • POPIA compliant
          </p>
        </div>
      </section>

      {/* Features Section */}
      <section id="features" className="py-20 px-4 bg-muted/30">
        <div className="container mx-auto max-w-6xl">
          <div className="text-center mb-12">
            <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
              Everything You Need to Communicate
            </h2>
            <p className="text-lg text-muted-foreground max-w-2xl mx-auto">
              A complete messaging platform designed for businesses of all sizes.
            </p>
          </div>
          <div className="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
            {features.map((feature) => (
              <div key={feature.title} className="bg-card rounded-xl p-6 border border-border hover:shadow-lg transition-shadow">
                <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary mb-4">
                  <feature.icon className="h-6 w-6" />
                </div>
                <h3 className="text-lg font-semibold text-foreground mb-2">{feature.title}</h3>
                <p className="text-muted-foreground">{feature.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* How it Works */}
      <section id="how-it-works" className="py-20 px-4">
        <div className="container mx-auto max-w-6xl">
          <div className="text-center mb-12">
            <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
              How It Works
            </h2>
            <p className="text-lg text-muted-foreground max-w-2xl mx-auto">
              Get started in minutes with our simple 4-step process.
            </p>
          </div>
          <div className="grid gap-8 md:grid-cols-4">
            {steps.map((step, index) => (
              <div key={step.step} className="relative text-center">
                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary text-primary-foreground text-2xl font-bold mx-auto mb-4">
                  {step.step}
                </div>
                {index < steps.length - 1 && (
                  <div className="hidden md:block absolute top-8 left-[60%] w-[80%] h-0.5 bg-border" />
                )}
                <h3 className="text-lg font-semibold text-foreground mb-2">{step.title}</h3>
                <p className="text-sm text-muted-foreground">{step.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Pricing Section */}
      <section id="pricing" className="py-20 px-4 bg-muted/30">
        <div className="container mx-auto max-w-6xl">
          <div className="text-center mb-12">
            <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
              Simple, Transparent Pricing
            </h2>
            <p className="text-lg text-muted-foreground max-w-2xl mx-auto">
              Pay only for what you send. No hidden fees, no surprises.
            </p>
          </div>
          <div className="grid gap-8 md:grid-cols-3">
            {pricing.map((plan) => (
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
                  {(showAllFeatures ? plan.allFeatures : plan.features).map((feature) => (
                    <li key={feature} className="flex items-start gap-3">
                      <Check className="h-5 w-5 text-success shrink-0 mt-0.5" />
                      <span className="text-sm text-muted-foreground">{feature}</span>
                    </li>
                  ))}
                </ul>
                <Link to={plan.name === "Enterprise" ? "/contact" : "/register"}>
                  <Button className="w-full" variant={plan.popular ? "default" : "outline"}>
                    {plan.cta}
                  </Button>
                </Link>
                {plan.popular && (
                  <p className="text-xs text-muted-foreground text-center mt-3">
                    Excl. VAT/BST/Tax (Includes carrier costs)
                  </p>
                )}
                {plan.name === "Enterprise" && (
                  <p className="text-xs text-muted-foreground text-center mt-3">
                    T&C's apply
                  </p>
                )}
              </div>
            ))}
          </div>
          
          {/* Show More Features Button */}
          <div className="text-center mt-8">
            <Button
              variant="ghost"
              onClick={() => setShowAllFeatures(!showAllFeatures)}
              className="gap-2"
            >
              {showAllFeatures ? (
                <>
                  <ChevronUp className="h-4 w-4" />
                  Show Less Features
                </>
              ) : (
                <>
                  <ChevronDown className="h-4 w-4" />
                  Compare All Features
                </>
              )}
            </Button>
          </div>

          {/* Detailed Pricing Tables */}
          <div className="text-center mt-8">
            <Button
              variant="outline"
              onClick={() => setShowDetailedPricing(!showDetailedPricing)}
              className="gap-2"
            >
              {showDetailedPricing ? (
                <>
                  <ChevronUp className="h-4 w-4" />
                  Hide Detailed Pricing
                </>
              ) : (
                <>
                  <ChevronDown className="h-4 w-4" />
                  View Detailed Pricing Tiers
                </>
              )}
            </Button>
          </div>

          {showDetailedPricing && (
            <div className="mt-12 grid gap-8 md:grid-cols-2">
              {/* SMS Pricing Table */}
              <div className="bg-card rounded-xl p-6 border border-border">
                <h3 className="text-xl font-semibold text-foreground mb-4 flex items-center gap-2">
                  <MessageSquare className="h-5 w-5 text-primary" />
                  SMS Pricing (Pro Plan)
                </h3>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-border">
                        <th className="text-left py-3 text-foreground font-medium">Volume</th>
                        <th className="text-right py-3 text-foreground font-medium">Price per SMS</th>
                      </tr>
                    </thead>
                    <tbody className="text-muted-foreground">
                      {smsPricing.map((tier, index) => (
                        <tr key={index} className="border-b border-border/50">
                          <td className="py-3">{tier.volume}</td>
                          <td className="py-3 text-right font-medium text-foreground">{tier.price}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <p className="text-xs text-muted-foreground mt-4">
                  * Free plan SMS include IEOSUIA branding
                </p>
              </div>

              {/* Email Pricing Table */}
              <div className="bg-card rounded-xl p-6 border border-border">
                <h3 className="text-xl font-semibold text-foreground mb-4 flex items-center gap-2">
                  <Mail className="h-5 w-5 text-primary" />
                  Email Pricing (Pro Plan)
                </h3>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-border">
                        <th className="text-left py-3 text-foreground font-medium">Volume</th>
                        <th className="text-right py-3 text-foreground font-medium">Price per Email</th>
                      </tr>
                    </thead>
                    <tbody className="text-muted-foreground">
                      {emailPricing.map((tier, index) => (
                        <tr key={index} className="border-b border-border/50">
                          <td className="py-3">{tier.volume}</td>
                          <td className="py-3 text-right font-medium text-foreground">{tier.price}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <p className="text-xs text-muted-foreground mt-4">
                  * For 500,000+ emails, <Link to="/contact?purpose=sales" className="text-primary hover:underline">contact our sales team</Link>
                </p>
              </div>
            </div>
          )}

          {/* Pricing FAQs */}
          <div className="mt-16">
            <h3 className="text-2xl font-bold text-foreground text-center mb-8">Pricing FAQs</h3>
            <div className="max-w-3xl mx-auto space-y-4">
              {pricingFaqs.map((faq, index) => (
                <div
                  key={index}
                  className="bg-card rounded-xl border border-border overflow-hidden"
                >
                  <button
                    onClick={() => setExpandedFaq(expandedFaq === index ? null : index)}
                    className="w-full flex items-center justify-between p-4 text-left hover:bg-muted/50 transition-colors"
                  >
                    <span className="font-medium text-foreground">{faq.question}</span>
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