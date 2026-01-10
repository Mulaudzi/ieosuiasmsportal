import { Link } from "react-router-dom";
import { Zap } from "lucide-react";

interface FooterProps {
  variant?: "full" | "simple";
}

export function Footer({ variant = "full" }: FooterProps) {
  if (variant === "simple") {
    return (
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
          <div className="flex flex-wrap justify-center gap-4 md:gap-6 text-sm text-sidebar-muted">
            <Link to="/terms-of-service" className="hover:text-sidebar-primary-foreground">Terms of Service</Link>
            <Link to="/privacy-policy" className="hover:text-sidebar-primary-foreground">Privacy Policy</Link>
            <Link to="/support" className="hover:text-sidebar-primary-foreground">Support</Link>
            <Link to="/landing" className="hover:text-sidebar-primary-foreground">Home</Link>
          </div>
          <p className="mt-6 text-sm text-sidebar-muted">
            © {new Date().getFullYear()} IEOSUIA SMS PORTAL. All rights reserved.
          </p>
        </div>
      </footer>
    );
  }

  return (
    <footer className="bg-sidebar py-12 px-4">
      <div className="container mx-auto max-w-6xl">
        <div className="grid gap-8 md:grid-cols-4">
          <div>
            <div className="flex items-center gap-2 mb-4">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary">
                <Zap className="h-5 w-5 text-primary-foreground" />
              </div>
              <span className="text-lg font-bold text-sidebar-primary-foreground">IEOSUIA SMS PORTAL</span>
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
              <li><Link to="/support" className="hover:text-sidebar-primary-foreground">Help Center</Link></li>
              <li><a href="#" className="hover:text-sidebar-primary-foreground">API Documentation</a></li>
              <li><a href="#" className="hover:text-sidebar-primary-foreground">Status</a></li>
            </ul>
          </div>
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
        <div className="mt-8 pt-8 border-t border-sidebar-border text-center text-sm text-sidebar-muted">
          © {new Date().getFullYear()} IEOSUIA SMS PORTAL. All rights reserved.
        </div>
      </div>
    </footer>
  );
}
