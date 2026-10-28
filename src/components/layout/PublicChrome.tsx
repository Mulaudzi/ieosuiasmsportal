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
  const { isAuthenticated, isLoading } = useAuth();
  const location = useLocation();
  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [location.pathname, location.hash]);
  useEffect(() => {
    const close = (event: KeyboardEvent) => { if (event.key === "Escape") setOpen(false); };
    window.addEventListener("keydown", close);
    return () => window.removeEventListener("keydown", close);
  }, []);
  // Keep the prerendered guest header in place while local auth state initialises.
  // Private routes still wait for authentication; signed-in users keep account chrome.
  const isPublicPage = publicPages.some(page => page.path === (location.pathname.replace(/\/$/, "") || "/"));
  if (isAuthenticated || (isLoading && !isPublicPage)) return null;
  return <>
    <div className="sms-home public-site-header">
      <header className="home-header">
        <div className="home-wrap home-nav">
          <Logo variant="navy" className="home-logo" />
          <nav aria-label="Main navigation" className="home-desktop-nav"><Link to="/features">Features</Link><Link to="/use-cases">Use Cases</Link><Link to="/pricing">Pricing</Link><Link to="/documentation">Resources</Link><Link to="/contact">Contact</Link></nav>
          <div className="home-nav-actions"><Link className="home-login" to="/login">Sign In</Link><Link className="home-button home-nav-cta" to="/register">Get Started</Link><button type="button" className="home-menu-button" aria-label={open ? "Close navigation" : "Open navigation"} aria-expanded={open} aria-controls="public-mobile-nav" onClick={() => setOpen(!open)}>{open ? <X/> : <Menu/>}</button></div>
        </div>
        {open && <nav id="public-mobile-nav" className="home-mobile-nav" aria-label="Mobile navigation" onClick={() => setOpen(false)}><a href="/#platform">Platform</a><Link to="/features">Features</Link><Link to="/use-cases">Use Cases</Link><Link to="/pricing">Pricing</Link><Link to="/documentation">Resources</Link><Link to="/faq">FAQ</Link><Link to="/banking-details">Banking Details</Link><Link to="/contact">Contact</Link><Link to="/register">Get Started</Link></nav>}
      </header>
    </div>
  </>;
}

/** Public information pages remain reachable from an authenticated session. */
export function SignedInPublicHeader() {
  const { isAuthenticated, user } = useAuth();
  if (!isAuthenticated) return null;
  return <nav className="flex items-center justify-between gap-4 border-b bg-background px-4 py-3"><Logo variant="theme" size="sm"/><Link to={user?.role === "admin" ? "/guymhan" : "/dashboard"} className="text-sm font-semibold">Back to dashboard</Link></nav>;
}
