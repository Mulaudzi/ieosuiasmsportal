import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";
import {
  ArrowLeft,
  Briefcase,
  Users,
  Rocket,
  Heart,
  Mail,
  MapPin,
  Clock,
  Sparkles,
} from "lucide-react";

const values = [
  {
    icon: Rocket,
    title: "Innovation",
    description: "We're always looking for new ways to improve our platform and services.",
  },
  {
    icon: Users,
    title: "Collaboration",
    description: "We believe great things happen when talented people work together.",
  },
  {
    icon: Heart,
    title: "Customer Focus",
    description: "Our customers are at the heart of everything we do.",
  },
  {
    icon: Sparkles,
    title: "Excellence",
    description: "We strive for excellence in every aspect of our work.",
  },
];

export default function Careers() {
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
      <section className="py-20 px-4 bg-gradient-to-b from-primary/5 to-background">
        <div className="container mx-auto max-w-4xl text-center">
          <Link to="/landing" className="inline-flex items-center gap-2 text-primary hover:underline mb-6">
            <ArrowLeft className="h-4 w-4" />
            Back to Home
          </Link>
          <div className="flex items-center justify-center gap-3 mb-6">
            <Briefcase className="h-12 w-12 text-primary" />
          </div>
          <h1 className="text-4xl md:text-5xl font-bold text-foreground mb-4">
            Join Our Team
          </h1>
          <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
            Be part of building South Africa's leading messaging platform.
          </p>
        </div>
      </section>

      {/* Coming Soon */}
      <section className="py-16 px-4">
        <div className="container mx-auto max-w-3xl">
          <div className="bg-card rounded-2xl p-8 md:p-12 border border-border text-center">
            <div className="inline-flex items-center gap-2 bg-primary/10 rounded-full px-4 py-2 mb-6">
              <Clock className="h-4 w-4 text-primary" />
              <span className="text-sm font-medium text-primary">Coming Soon</span>
            </div>
            <h2 className="text-3xl font-bold text-foreground mb-4">
              We're Growing!
            </h2>
            <p className="text-lg text-muted-foreground mb-8 max-w-xl mx-auto">
              IEOSUIA is expanding and we'll be hiring soon! We're looking for passionate 
              individuals who want to make a difference in how businesses communicate.
            </p>
            <div className="bg-muted/50 rounded-xl p-6 mb-8">
              <h3 className="font-semibold text-foreground mb-3">Be the First to Know</h3>
              <p className="text-sm text-muted-foreground mb-4">
                Send us your CV and we'll reach out when positions become available.
              </p>
              <a href="mailto:careers@ieosuia.com?subject=Career Enquiry - IEOSUIA SMS Portal">
                <Button className="gap-2">
                  <Mail className="h-4 w-4" />
                  Send Your CV
                </Button>
              </a>
            </div>
            <div className="flex items-center justify-center gap-2 text-muted-foreground">
              <MapPin className="h-4 w-4" />
              <span className="text-sm">Based in Johannesburg, South Africa</span>
            </div>
          </div>
        </div>
      </section>

      {/* Our Values */}
      <section className="py-16 px-4 bg-muted/30">
        <div className="container mx-auto max-w-6xl">
          <h2 className="text-3xl font-bold text-foreground mb-8 text-center">
            Our Values
          </h2>
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            {values.map((value) => (
              <div key={value.title} className="bg-card rounded-xl p-6 border border-border text-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary mx-auto mb-4">
                  <value.icon className="h-7 w-7" />
                </div>
                <h3 className="text-lg font-semibold text-foreground mb-2">{value.title}</h3>
                <p className="text-sm text-muted-foreground">{value.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Why Join Us */}
      <section className="py-16 px-4">
        <div className="container mx-auto max-w-4xl">
          <h2 className="text-3xl font-bold text-foreground mb-8 text-center">
            Why Join IEOSUIA?
          </h2>
          <div className="grid gap-6 md:grid-cols-2">
            <div className="bg-card rounded-xl p-6 border border-border">
              <h3 className="font-semibold text-foreground mb-2">🚀 Growth Opportunity</h3>
              <p className="text-muted-foreground">
                Join a growing company where you can make a real impact and grow your career.
              </p>
            </div>
            <div className="bg-card rounded-xl p-6 border border-border">
              <h3 className="font-semibold text-foreground mb-2">🎯 Meaningful Work</h3>
              <p className="text-muted-foreground">
                Help businesses across South Africa communicate more effectively with their customers.
              </p>
            </div>
            <div className="bg-card rounded-xl p-6 border border-border">
              <h3 className="font-semibold text-foreground mb-2">💡 Innovation Culture</h3>
              <p className="text-muted-foreground">
                Work with modern technologies and contribute to building cutting-edge solutions.
              </p>
            </div>
            <div className="bg-card rounded-xl p-6 border border-border">
              <h3 className="font-semibold text-foreground mb-2">🤝 Great Team</h3>
              <p className="text-muted-foreground">
                Collaborate with passionate, talented individuals who love what they do.
              </p>
            </div>
          </div>
        </div>
      </section>

      <Footer variant="simple" />
    </div>
  );
}
