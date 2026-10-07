import { Link } from 'react-router-dom';
import { CheckCircle, Circle } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import { api } from '@/lib/api';
export interface PortalOverview {contacts:number;bought_credits:boolean;sent_campaign:boolean;checked_delivery:boolean}
export function usePortalOverview(){
  const {user}=useAuth();
  return useQuery({queryKey:['portal-overview',user?.id],enabled:!!user,queryFn:async()=>{const r=await api.get<PortalOverview>('/portal/overview');if(!r.success)throw new Error('Overview unavailable');return (r.data??r) as PortalOverview;},refetchInterval:30000});
}
export function GetStartedChecklist({dashboard=false}:{dashboard?:boolean}){
  const {data}=usePortalOverview();
  if(!data||(dashboard&&data.sent_campaign))return null;
  return <section aria-label="Get started checklist" className="mb-4 rounded-xl border bg-card p-4"><h2 className="mb-3 font-semibold">Get Started</h2><ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{[
    ['Add contacts','/contacts',data.contacts>0],['Buy credits','/wallet',data.bought_credits],['Send a test SMS','/sms-campaigns/new',data.sent_campaign],['Check delivery','/reports',data.checked_delivery],
  ].map(([label,to,done])=><li key={String(label)}><Link className="flex items-center gap-2 text-sm hover:underline" to={String(to)}>{done?<CheckCircle aria-label="Completed" className="h-4 w-4 shrink-0 text-success"/>:<Circle aria-label="Not completed" className="h-4 w-4 shrink-0 text-muted-foreground"/>}{label}</Link></li>)}</ul></section>;
}
