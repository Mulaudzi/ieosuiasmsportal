import { Link } from "react-router-dom";
import ieosuiaLogo from "@/assets/ieosuia-logo.png";

interface LogoProps {
  size?: "sm" | "md" | "lg";
  showSubtitle?: boolean;
  linkTo?: string;
  className?: string;
}

export function Logo({ size = "md", showSubtitle = true, linkTo = "/landing", className = "" }: LogoProps) {
  const sizes = {
    sm: { logo: "h-8", text: "text-base", subtitle: "text-[8px]" },
    md: { logo: "h-10", text: "text-xl", subtitle: "text-[10px]" },
    lg: { logo: "h-12", text: "text-2xl", subtitle: "text-xs" },
  };

  const content = (
    <div className={`flex items-center gap-3 ${className}`}>
      <img 
        src={ieosuiaLogo} 
        alt="IEOSUIA" 
        className={`${sizes[size].logo} w-auto object-contain`}
      />
      <div className="flex flex-col">
        <span className={`${sizes[size].text} font-bold text-foreground leading-tight`}>
          IEOSUIA
        </span>
        {showSubtitle && (
          <span className={`${sizes[size].subtitle} text-muted-foreground font-medium tracking-wider uppercase`}>
            SMS PORTAL
          </span>
        )}
      </div>
    </div>
  );

  if (linkTo) {
    return <Link to={linkTo}>{content}</Link>;
  }

  return content;
}

export function LogoSidebar({ size = "md", showSubtitle = true, className = "" }: Omit<LogoProps, 'linkTo'>) {
  const sizes = {
    sm: { logo: "h-8", text: "text-base", subtitle: "text-[8px]" },
    md: { logo: "h-10", text: "text-xl", subtitle: "text-[10px]" },
    lg: { logo: "h-12", text: "text-2xl", subtitle: "text-xs" },
  };

  return (
    <div className={`flex items-center gap-3 ${className}`}>
      <img 
        src={ieosuiaLogo} 
        alt="IEOSUIA" 
        className={`${sizes[size].logo} w-auto object-contain`}
      />
      <div className="flex flex-col">
        <span className={`${sizes[size].text} font-bold text-sidebar-primary-foreground leading-tight`}>
          IEOSUIA
        </span>
        {showSubtitle && (
          <span className={`${sizes[size].subtitle} text-sidebar-muted font-medium tracking-wider uppercase`}>
            SMS PORTAL
          </span>
        )}
      </div>
    </div>
  );
}
