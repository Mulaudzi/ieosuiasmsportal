import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Menu, X } from "lucide-react";
import { useAuth } from "@/hooks/useAuth";
import { Logo } from "./Logo";
import publicPages from "@/seo/public-pages.json";
import "@/pages/Landing.css";
import "./PublicChrome.css";
import "@/components/marketing/CampaignHero.css";

export function PublicChrome() {
  const { isAuthenticated, isLoading, user } = useAuth();
  const location = useLocation();
  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [location.pathname, location.hash]);
  useEffect(() => {
    const close = (event: KeyboardEvent) => { if (event.key === "Escape") setOpen(false); };
    window.addEventListener("keydown", close);
    return () => window.removeEventListener("keydown", close);
  }, []);
  // Public pages share one header regardless of sign-in state. Private routes
  // retain their dashboard chrome; this component never changes access control.
  const isPublicPage = publicPages.some(page => page.path === (location.pathname.replace(/\/$/, "") || "/"));
  if ((isAuthenticated && !isPublicPage) || (isLoading && !isPublicPage)) return null;
  const dashboard = user?.role === "admin" ? "/guymhan" : "/dashboard";
  const accountLink = isLoading
    ? <span className="home-auth-loading" role="status" aria-label="Checking sign-in"><span aria-hidden="true"/></span>
    : isAuthenticated
      ? <Link className="home-button home-dashboard-cta" to={dashboard}>Go To Dashboard</Link>
      : <><Link className="home-login" to="/login">Sign In</Link><Link className="home-button home-nav-cta" to="/register">Get Started</Link></>;
  return <>
    <div className="sms-home public-site-header">
      <header className="home-header">
        <div className={`home-wrap home-nav${isAuthenticated ? " home-nav--signed-in" : ""}`}>
          <Logo variant="navy" className="home-logo" />
          <nav aria-label="Main navigation" className="home-desktop-nav"><Link to="/features">Features</Link><Link to="/use-cases">Use Cases</Link><Link to="/pricing">Pricing</Link><Link to="/documentation">Resources</Link><Link to="/developers">SMS API</Link><Link to="/contact">Contact</Link></nav>
          <div className="home-nav-actions">{accountLink}<button type="button" className="home-menu-button" aria-label={open ? "Close navigation" : "Open navigation"} aria-expanded={open} aria-controls="public-mobile-nav" onClick={() => setOpen(!open)}>{open ? <X/> : <Menu/>}</button></div>
        </div>
        {open && <nav id="public-mobile-nav" className="home-mobile-nav" aria-label="Mobile navigation" onClick={() => setOpen(false)}><a href="/#platform">Platform</a><Link to="/features">Features</Link><Link to="/use-cases">Use Cases</Link><Link to="/pricing">Pricing</Link><Link to="/documentation">Resources</Link><Link to="/developers">SMS API</Link><Link to="/faq">FAQ</Link><Link to="/banking-details">Banking Details</Link><Link to="/contact">Contact</Link>{!isLoading && (isAuthenticated ? <Link to={dashboard}>Go To Dashboard</Link> : <Link to="/register">Get Started</Link>)}</nav>}
      </header>
    </div>
  </>;
}

/** Compatibility marker: PublicChrome now owns the full shared header. */
export function SignedInPublicHeader() {
  return null;
}
