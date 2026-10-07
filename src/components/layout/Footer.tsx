import { Link } from "react-router-dom";
import { SocialLinks } from "./SocialLinks";
import { Logo } from "./Logo";

interface FooterProps {
  variant?: "full" | "simple";
}

export function Footer({ variant = "full" }: FooterProps) {
  if (variant === "simple") {
    return (
      <footer className="bg-sidebar py-8 px-4">
        <div className="container mx-auto max-w-6xl text-center">
          <div className="flex items-center justify-center gap-3 mb-4">
            <Logo variant="navy" size="sm" linkTo="" />
          </div>
          <p className="text-sm text-sidebar-muted mb-4">
            Business SMS campaigns, contacts and delivery reports. Email Campaigns: Coming Soon.
          </p>
          <div className="flex flex-wrap justify-center gap-4 md:gap-6 text-sm text-sidebar-muted mb-4">
            <Link to="/terms-of-service" className="hover:text-sidebar-primary-foreground">Terms of Service</Link>
            <Link to="/privacy-policy" className="hover:text-sidebar-primary-foreground">Privacy Policy</Link>
            <Link to="/popia-compliance" className="hover:text-sidebar-primary-foreground">POPIA Compliance</Link>
            <Link to="/cookie-policy" className="hover:text-sidebar-primary-foreground">Cookie Policy</Link>
            <Link to="/support" className="hover:text-sidebar-primary-foreground">Support</Link>
            <Link to="/contact" className="hover:text-sidebar-primary-foreground">Contact</Link>
            <Link to="/features" className="hover:text-sidebar-primary-foreground">SMS Features</Link>
            <Link to="/use-cases" className="hover:text-sidebar-primary-foreground">Use Cases</Link>
            <Link to="/faq" className="hover:text-sidebar-primary-foreground">SMS Questions</Link>
            <Link to="/" className="hover:text-sidebar-primary-foreground">Home</Link>
          </div>
          {/* Social Links */}
          <SocialLinks className="justify-center mb-4" linkClassName="text-sidebar-muted hover:text-sidebar-primary-foreground" />
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
              <Logo variant="navy" linkTo="" />
            </div>
            <p className="text-sm text-sidebar-muted mb-4">
              Business SMS campaigns, contacts and delivery reports for South Africa. Email Campaigns: Coming Soon.
            </p>
            {/* Social Links */}
            <SocialLinks linkClassName="bg-sidebar-accent text-sidebar-muted hover:text-sidebar-primary-foreground" />
          </div>
          
          {/* Product */}
          <div>
          <h4 className="font-semibold text-sidebar-primary-foreground mb-4">Product</h4>
            <ul className="space-y-2 text-sm text-sidebar-muted">
              <li><Link to="/features" className="hover:text-sidebar-primary-foreground">Features</Link></li>
              <li><Link to="/pricing" className="hover:text-sidebar-primary-foreground">Pricing</Link></li>
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
              <li><Link to="/documentation" className="hover:text-sidebar-primary-foreground">Guides</Link></li>
              <li><Link to="/careers" className="hover:text-sidebar-primary-foreground">Careers</Link></li>
              <li><Link to="/contact" className="hover:text-sidebar-primary-foreground">Contact Us</Link></li>
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
        
        {/* Bottom section */}
        <div className="mt-8 pt-8 border-t border-sidebar-border">
          <div className="text-center text-sm text-sidebar-muted">
            © {new Date().getFullYear()} IEOSUIA. All rights reserved.
          </div>
        </div>
      </div>
    </footer>
  );
}
