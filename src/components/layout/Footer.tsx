import { Link } from "react-router-dom";
import { Facebook, Youtube, Mail, Phone, MapPin } from "lucide-react";
import ieosuiaLogo from "@/assets/ieosuia-logo.png";

interface FooterProps {
  variant?: "full" | "simple";
}

export function Footer({ variant = "full" }: FooterProps) {
  if (variant === "simple") {
    return (
      <footer className="bg-sidebar py-8 px-4">
        <div className="container mx-auto max-w-6xl text-center">
          <div className="flex items-center justify-center gap-3 mb-4">
            <img src={ieosuiaLogo} alt="IEOSUIA" className="h-8 w-auto" />
            <div className="flex flex-col items-start">
              <span className="text-lg font-bold text-sidebar-primary-foreground leading-tight">IEOSUIA</span>
              <span className="text-[8px] text-sidebar-muted font-medium tracking-wider uppercase">SMS PORTAL</span>
            </div>
          </div>
          <p className="text-sm text-sidebar-muted mb-4">
            Professional bulk SMS and email messaging platform for businesses.
          </p>
          <div className="flex flex-wrap justify-center gap-4 md:gap-6 text-sm text-sidebar-muted mb-4">
            <Link to="/terms-of-service" className="hover:text-sidebar-primary-foreground">Terms of Service</Link>
            <Link to="/privacy-policy" className="hover:text-sidebar-primary-foreground">Privacy Policy</Link>
            <Link to="/popia-compliance" className="hover:text-sidebar-primary-foreground">POPIA Compliance</Link>
            <Link to="/cookie-policy" className="hover:text-sidebar-primary-foreground">Cookie Policy</Link>
            <Link to="/support" className="hover:text-sidebar-primary-foreground">Support</Link>
            <Link to="/landing" className="hover:text-sidebar-primary-foreground">Home</Link>
          </div>
          {/* Social Links */}
          <div className="flex justify-center gap-4 mb-4">
            <a 
              href="https://www.facebook.com/iegroupSA" 
              target="_blank" 
              rel="noopener noreferrer"
              className="text-sidebar-muted hover:text-sidebar-primary-foreground transition-colors"
              aria-label="Facebook"
            >
              <Facebook className="h-5 w-5" />
            </a>
            <a 
              href="https://www.youtube.com/@ieosuia" 
              target="_blank" 
              rel="noopener noreferrer"
              className="text-sidebar-muted hover:text-sidebar-primary-foreground transition-colors"
              aria-label="YouTube"
            >
              <Youtube className="h-5 w-5" />
            </a>
          </div>
          <p className="text-sm text-sidebar-muted">
            © {new Date().getFullYear()} IEOSUIA. All rights reserved.
          </p>
        </div>
      </footer>
    );
  }

  return (
    <footer className="bg-sidebar py-12 px-4">
      <div className="container mx-auto max-w-6xl">
        <div className="grid gap-8 md:grid-cols-4">
          {/* Brand */}
          <div>
            <div className="flex items-center gap-3 mb-4">
              <img src={ieosuiaLogo} alt="IEOSUIA" className="h-10 w-auto" />
              <div className="flex flex-col">
                <span className="text-lg font-bold text-sidebar-primary-foreground leading-tight">IEOSUIA</span>
                <span className="text-[9px] text-sidebar-muted font-medium tracking-wider uppercase">SMS PORTAL</span>
              </div>
            </div>
            <p className="text-sm text-sidebar-muted mb-4">
              Professional bulk SMS and email messaging platform for South African businesses.
            </p>
            {/* Social Links */}
            <div className="flex gap-3">
              <a 
                href="https://www.facebook.com/iegroupSA" 
                target="_blank" 
                rel="noopener noreferrer"
                className="flex h-8 w-8 items-center justify-center rounded-lg bg-sidebar-accent text-sidebar-muted hover:text-sidebar-primary-foreground transition-colors"
                aria-label="Facebook"
              >
                <Facebook className="h-4 w-4" />
              </a>
              <a 
                href="https://www.youtube.com/@ieosuia" 
                target="_blank" 
                rel="noopener noreferrer"
                className="flex h-8 w-8 items-center justify-center rounded-lg bg-sidebar-accent text-sidebar-muted hover:text-sidebar-primary-foreground transition-colors"
                aria-label="YouTube"
              >
                <Youtube className="h-4 w-4" />
              </a>
            </div>
          </div>
          
          {/* Product */}
          <div>
            <h4 className="font-semibold text-sidebar-primary-foreground mb-4">Product</h4>
            <ul className="space-y-2 text-sm text-sidebar-muted">
              <li><a href="#features" className="hover:text-sidebar-primary-foreground">Features</a></li>
              <li><a href="#pricing" className="hover:text-sidebar-primary-foreground">Pricing</a></li>
              <li><Link to="/documentation" className="hover:text-sidebar-primary-foreground">Documentation</Link></li>
              <li><Link to="/login" className="hover:text-sidebar-primary-foreground">Login</Link></li>
              <li><Link to="/register" className="hover:text-sidebar-primary-foreground">Sign Up</Link></li>
            </ul>
          </div>
          
          {/* Company */}
          <div>
            <h4 className="font-semibold text-sidebar-primary-foreground mb-4">Company</h4>
            <ul className="space-y-2 text-sm text-sidebar-muted">
              <li><Link to="/support" className="hover:text-sidebar-primary-foreground">Help Center</Link></li>
              <li><a href="https://www.youtube.com/@ieosuia" target="_blank" rel="noopener noreferrer" className="hover:text-sidebar-primary-foreground">Tutorials</a></li>
              <li><Link to="/careers" className="hover:text-sidebar-primary-foreground">Careers</Link></li>
              <li><a href="#contact" className="hover:text-sidebar-primary-foreground">Contact Us</a></li>
            </ul>
          </div>
          
          {/* Legal */}
          <div>
            <h4 className="font-semibold text-sidebar-primary-foreground mb-4">Legal</h4>
            <ul className="space-y-2 text-sm text-sidebar-muted">
              <li><Link to="/privacy-policy" className="hover:text-sidebar-primary-foreground">Privacy Policy</Link></li>
              <li><Link to="/terms-of-service" className="hover:text-sidebar-primary-foreground">Terms of Service</Link></li>
              <li><Link to="/popia-compliance" className="hover:text-sidebar-primary-foreground">POPIA Compliance</Link></li>
              <li><Link to="/cookie-policy" className="hover:text-sidebar-primary-foreground">Cookie Policy</Link></li>
            </ul>
          </div>
        </div>
        
        {/* Contact Info */}
        <div className="mt-8 pt-8 border-t border-sidebar-border">
          <div className="grid gap-4 md:grid-cols-3 text-sm text-sidebar-muted mb-6">
            <div className="flex items-center gap-2">
              <Mail className="h-4 w-4 text-primary" />
              <span>hello@ieosuia.com</span>
            </div>
            <div className="flex items-center gap-2">
              <Phone className="h-4 w-4 text-primary" />
              <span>079 928 2775 / 063 154 0696</span>
            </div>
            <div className="flex items-center gap-2">
              <MapPin className="h-4 w-4 text-primary" />
              <span>26 Rock Alder, Ext 15, Naturena, JHB</span>
            </div>
          </div>
          <div className="text-center text-sm text-sidebar-muted">
            © {new Date().getFullYear()} IEOSUIA. All rights reserved.
          </div>
        </div>
      </div>
    </footer>
  );
}
