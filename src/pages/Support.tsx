import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import {
  Zap,
  ArrowLeft,
  Mail,
  Phone,
  MapPin,
  Clock,
  MessageCircle,
  FileQuestion,
  Book,
  Headphones,
} from "lucide-react";
import { useState } from "react";
import { toast } from "@/hooks/use-toast";

const faqs = [
  {
    question: "How do I send my first SMS campaign?",
    answer: "Navigate to SMS Campaigns, click 'New Campaign', select your contacts or upload a list, compose your message, and click Send. You can also schedule it for later.",
  },
  {
    question: "What are SMS credits and how do they work?",
    answer: "SMS credits are used to send messages. Each standard SMS costs 1 credit. You can purchase credits from the Wallet section. New accounts receive 5 free credits to get started.",
  },
  {
    question: "How do I import contacts?",
    answer: "Go to Contacts, click 'Import', and upload a CSV file with phone numbers. You can also add contacts manually or create groups for targeted messaging.",
  },
  {
    question: "What happens when someone opts out?",
    answer: "When a recipient replies with STOP, they are automatically added to your opt-out list. They won't receive future messages, ensuring compliance with regulations.",
  },
  {
    question: "How do I track message delivery?",
    answer: "Each campaign shows real-time delivery reports. You can see delivered, pending, and failed messages. Detailed analytics are available in the Reports section.",
  },
  {
    question: "Can I schedule messages for later?",
    answer: "Yes! When creating a campaign, you can choose to send immediately or schedule for a specific date and time. This is great for timed promotions.",
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

  return (
    <div className="min-h-screen bg-background">
      {/* Navigation */}
      <nav className="sticky top-0 z-50 bg-background/80 backdrop-blur-md border-b border-border">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <Link to="/landing" className="flex items-center gap-2">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary">
              <Zap className="h-6 w-6 text-primary-foreground" />
            </div>
            <span className="text-xl font-bold text-foreground">IEOSUIA SMS PORTAL</span>
          </Link>
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
            <div className="bg-card rounded-xl p-6 border border-border hover:shadow-lg transition-shadow">
              <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary mb-4">
                <Book className="h-6 w-6" />
              </div>
              <h3 className="text-lg font-semibold text-foreground mb-2">Documentation</h3>
              <p className="text-muted-foreground mb-4">
                Learn how to use all features with our comprehensive guides.
              </p>
              <Button variant="outline" className="w-full">View Docs</Button>
            </div>
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
              <a href="https://wa.me/27111234567" target="_blank" rel="noopener noreferrer">
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
          <h2 className="text-3xl font-bold text-foreground mb-8 text-center">
            Frequently Asked Questions
          </h2>
          <div className="space-y-4">
            {faqs.map((faq, index) => (
              <div key={index} className="bg-card rounded-xl p-6 border border-border">
                <h3 className="text-lg font-semibold text-foreground mb-2">{faq.question}</h3>
                <p className="text-muted-foreground">{faq.answer}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Contact Form & Info */}
      <section className="py-16 px-4">
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
                    <p className="text-sm text-muted-foreground">We respond within 24 hours</p>
                  </div>
                </div>
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <Phone className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">Phone</h4>
                    <p className="text-muted-foreground">+27 11 123 4567</p>
                    <p className="text-sm text-muted-foreground">Mon-Fri, 8AM-5PM SAST</p>
                  </div>
                </div>
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <MessageCircle className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">WhatsApp</h4>
                    <p className="text-muted-foreground">+27 11 123 4567</p>
                    <p className="text-sm text-muted-foreground">Quick responses during business hours</p>
                  </div>
                </div>
                <div className="flex items-start gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <MapPin className="h-5 w-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-foreground">Office</h4>
                    <p className="text-muted-foreground">106 Harry Street, Robertsham</p>
                    <p className="text-muted-foreground">Johannesburg, 2190</p>
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
                <Button type="submit" className="w-full" disabled={isSubmitting}>
                  {isSubmitting ? "Sending..." : "Send Message"}
                </Button>
              </form>
            </div>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="bg-sidebar py-8 px-4">
        <div className="container mx-auto max-w-6xl text-center">
          <div className="flex items-center justify-center gap-2 mb-4">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary">
              <Zap className="h-5 w-5 text-primary-foreground" />
            </div>
            <span className="text-lg font-bold text-sidebar-primary-foreground">IEOSUIA SMS PORTAL</span>
          </div>
          <p className="text-sm text-sidebar-muted mb-4">
            Professional bulk SMS and email messaging platform for businesses.
          </p>
          <div className="flex justify-center gap-6 text-sm text-sidebar-muted">
            <Link to="/terms-of-service" className="hover:text-sidebar-primary-foreground">Terms of Service</Link>
            <Link to="/privacy-policy" className="hover:text-sidebar-primary-foreground">Privacy Policy</Link>
            <Link to="/landing" className="hover:text-sidebar-primary-foreground">Home</Link>
          </div>
          <p className="mt-6 text-sm text-sidebar-muted">
            © 2026 IEOSUIA SMS PORTAL. All rights reserved.
          </p>
        </div>
      </footer>
    </div>
  );
}
