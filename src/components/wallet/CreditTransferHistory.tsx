import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { Button } from '@/components/ui/button';
import { creditTransfersApi } from '@/lib/credit-transfers';

export function CreditTransferHistory(){
  const {user}=useAuth();const [page,setPage]=useState(1);
  const {data,error,isPending,refetch}=useQuery({queryKey:['credit-transfer-history',user?.id,page],enabled:!!user,retry:false,queryFn:()=>creditTransfersApi.customerHistory(page)});
  if(!isPending&&!error&&!data?.transfers.length)return null;
  return <section aria-label="Credit transfer history" className="mt-6 space-y-3"><h2 className="text-lg font-semibold">Credit transfers</h2>{isPending&&<p role="status" className="text-sm">Loading credit transfers…</p>}{error&&<p role="alert" className="text-sm">Credit transfers could not be loaded. <Button variant="link" onClick={()=>void refetch()}>Retry</Button></p>}{data?.transfers.map(t=><article key={t.id} className="flex min-w-0 items-start gap-3 rounded-xl border bg-card p-4">{t.direction==='received'?<ArrowDownLeft className="h-5 w-5 shrink-0 text-emerald-600"/>:<ArrowUpRight className="h-5 w-5 shrink-0 text-primary"/>}<div className="min-w-0"><strong>{t.direction==='received'?'+':'−'}{t.credits.toLocaleString()} credits {t.direction}</strong><p className="mt-1 break-all text-xs text-muted-foreground">{t.reference}</p><p className="mt-1 text-xs">{t.created_at} · Completed</p></div></article>)}{data&&data.last_page>1&&<div className="flex items-center justify-between"><Button variant="outline" disabled={page===1} onClick={()=>setPage(p=>p-1)}>Previous</Button><span className="text-sm">Page {page}</span><Button variant="outline" disabled={page>=data.last_page} onClick={()=>setPage(p=>p+1)}>Next</Button></div>}</section>;
}
