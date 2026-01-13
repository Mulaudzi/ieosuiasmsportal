import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/layout/Logo";
import { Footer } from "@/components/layout/Footer";
import {
  ArrowLeft,
  MessageSquare,
  Mail,
  Users,
  BarChart3,
  Settings,
  Wallet,
  FileText,
  Clock,
  Phone,
  Shield,
  Zap,
  BookOpen,
  Video,
  ExternalLink,
} from "lucide-react";

const guides = [
  {
    icon: MessageSquare,
    title: "SMS Campaigns",
    description: "Learn how to create, schedule, and send bulk SMS campaigns to your contacts.",
    topics: [
      "Creating your first SMS campaign",
      "Importing and managing contacts",
      "Scheduling messages for optimal delivery",
      "Using personalization variables",
      "Understanding delivery reports",
    ],
  },
  {
    icon: Mail,
    title: "Email Campaigns",
    description: "Master email marketing with our rich template editor and analytics.",
    topics: [
      "Building email templates",
      "Using the drag-and-drop editor",
      "Setting up email campaigns",
      "A/B testing your messages",
      "Tracking opens and clicks",
    ],
  },
  {
    icon: Users,
    title: "Contact Management",
    description: "Organize your contacts into groups and manage opt-outs effectively.",
    topics: [
      "Importing contacts via CSV",
      "Creating contact groups",
      "Managing subscription status",
      "Handling opt-out requests",
      "Cleaning your contact list",
    ],
  },
  {
    icon: FileText,
    title: "Templates",
    description: "Create reusable templates to save time and maintain consistency.",
    topics: [
      "Creating SMS templates",
      "Building email templates",
      "Using variables in templates",
      "Template best practices",
      "Managing your template library",
    ],
  },
  {
    icon: BarChart3,
    title: "Reports & Analytics",
    description: "Understand your campaign performance with detailed analytics.",
    topics: [
      "Reading delivery reports",
      "Analyzing campaign metrics",
      "Comparing campaign performance",
      "Exporting report data",
      "Setting up automated reports",
    ],
  },
  {
    icon: Wallet,
    title: "Wallet & Credits",
    description: "Manage your SMS credits and understand billing.",
    topics: [
      "Understanding credit system",
      "Purchasing credits",
      "Viewing transaction history",
      "Managing payment methods",
      "Understanding pricing",
    ],
  },
  {
    icon: Settings,
    title: "Account Settings",
    description: "Configure your account preferences and security settings.",
    topics: [
      "Updating profile information",
      "Managing sender IDs",
      "Configuring notifications",
      "Security best practices",
      "Two-factor authentication",
    ],
  },
  {
    icon: Shield,
    title: "Compliance",
    description: "Stay compliant with POPIA and messaging regulations.",
    topics: [
      "Understanding POPIA requirements",
      "Obtaining consent",
      "Managing opt-outs",
      "Record keeping",
      "Best practices for compliance",
    ],
  },
];

const quickStart = [
  {
    step: 1,
    title: "Create Your Account",
    description: "Sign up for free and verify your email address to get started with 5 free SMS credits.",
  },
  {
    step: 2,
    title: "Add Your Contacts",
    description: "Import your contacts via CSV file or add them manually. Organize them into groups for targeted messaging.",
  },
  {
    step: 3,
    title: "Create a Campaign",
    description: "Choose SMS or Email, compose your message, select your recipients, and review before sending.",
  },
  {
    step: 4,
    title: "Send & Track",
    description: "Send your campaign immediately or schedule it for later. Monitor delivery in real-time.",
  },
];

export default function Documentation() {
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
          <div className="flex items-center justify-center gap-3 mb-6">
            <BookOpen className="h-12 w-12 text-primary" />
          </div>
          <h1 className="text-4xl md:text-5xl font-bold text-foreground mb-4">
            Documentation
          </h1>
          <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
            Everything you need to know to get the most out of IEOSUIA SMS Portal.
          </p>
        </div>
      </section>

      {/* Quick Start */}
      <section className="py-16 px-4">
        <div className="container mx-auto max-w-6xl">
          <h2 className="text-3xl font-bold text-foreground mb-8 text-center">
            Quick Start Guide
          </h2>
          <div className="grid gap-6 md:grid-cols-4">
            {quickStart.map((item, index) => (
              <div key={item.step} className="relative text-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary text-primary-foreground text-xl font-bold mx-auto mb-4">
                  {item.step}
                </div>
                {index < quickStart.length - 1 && (
                  <div className="hidden md:block absolute top-7 left-[60%] w-[80%] h-0.5 bg-border" />
                )}
                <h3 className="text-lg font-semibold text-foreground mb-2">{item.title}</h3>
                <p className="text-sm text-muted-foreground">{item.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Video Tutorials */}
      <section className="py-12 px-4 bg-muted/30">
        <div className="container mx-auto max-w-4xl text-center">
          <div className="bg-card rounded-xl p-8 border border-border">
            <Video className="h-12 w-12 text-primary mx-auto mb-4" />
            <h2 className="text-2xl font-bold text-foreground mb-3">Video Tutorials</h2>
            <p className="text-muted-foreground mb-6">
              Watch step-by-step video guides on our YouTube channel to learn how to use all features.
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

      {/* Feature Guides */}
      <section className="py-16 px-4">
        <div className="container mx-auto max-w-6xl">
          <h2 className="text-3xl font-bold text-foreground mb-8 text-center">
            Feature Guides
          </h2>
          <div className="grid gap-6 md:grid-cols-2">
            {guides.map((guide) => (
              <div key={guide.title} className="bg-card rounded-xl p-6 border border-border hover:shadow-lg transition-shadow">
                <div className="flex items-start gap-4 mb-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0">
                    <guide.icon className="h-6 w-6" />
                  </div>
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">{guide.title}</h3>
                    <p className="text-sm text-muted-foreground">{guide.description}</p>
                  </div>
                </div>
                <ul className="space-y-2 pl-4">
                  {guide.topics.map((topic) => (
                    <li key={topic} className="text-sm text-muted-foreground flex items-center gap-2">
                      <div className="h-1.5 w-1.5 rounded-full bg-primary shrink-0" />
                      {topic}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Need Help */}
      <section className="py-12 px-4 bg-muted/30">
        <div className="container mx-auto max-w-4xl text-center">
          <h2 className="text-2xl font-bold text-foreground mb-4">Still Need Help?</h2>
          <p className="text-muted-foreground mb-6">
            Can't find what you're looking for? Our support team is ready to assist you.
          </p>
          <div className="flex flex-wrap justify-center gap-4">
            <Link to="/support">
              <Button variant="outline" className="gap-2">
                <Phone className="h-4 w-4" />
                Contact Support
              </Button>
            </Link>
            <a href="https://wa.me/27799282775" target="_blank" rel="noopener noreferrer">
              <Button className="gap-2">
                <Zap className="h-4 w-4" />
                WhatsApp Chat
              </Button>
            </a>
          </div>
        </div>
      </section>

      <Footer variant="simple" />
    </div>
  );
}
