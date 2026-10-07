import { Facebook, Instagram } from "lucide-react";
import links from "./official-social-links.json";

function TikTokIcon() {
  return <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M16.6 3c.4 2.3 1.7 3.7 4.1 3.9v3.4a9.1 9.1 0 0 1-4.1-1.2v7a6.1 6.1 0 1 1-5.3-6v3.5a2.7 2.7 0 1 0 1.8 2.5V3h3.5Z"/></svg>;
}

export function SocialLinks({ className = "", linkClassName = "" }: { className?: string; linkClassName?: string }) {
  return <div className={`flex items-center gap-3 ${className}`}>
    {links.map(link => <a key={link.platform} href={link.url} target="_blank" rel="noopener noreferrer"
      aria-label={`IEOSUIA on ${link.name}`}
      className={`inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${linkClassName}`}>
      {link.platform === "instagram" ? <Instagram size={20} aria-hidden="true"/> : link.platform === "facebook" ? <Facebook size={20} aria-hidden="true"/> : <TikTokIcon/>}
    </a>)}
  </div>;
}
