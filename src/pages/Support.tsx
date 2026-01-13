import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";
import {
  ArrowLeft,
  Mail,
  Phone,
  MapPin,
  Clock,
  MessageCircle,
  FileQuestion,
  Book,
  Headphones,
  CreditCard,
  ChevronDown,
  ChevronUp,
  ExternalLink,
} from "lucide-react";
import { useState } from "react";
import { toast } from "@/hooks/use-toast";

const generalFaqs = [
  {
    question: "How do I send my first SMS campaign?",
    answer: "Navigate to SMS Campaigns, click 'New Campaign', select your contacts or upload a list, compose your message, and click Send. You can also schedule it for later.",
  },
  {
    question: "What are SMS credits and how do they work?",
    answer: "SMS credits are used to send messages. Each standard SMS (160 characters) costs 1 credit. Longer messages use multiple credits. You can purchase credits from the Wallet section. New accounts receive 5 free credits to get started.",
  },
  {
    question: "How do I import contacts?",
    answer: "Go to Contacts, click 'Import', and upload a CSV file with phone numbers. The CSV should have columns for phone number and optionally name. You can also add contacts manually or create groups for targeted messaging.",
  },
  {
    question: "What happens when someone opts out?",
    answer: "When a recipient replies with STOP, they are automatically added to your opt-out list. They won't receive future messages, ensuring compliance with POPIA regulations. You can view and manage opt-outs in your Contacts section.",
  },
  {
    question: "How do I track message delivery?",
    answer: "Each campaign shows real-time delivery reports. You can see delivered, pending, and failed messages with timestamps. Detailed analytics including delivery rates and trends are available in the Reports section.",
  },
  {
    question: "Can I schedule messages for later?",
    answer: "Yes! When creating a campaign, choose 'Schedule for later' and select your preferred date and time. Our system even recommends optimal send times based on historical delivery data.",
  },
  {
    question: "What is a Sender ID?",
    answer: "A Sender ID is the name or number that appears as the sender of your SMS. You can request custom Sender IDs from the Sender IDs section. Note that some networks may not support alphanumeric Sender IDs.",
  },
  {
    question: "How do I create an email campaign?",
    answer: "Navigate to Email Campaigns, click 'New Campaign', select your template or create a new one with our rich editor, add recipients, and send or schedule your campaign.",
  },
  {
    question: "What file formats can I import for contacts?",
    answer: "We support CSV (Comma Separated Values) files. The file should have headers and can include phone number, name, email, and other custom fields. We provide a sample template you can download.",
  },
  {
    question: "How do I use personalization in my messages?",
    answer: "Use variables like {name} or {company} in your message. These will be replaced with actual contact data when sending. Make sure your contacts have these fields populated.",
  },
  {
    question: "Can I send to international numbers?",
    answer: "Currently we support South African mobile numbers. International messaging support is coming soon. Contact support for enterprise international messaging needs.",
  },
  {
    question: "How do I cancel a scheduled campaign?",
    answer: "Go to your campaign list, find the scheduled campaign, and click the menu button (three dots). Select 'Cancel' to stop the campaign before it sends.",
  },
];

const pricingFaqs = [
  {
    question: "How much does each SMS cost?",
    answer: "SMS pricing depends on your plan and volume. Starter plan includes 30 free SMS/month. Pro plan at R299/month includes unlimited SMS. Individual credits can be purchased starting from R50 for smaller volumes.",
  },
  {
    question: "What payment methods do you accept?",
    answer: "We accept all major credit and debit cards (Visa, Mastercard), EFT/bank transfers, and mobile payments. All payments are processed securely.",
  },
  {
    question: "Do credits expire?",
    answer: "Purchased credits do not expire. Free credits included in monthly plans reset each billing cycle. Your credit balance is always visible in your Wallet.",
  },
  {
    question: "Can I get a refund on unused credits?",
    answer: "Credits are non-refundable once purchased. However, they never expire so you can use them whenever you need. Contact support for exceptional circumstances.",
  },
  {
    question: "Is there a free trial?",
    answer: "Yes! New accounts receive 5 free SMS credits to test the platform. You can explore all features with the Starter plan at no cost.",
  },
  {
    question: "How do I upgrade my plan?",
    answer: "Go to Settings > Subscription to view available plans. Select the plan you want and complete the payment. Your new plan benefits are available immediately.",
  },
  {
    question: "What's included in the Pro plan?",
    answer: "Pro plan (R299/month) includes unlimited SMS, full template customization, automated reminders, advanced reports, SMS & email sending, and priority support.",
  },
  {
    question: "Do you offer volume discounts?",
    answer: "Yes! Our Business plan at R799/month offers the best rates for high-volume senders. Contact sales for custom enterprise pricing on very high volumes.",
  },
];

const technicalFaqs = [
  {
    question: "What is the maximum message length?",
    answer: "Standard SMS is 160 characters. Longer messages are split into segments of 153 characters each (due to concatenation headers). Our editor shows character count and segment breakdown.",
  },
  {
    question: "Why didn't my message get delivered?",
    answer: "Messages may fail due to: invalid phone number, network issues, phone turned off, or the recipient opted out. Check the delivery report for specific error details.",
  },
  {
    question: "How quickly are messages delivered?",
    answer: "Most messages are delivered within seconds. During peak times or network congestion, delivery may take up to a few minutes. Our average delivery time is under 5 seconds.",
  },
  {
    question: "Can I use special characters or emojis?",
    answer: "Yes, but special characters and emojis use Unicode encoding which reduces the character limit to 70 per segment. We recommend testing with a small batch first.",
  },
  {
    question: "Is my data secure?",
    answer: "Absolutely. We use bank-level encryption (256-bit SSL), secure data centers, and comply with POPIA. Your data is never shared with third parties.",
  },
  {
    question: "Do you have a status page?",
    answer: "Yes, you can check our system status and any ongoing maintenance at our status page. We also notify users of any scheduled maintenance via email.",
  },
];

export default function Support() {
  const [formData, setFormData] = useState({
    name: "",
    email: "",
    subject: "",
    message: "",
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [expandedFaq, setExpandedFaq] = useState<number | null>(null);
  const [faqSection, setFaqSection] = useState<'general' | 'pricing' | 'technical'>('general');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    
    // Simulate form submission
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    toast({
      title: "Message sent!",
      description: "We'll get back to you within 24 hours.",
    });
    
    setFormData({ name: "", email: "", subject: "", message: "" });
    setIsSubmitting(false);
  };

  const currentFaqs = faqSection === 'pricing' ? pricingFaqs : faqSection === 'technical' ? technicalFaqs : generalFaqs;

  return (
    <div className="min-h-screen bg-background">
      {/* Navigation */}
      <nav className="sticky top-0 z-50 bg-background/80 backdrop-blur-md border-b border-border">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <Logo size="md" />
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

      {/* Hero */}
      <section className="py-16 px-4 bg-gradient-to-b from-primary/5 to-background">
        <div className="container mx-auto max-w-4xl text-center">
          <Link to="/landing" className="inline-flex items-center gap-2 text-primary hover:underline mb-6">
            <ArrowLeft className="h-4 w-4" />
            Back to Home
          </Link>
          <h1 className="text-4xl md:text-5xl font-bold text-foreground mb-4">
            How Can We Help?
          </h1>
          <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
            Get answers to your questions, contact our support team, or browse our documentation.
          </p>
        </div>
      </section>

      {/* Quick Links */}
      <section className="py-12 px-4">
        <div className="container mx-auto max-w-6xl">
          <div className="grid gap-6 md:grid-cols-3">
            <Link to="/documentation" className="block">
              <div className="bg-card rounded-xl p-6 border border-border hover:shadow-lg transition-shadow h-full">
                <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary mb-4">
                  <Book className="h-6 w-6" />
                </div>
                <h3 className="text-lg font-semibold text-foreground mb-2">Documentation</h3>
                <p className="text-muted-foreground mb-4">
                  Learn how to use all features with our comprehensive guides.
                </p>
                <Button variant="outline" className="w-full">View Docs</Button>
              </div>
            </Link>
            <div className="bg-card rounded-xl p-6 border border-border hover:shadow-lg transition-shadow">
              <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary mb-4">
                <FileQuestion className="h-6 w-6" />
              </div>
              <h3 className="text-lg font-semibold text-foreground mb-2">FAQs</h3>
              <p className="text-muted-foreground mb-4">
                Find quick answers to commonly asked questions below.
              </p>
              <Button variant="outline" className="w-full" onClick={() => document.getElementById('faqs')?.scrollIntoView({ behavior: 'smooth' })}>
                View FAQs
              </Button>
            </div>
            <div className="bg-card rounded-xl p-6 border border-border hover:shadow-lg transition-shadow">
              <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary mb-4">
                <Headphones className="h-6 w-6" />
              </div>
              <h3 className="text-lg font-semibold text-foreground mb-2">Live Support</h3>
              <p className="text-muted-foreground mb-4">
                Chat with our team for immediate assistance.
              </p>
              <a href="https://wa.me/27799282775" target="_blank" rel="noopener noreferrer">
                <Button variant="outline" className="w-full gap-2">
                  <MessageCircle className="h-4 w-4" />
                  WhatsApp Chat
                </Button>
              </a>
            </div>
          </div>
        </div>
      </section>

      {/* FAQs */}
      <section id="faqs" className="py-16 px-4 bg-muted/30">
        <div className="container mx-auto max-w-4xl">
          <h2 className="text-3xl font-bold text-foreground mb-4 text-center">
            Frequently Asked Questions
          </h2>
          
          {/* FAQ Category Tabs */}
          <div className="flex justify-center gap-2 mb-8">
            <Button
              variant={faqSection === 'general' ? 'default' : 'outline'}
              onClick={() => { setFaqSection('general'); setExpandedFaq(null); }}
              className="gap-2"
            >
              <FileQuestion className="h-4 w-4" />
              General
            </Button>
            <Button
              variant={faqSection === 'pricing' ? 'default' : 'outline'}
              onClick={() => { setFaqSection('pricing'); setExpandedFaq(null); }}
              className="gap-2"
            >
              <CreditCard className="h-4 w-4" />
              Pricing
            </Button>
            <Button
              variant={faqSection === 'technical' ? 'default' : 'outline'}
              onClick={() => { setFaqSection('technical'); setExpandedFaq(null); }}
              className="gap-2"
            >
              <Book className="h-4 w-4" />
              Technical
            </Button>
          </div>
          
          <div className="space-y-3">
            {currentFaqs.map((faq, index) => (
              <div key={index} className="bg-card rounded-xl border border-border overflow-hidden">
                <button
                  onClick={() => setExpandedFaq(expandedFaq === index ? null : index)}
                  className="w-full p-5 text-left flex items-center justify-between gap-4"
                >
                  <h3 className="text-base font-semibold text-foreground pr-4">{faq.question}</h3>
                  {expandedFaq === index ? (
                    <ChevronUp className="h-5 w-5 text-muted-foreground shrink-0" />
                  ) : (
                    <ChevronDown className="h-5 w-5 text-muted-foreground shrink-0" />
                  )}
                </button>
                {expandedFaq === index && (
                  <div className="px-5 pb-5 pt-0">
                    <p className="text-muted-foreground">{faq.answer}</p>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Video Tutorials */}
      <section className="py-12 px-4">
        <div className="container mx-auto max-w-4xl">
          <div className="bg-card rounded-xl p-8 border border-border text-center">
            <h2 className="text-2xl font-bold text-foreground mb-3">Video Tutorials</h2>
            <p className="text-muted-foreground mb-6">
              Watch step-by-step video guides on our YouTube channel.
            </p>
            <a 
              href="https://www.youtube.com/@ieosuia" 
              target="_blank" 
              rel="noopener noreferrer"
            >
              <Button className="gap-2">
                <ExternalLink className="h-4 w-4" />
                Watch on YouTube
              </Button>
            </a>
          </div>
        </div>
      </section>

      {/* Contact Form & Info */}
      <section className="py-16 px-4 bg-muted/30">
        <div className="container mx-auto max-w-6xl">
          <h2 className="text-3xl font-bold text-foreground mb-8 text-center">
            Contact Support
          </h2>
          <div className="grid gap-8 md:grid-cols-2">
            {/* Contact Info */}
            <div className="space-y-6">
              <p className="text-muted-foreground">
                Can't find what you're looking for? Our support team is here to help. 
                Reach out through any of the channels below.
              </p>
              <div className="space-y-4">
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <Mail className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">Email</h4>
                    <p className="text-muted-foreground">support@ieosuia.com</p>
                    <p className="text-sm text-muted-foreground">General: hello@ieosuia.com</p>
                  </div>
                </div>
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <Phone className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">Phone (Calls Only)</h4>
                    <p className="text-muted-foreground">079 928 2775</p>
                    <p className="text-muted-foreground">063 154 0696</p>
                  </div>
                </div>
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <MessageCircle className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">WhatsApp</h4>
                    <a href="https://wa.me/27799282775" target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                      +27 79 928 2775
                    </a>
                    <p className="text-sm text-muted-foreground">Quick responses during business hours</p>
                  </div>
                </div>
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <MapPin className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">Office</h4>
                    <p className="text-muted-foreground">26 Rock Alder, Extension 15</p>
                    <p className="text-muted-foreground">Naturena, Johannesburg, 2095</p>
                  </div>
                </div>
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <Clock className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">Business Hours</h4>
                    <p className="text-muted-foreground">Monday - Friday: 8:00 AM - 5:00 PM</p>
                    <p className="text-muted-foreground">Saturday - Sunday: Closed</p>
                  </div>
                </div>
              </div>
            </div>

            {/* Contact Form */}
            <div className="bg-card rounded-xl p-6 border border-border">
              <h3 className="text-xl font-semibold text-foreground mb-4">Send us a message</h3>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label htmlFor="name">Name</Label>
                    <Input
                      id="name"
                      placeholder="Your name"
                      value={formData.name}
                      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                      className="mt-1.5"
                      required
                    />
                  </div>
                  <div>
                    <Label htmlFor="email">Email</Label>
                    <Input
                      id="email"
                      type="email"
                      placeholder="you@example.com"
                      value={formData.email}
                      onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                      className="mt-1.5"
                      required
                    />
                  </div>
                </div>
                <div>
                  <Label htmlFor="subject">Subject</Label>
                  <Input
                    id="subject"
                    placeholder="How can we help?"
                    value={formData.subject}
                    onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
                    className="mt-1.5"
                    required
                  />
                </div>
                <div>
                  <Label htmlFor="message">Message</Label>
                  <Textarea
                    id="message"
                    placeholder="Describe your issue or question..."
                    value={formData.message}
                    onChange={(e) => setFormData({ ...formData, message: e.target.value })}
                    className="mt-1.5 min-h-[120px]"
                    required
                  />
                </div>
                <p className="text-xs text-muted-foreground">
                  This enquiry is from IEOSUIA SMS Portal.
                </p>
                <Button type="submit" className="w-full" disabled={isSubmitting}>
                  {isSubmitting ? "Sending..." : "Send Message"}
                </Button>
              </form>
            </div>
          </div>
        </div>
      </section>

      <Footer variant="simple" />
    </div>
  );
}
