import { Lightbulb, CircleHelp } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { ReactNode } from 'react';
export function PortalHint({children,visible,onChange}:{children:ReactNode;visible:boolean;onChange:(visible:boolean)=>void}) {
  if(!visible)return <Button type="button" variant="ghost" size="sm" onClick={()=>onChange(true)}><CircleHelp className="h-4 w-4"/>Show tips</Button>;
  return <aside aria-label="Helpful tips" className="flex flex-wrap items-start gap-2 rounded-xl border border-primary/20 bg-primary/5 p-3 text-sm"><Lightbulb className="mt-0.5 h-4 w-4 shrink-0 text-primary"/><div className="min-w-0 flex-1">{children}</div><Button type="button" variant="ghost" size="sm" className="h-8 shrink-0 text-xs" title="Hide these tips on this browser for your account" onClick={()=>onChange(false)}>Hide tips</Button></aside>;
}
