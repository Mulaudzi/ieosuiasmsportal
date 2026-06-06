import { Link, useSearchParams } from "react-router-dom";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { 
  Mail, 
  Phone, 
  MapPin, 
  Clock, 
  MessageCircle,
  ArrowLeft,
  Send,
  HelpCircle,
  ShoppingBag,
  Smile,
} from "lucide-react";
import { useState, useEffect } from "react";
import { toast } from "@/hooks/use-toast";
import { cn } from "@/lib/utils";

type InquiryPurpose = "general" | "support" | "sales";

interface PurposeOption {
  id: InquiryPurpose;
  label: string;
  description: string;
  icon: React.ElementType;
  email: string;
}

const purposeOptions: PurposeOption[] = [
  {
    id: "general",
    label: "General / Friendly Inquiry",
    description: "Say hello, ask a question, or provide feedback",
    icon: Smile,
    email: "hello@ieosuia.com",
  },
  {
    id: "support",
    label: "Support / Technical Help",
    description: "Get help with your account or technical issues",
    icon: HelpCircle,
    email: "support@ieosuia.com",
  },
  {
    id: "sales",
    label: "Sales / Quotes / Partnerships",
    description: "Discuss pricing, volume discounts, or partnerships",
    icon: ShoppingBag,
    email: "sales@ieosuia.com",
  },
];

export default function Contact() {
  const [searchParams] = useSearchParams();
  
  const [formData, setFormData] = useState({
    name: "",
    email: "",
    message: "",
  });
  const [purpose, setPurpose] = useState<InquiryPurpose>("general");
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Set purpose from URL params (for preselection from other pages)
  useEffect(() => {
    const purposeParam = searchParams.get("purpose") as InquiryPurpose;
    if (purposeParam && ["general", "support", "sales"].includes(purposeParam)) {
      setPurpose(purposeParam);
    }
  }, [searchParams]);

  const getEmailRecipient = () => {
    return purposeOptions.find(p => p.id === purpose)?.email || "hello@ieosuia.com";
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    
    try {
      const apiUrl = import.meta.env.VITE_API_URL || 'https://sms.ieosuia.com/api';
      
      const response = await fetch(`${apiUrl}/contact`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          name: formData.name,
          email: formData.email,
          message: formData.message,
          purpose: purpose,
          originUrl: window.location.href,
        }),
      });
      
      const result = await response.json();
      
      if (!response.ok) {
        throw new Error(result.error || 'Failed to send message');
      }
      
      toast({
        title: "Message Sent",
        description: `Thank you for your message. Our ${purpose === "sales" ? "sales" : purpose === "support" ? "support" : ""} team will get back to you soon!`,
      });
      
      setFormData({ name: "", email: "", message: "" });
      setPurpose("general");
    } catch (error) {
      toast({
        title: "Error",
        description: error instanceof Error ? error.message : "Failed to send message. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  const selectedPurpose = purposeOptions.find(p => p.id === purpose);

  return (
    <div className="min-h-screen bg-background">
      {/* Navigation */}
      <nav className="bg-background border-b border-border">
        <div className="container mx-auto px-4 py-4 flex items-center justify-between">
          <Logo size="md" linkTo="/landing" />
          <Link to="/landing">
            <Button variant="ghost" size="sm">
              <ArrowLeft className="h-4 w-4 mr-2" />
              Back to Home
            </Button>
          </Link>
        </div>
      </nav>

      {/* Hero Section */}
      <section className="py-16 px-4 bg-gradient-to-b from-primary/5 to-background">
        <div className="container mx-auto max-w-4xl text-center">
          <h1 className="text-4xl md:text-5xl font-bold text-foreground mb-4">
            Contact Us
          </h1>
          <p className="text-xl text-muted-foreground">
            Get in touch with our team. We're here to help with any questions about our SMS and email messaging services.
          </p>
        </div>
      </section>

      {/* Contact Information */}
      <section className="py-16 px-4">
        <div className="container mx-auto max-w-6xl">
          <div className="grid gap-12 lg:grid-cols-2">
            {/* Contact Details */}
            <div>
              <h2 className="text-2xl font-bold text-foreground mb-8">Get in Touch</h2>
              
              <div className="space-y-6">
                <div className="flex items-start gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                    <Mail className="h-6 w-6" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-foreground text-lg">Email</h3>
                    <p className="text-muted-foreground mt-1">
                      <strong>General:</strong> hello@ieosuia.com
                    </p>
                    <p className="text-muted-foreground">
                      <strong>Support:</strong> support@ieosuia.com
                    </p>
                    <p className="text-muted-foreground">
                      <strong>Sales:</strong> sales@ieosuia.com
                    </p>
                    <p className="text-xs text-muted-foreground mt-2 italic">
                      Use the form to ensure your message reaches the right team
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                    <Phone className="h-6 w-6" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-foreground text-lg">Phone (Calls Only)</h3>
                    <p className="text-muted-foreground mt-1">
                      <a href="tel:+27799282775" className="hover:text-primary">079 928 2775</a>
                    </p>
                    <p className="text-muted-foreground">
                      <a href="tel:+27631540696" className="hover:text-primary">063 154 0696</a>
                    </p>
                    <p className="text-sm text-muted-foreground mt-2">
                      <em>Note: We do not have a landline at the moment.</em>
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                    <MessageCircle className="h-6 w-6" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-foreground text-lg">WhatsApp</h3>
                    <p className="text-muted-foreground mt-1">
                      <a 
                        href="https://wa.me/27799282775" 
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="text-primary hover:underline"
                      >
                        +27 79 928 2775
                      </a>
                    </p>
                    <p className="text-sm text-muted-foreground mt-1">
                      Click to chat with us on WhatsApp
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                    <MapPin className="h-6 w-6" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-foreground text-lg">Office Address</h3>
                    <p className="text-muted-foreground mt-1">
                      26 Rock Alder<br />
                      Extension 15<br />
                      Naturena, Johannesburg<br />
                      2095, South Africa
                    </p>
                  </div>
                </div>

                <div className="flex items-start gap-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                    <Clock className="h-6 w-6" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-foreground text-lg">Business Hours</h3>
                    <p className="text-muted-foreground mt-1">
                      Monday - Friday: 8:00 AM - 5:00 PM<br />
                      Saturday - Sunday: Closed
                    </p>
                  </div>
                </div>
              </div>
            </div>

            {/* Contact Form */}
            <div>
              <h2 className="text-2xl font-bold text-foreground mb-8">Send Us a Message</h2>
              
              <form onSubmit={handleSubmit} className="bg-card rounded-xl p-6 border border-border space-y-5">
                {/* Purpose Selection */}
                <div>
                  <Label className="mb-3 block">What can we help you with?</Label>
                  <div className="grid gap-3">
                    {purposeOptions.map((option) => {
                      const Icon = option.icon;
                      return (
                        <button
                          key={option.id}
                          type="button"
                          onClick={() => setPurpose(option.id)}
                          className={cn(
                            "flex items-start gap-3 p-4 rounded-lg border text-left transition-all",
                            purpose === option.id
                              ? "border-primary bg-primary/5"
                              : "border-border hover:border-primary/50"
                          )}
                        >
                          <div className={cn(
                            "h-10 w-10 rounded-lg flex items-center justify-center shrink-0",
                            purpose === option.id ? "bg-primary/10" : "bg-muted"
                          )}>
                            <Icon className={cn(
                              "h-5 w-5",
                              purpose === option.id ? "text-primary" : "text-muted-foreground"
                            )} />
                          </div>
                          <div>
                            <p className={cn(
                              "font-medium",
                              purpose === option.id ? "text-primary" : "text-foreground"
                            )}>
                              {option.label}
                            </p>
                            <p className="text-sm text-muted-foreground">
                              {option.description}
                            </p>
                          </div>
                        </button>
                      );
                    })}
                  </div>
                </div>

                <div>
                  <Label htmlFor="name">Name</Label>
                  <input
                    id="name"
                    type="text"
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="mt-1.5 w-full rounded-lg border border-border bg-background px-4 py-2.5 text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    placeholder="Your name"
                  />
                </div>

                <div>
                  <Label htmlFor="email">Email</Label>
                  <input
                    id="email"
                    type="email"
                    required
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    className="mt-1.5 w-full rounded-lg border border-border bg-background px-4 py-2.5 text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    placeholder="you@example.com"
                  />
                </div>

                <div>
                  <Label htmlFor="message">Message</Label>
                  <textarea
                    id="message"
                    rows={5}
                    required
                    value={formData.message}
                    onChange={(e) => setFormData({ ...formData, message: e.target.value })}
                    className="mt-1.5 w-full rounded-lg border border-border bg-background px-4 py-2.5 text-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary resize-none"
                    placeholder="How can we help you?"
                  />
                </div>

                <div className="bg-muted/50 rounded-lg p-3 text-sm">
                  <p className="text-muted-foreground">
                    Your message will be sent to <strong className="text-foreground">{selectedPurpose?.email}</strong>
                    {" "}with a copy to <strong className="text-foreground">info@ieosuia.com</strong>
                  </p>
                </div>

                <Button type="submit" className="w-full" disabled={isSubmitting}>
                  {isSubmitting ? (
                    "Sending..."
                  ) : (
                    <>
                      <Send className="h-4 w-4 mr-2" />
                      Send Message
                    </>
                  )}
                </Button>
              </form>
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
