import { Link } from "react-router-dom";
import smsPortalLogo from "@/assets/ieosuia-sms-portal-logo.png";
import smsPortalLogoWhite from "@/assets/ieosuia-sms-portal-logo-white.png";

interface LogoProps {
  size?: "sm" | "md" | "lg";
  linkTo?: string;
  className?: string;
  variant?: "light" | "dark";
}

export function Logo({ size = "md", linkTo = "/landing", className = "", variant = "light" }: LogoProps) {
  const sizes = {
    sm: "h-8",
    md: "h-10",
    lg: "h-12",
  };

  const logoSrc = variant === "dark" ? smsPortalLogoWhite : smsPortalLogo;

  const content = (
    <div className={`flex items-center ${className}`}>
      <img 
        src={logoSrc} 
        alt="IEOSUIA SMS Portal" 
        className={`${sizes[size]} w-auto object-contain`}
      />
    </div>
  );

  if (linkTo) {
    return <Link to={linkTo}>{content}</Link>;
  }

  return content;
}

export function LogoSidebar({ size = "md", className = "" }: Omit<LogoProps, 'linkTo' | 'variant'>) {
  const sizes = {
    sm: "h-8",
    md: "h-10",
    lg: "h-12",
  };

  return (
    <div className={`flex items-center ${className}`}>
      <img 
        src={smsPortalLogoWhite} 
        alt="IEOSUIA SMS Portal" 
        className={`${sizes[size]} w-auto object-contain`}
      />
    </div>
  );
}
