import { Link } from "react-router-dom";
import standard from "@/assets/ieosuia-sms-portal-standard.png";
import dark from "@/assets/ieosuia-sms-portal-dark.png";
import navy from "@/assets/ieosuia-sms-portal-navy.png";
import light from "@/assets/ieosuia-sms-portal-light.png";
import "./Logo.css";

export type LogoVariant = "standard" | "dark" | "navy" | "light";
export const logoAssets = { standard, dark, navy, light };
interface LogoProps {
  size?: "sm" | "md" | "lg";
  linkTo?: string;
  className?: string;
  /** Follows the portal's light surface / dark navy surface tokens. */
  variant?: LogoVariant | "theme";
}
export function Logo({ size = "md", linkTo = "/", className = "", variant = "standard" }: LogoProps) {
  const content = <span className={`portal-logo portal-logo--${size} ${className}`}>
    {variant === "theme" ? <>
      <img src={light} width={2172} height={724} alt="IEOSUIA SMS Portal" className="portal-logo__light" />
      <img src={navy} width={2172} height={724} alt="IEOSUIA SMS Portal" className="portal-logo__navy" />
    </> : <img src={logoAssets[variant]} width={2172} height={724} alt="IEOSUIA SMS Portal" />}
  </span>;
  return linkTo ? <Link className="portal-logo-link" to={linkTo === "/landing" ? "/" : linkTo}>{content}</Link> : content;
}
/** Customer and administrator sidebars both have fixed navy backgrounds. */
export function LogoSidebar(props: Omit<LogoProps, "linkTo" | "variant">) {
  return <Logo {...props} variant="navy" linkTo="" />;
}
