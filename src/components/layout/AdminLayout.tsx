import { ReactNode, useState } from "react";
import { AdminSidebar } from "./AdminSidebar";
import { NotificationBell } from "./NotificationBell";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import { Menu } from "lucide-react";

interface AdminLayoutProps {
  children: ReactNode;
  title: string;
  subtitle?: string;
  actions?: ReactNode;
  className?: string;
}

export function AdminLayout({ 
  children, 
  title, 
  subtitle,
  actions,
  className 
}: AdminLayoutProps) {
  const [menuOpen,setMenuOpen]=useState(false);
  return (
    <div className="min-h-screen bg-background">
      <AdminSidebar />
      
      {/* Main Content */}
      <div className="lg:ml-64">
        {/* Header */}
        <header className="sticky top-0 z-30 border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
          <div className="flex min-h-16 items-center justify-between gap-2 px-3 py-2 sm:px-4 lg:px-6">
            <div className="flex min-w-0 items-center gap-2 sm:gap-3">
              <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
                <SheetTrigger asChild><Button variant="ghost" size="icon" className="shrink-0 lg:hidden" aria-label="Open operations menu"><Menu className="h-5 w-5"/></Button></SheetTrigger>
                <SheetContent side="left" className="w-[86vw] max-w-72 border-0 bg-sidebar p-0"><AdminSidebar mobile onNavigate={()=>setMenuOpen(false)}/></SheetContent>
              </Sheet>
              <div className="min-w-0">
              <h1 className="truncate text-base font-semibold text-foreground sm:text-xl">{title}</h1>
              {subtitle && (
                <p className="hidden truncate text-sm text-muted-foreground sm:block">{subtitle}</p>
              )}
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-1 sm:gap-3">
              {actions}
              <NotificationBell />
            </div>
          </div>
        </header>

        {/* Page Content */}
        <main className={cn("p-3 pb-20 sm:p-4 lg:p-6", className)}>
          {children}
        </main>
      </div>
    </div>
  );
}
