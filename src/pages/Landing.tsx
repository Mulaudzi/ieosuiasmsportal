import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import {
  MessageSquare,
  Mail,
  Users,
  BarChart3,
  Shield,
  Zap,
  Check,
  ArrowRight,
  Phone,
  MapPin,
  Clock,
  MessageCircle,
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
    name: "Starter",
    price: "Free",
    description: "Perfect for small businesses getting started",
    features: [
      "Up to 30 SMS/month",
      "3 Basic templates",
      "Basic dashboard",
      "Email support",
      "IEOSUIA watermark",
    ],
    cta: "Start Free",
    popular: false,
  },
  {
    name: "Pro",
    price: "R299",
    period: "/month",
    description: "For growing businesses with higher volume needs",
    features: [
      "Unlimited SMS",
      "Full template customization",
      "Automated reminders",
      "Advanced reports",
      "SMS & Email sending",
      "Priority support",
    ],
    cta: "Start Free Trial",
    popular: true,
  },
  {
    name: "Business",
    price: "R799",
    period: "/month",
    description: "Enterprise features for large organizations",
    features: [
      "Everything in Pro",
      "Multi-user access",
      "Multi-business support",
      "Role-based permissions",
      "Advanced ledger",
      "API access",
      "White-labeling",
      "Dedicated support",
    ],
    cta: "Contact Sales",
    popular: false,
  },
];

export default function Landing() {
  return (
    <div className="min-h-screen bg-background">
      {/* Navigation */}
      <nav className="fixed top-0 left-0 right-0 z-50 bg-background/80 backdrop-blur-md border-b border-border">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary">
              <Zap className="h-6 w-6 text-primary-foreground" />
            </div>
            <span className="text-xl font-bold text-foreground">IEOSUIA SMS</span>
          </div>
          <div className="hidden md:flex items-center gap-8">
            <a href="#features" className="text-muted-foreground hover:text-foreground transition-colors">Features</a>
            <a href="#how-it-works" className="text-muted-foreground hover:text-foreground transition-colors">How it Works</a>
            <a href="#pricing" className="text-muted-foreground hover:text-foreground transition-colors">Pricing</a>
            <a href="#contact" className="text-muted-foreground hover:text-foreground transition-colors">Contact</a>
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
            <span className="text-sm font-medium text-primary">Trusted by 10,000+ businesses</span>
          </div>
          <h1 className="text-4xl md:text-6xl font-bold text-foreground leading-tight mb-6">
            Professional Messaging
            <br />
            <span className="text-primary">Made Simple</span>
          </h1>
          <p className="text-xl text-muted-foreground mb-8 max-w-2xl mx-auto">
            Send bulk SMS and email campaigns, track delivery reports, and grow your business with our powerful messaging platform.
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
            No credit card required • 30 free SMS per month
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
              Choose the plan that fits your needs. No hidden fees.
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
                      Most Popular
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
                <Link to="/register">
                  <Button className="w-full" variant={plan.popular ? "default" : "outline"}>
                    {plan.cta}
                  </Button>
                </Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Contact Section */}
      <section id="contact" className="py-20 px-4">
        <div className="container mx-auto max-w-4xl">
          <div className="text-center mb-12">
            <h2 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
              Get in Touch
            </h2>
            <p className="text-lg text-muted-foreground">
              Have questions? We're here to help.
            </p>
          </div>
          <div className="grid gap-8 md:grid-cols-2">
            <div className="space-y-6">
              <div className="flex items-start gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                  <Mail className="h-5 w-5" />
                </div>
                <div>
                  <h4 className="font-semibold text-foreground">Email</h4>
                  <p className="text-muted-foreground">support@ieosuia.com</p>
                </div>
              </div>
              <div className="flex items-start gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                  <Phone className="h-5 w-5" />
                </div>
                <div>
                  <h4 className="font-semibold text-foreground">Phone</h4>
                  <p className="text-muted-foreground">+27 11 123 4567</p>
                </div>
              </div>
              <div className="flex items-start gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                  <MapPin className="h-5 w-5" />
                </div>
                <div>
                  <h4 className="font-semibold text-foreground">Address</h4>
                  <p className="text-muted-foreground">106 Harry Street, Robertsham<br />Johannesburg, 2190</p>
                </div>
              </div>
              <div className="flex items-start gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                  <Clock className="h-5 w-5" />
                </div>
                <div>
                  <h4 className="font-semibold text-foreground">Business Hours</h4>
                  <p className="text-muted-foreground">Mon - Fri: 8:00 AM - 5:00 PM</p>
                </div>
              </div>
            </div>
            <div className="bg-card rounded-xl p-6 border border-border">
              <form className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-foreground mb-1.5">Name</label>
                  <input
                    type="text"
                    className="w-full rounded-lg border border-border bg-background px-4 py-2.5 text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    placeholder="Your name"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-foreground mb-1.5">Email</label>
                  <input
                    type="email"
                    className="w-full rounded-lg border border-border bg-background px-4 py-2.5 text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    placeholder="you@example.com"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-foreground mb-1.5">Message</label>
                  <textarea
                    rows={4}
                    className="w-full rounded-lg border border-border bg-background px-4 py-2.5 text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary resize-none"
                    placeholder="How can we help?"
                  />
                </div>
                <Button type="submit" className="w-full">Send Message</Button>
              </form>
            </div>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="bg-sidebar py-12 px-4">
        <div className="container mx-auto max-w-6xl">
          <div className="grid gap-8 md:grid-cols-4">
            <div>
              <div className="flex items-center gap-2 mb-4">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary">
                  <Zap className="h-5 w-5 text-primary-foreground" />
                </div>
                <span className="text-lg font-bold text-sidebar-primary-foreground">IEOSUIA SMS</span>
              </div>
              <p className="text-sm text-sidebar-muted">
                Professional bulk SMS and email messaging platform for businesses.
              </p>
            </div>
            <div>
              <h4 className="font-semibold text-sidebar-primary-foreground mb-4">Product</h4>
              <ul className="space-y-2 text-sm text-sidebar-muted">
                <li><a href="#features" className="hover:text-sidebar-primary-foreground">Features</a></li>
                <li><a href="#pricing" className="hover:text-sidebar-primary-foreground">Pricing</a></li>
                <li><Link to="/login" className="hover:text-sidebar-primary-foreground">Login</Link></li>
                <li><Link to="/register" className="hover:text-sidebar-primary-foreground">Sign Up</Link></li>
              </ul>
            </div>
            <div>
              <h4 className="font-semibold text-sidebar-primary-foreground mb-4">Support</h4>
              <ul className="space-y-2 text-sm text-sidebar-muted">
                <li><a href="#contact" className="hover:text-sidebar-primary-foreground">Contact Us</a></li>
                <li><a href="#" className="hover:text-sidebar-primary-foreground">Help Center</a></li>
                <li><a href="#" className="hover:text-sidebar-primary-foreground">API Documentation</a></li>
                <li><a href="#" className="hover:text-sidebar-primary-foreground">Status</a></li>
              </ul>
            </div>
            <div>
              <h4 className="font-semibold text-sidebar-primary-foreground mb-4">Legal</h4>
              <ul className="space-y-2 text-sm text-sidebar-muted">
                <li><a href="#" className="hover:text-sidebar-primary-foreground">Privacy Policy</a></li>
                <li><a href="#" className="hover:text-sidebar-primary-foreground">Terms of Service</a></li>
                <li><a href="#" className="hover:text-sidebar-primary-foreground">POPIA Compliance</a></li>
              </ul>
            </div>
          </div>
          <div className="mt-8 pt-8 border-t border-sidebar-border text-center text-sm text-sidebar-muted">
            © 2026 IEOSUIA SMS. All rights reserved.
          </div>
        </div>
      </footer>

      {/* WhatsApp Button */}
      <a
        href="https://wa.me/27111234567"
        target="_blank"
        rel="noopener noreferrer"
        className="fixed bottom-6 right-6 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg hover:bg-[#20BD5A] transition-colors"
        aria-label="Chat on WhatsApp"
      >
        <MessageCircle className="h-7 w-7" />
      </a>
    </div>
  );
}
