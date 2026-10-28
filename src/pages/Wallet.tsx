import { CashBalanceCard } from '@/components/wallet/CashBalanceCard';
import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { getWorkDraft } from '@/lib/work-drafts';
import { DraftList } from '@/components/drafts/DraftList';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { PageLoading } from '@/components/ui/page-loading';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/dashboard/MetricCard';
import { BuyCreditsModal } from '@/components/wallet/BuyCreditsModal';
import { WalletActivity } from '@/components/wallet/WalletActivity';
import { BankTransferOrders } from '@/components/wallet/BankTransferOrders';
import { CreditPurchasePolicy } from '@/components/pricing/CreditPurchasePolicy';
import { useWalletStats } from '@/hooks/useWallet';
import { Wallet as WalletIcon, CreditCard, TrendingUp, RefreshCw, ShoppingCart } from 'lucide-react';

export default function Wallet(){
 const [buy,setBuy]=useState(false);const [paid,setPaid]=useState<number|null>(null);const client=useQueryClient();
 const {user}=useAuth();const [params,setParams]=useSearchParams();const draftId=params.get('draft');
 const draft=useQuery({queryKey:['work-draft',user?.id,draftId],enabled:!!user&&!!draftId,retry:false,refetchOnWindowFocus:false,queryFn:()=>getWorkDraft(draftId!,'purchase')});
 useEffect(()=>{if(draftId)setBuy(true);},[draftId]);
 const clearDraftLink=()=>setParams(previous=>{const next=new URLSearchParams(previous);next.delete('draft');return next;},{replace:true});
 const {smsCredits,pricePerCredit,usedThisMonth,isLoading,error,refetch}=useWalletStats();
 const refresh=()=>{void refetch();void client.invalidateQueries({queryKey:['customer-cash-balance']});void client.invalidateQueries({queryKey:['purchase-options']});void client.invalidateQueries({queryKey:['wallet']});void client.invalidateQueries({queryKey:['wallet-activity']});};
 return <DashboardLayout title="SMS Credits" subtitle="Buy SMS credits and view your transaction history" actions={<><Button aria-label="Refresh credits" variant="outline" size="icon" onClick={refresh}><RefreshCw className="h-4 w-4"/></Button><Button onClick={()=>setBuy(true)} className="gap-2"><ShoppingCart className="h-4 w-4"/>Buy Credits</Button></>}>
  {draftId&&!draft.data&&<p role={draft.isError?'alert':'status'}>{draft.isError?'This purchase draft could not be opened. It may have been deleted or belongs to another account.':'Loading your purchase draft...'} <Button variant="link" onClick={()=>{clearDraftLink();setBuy(false);}}>Back to Wallet</Button>{draft.isError&&<Button variant="link" onClick={()=>void draft.refetch()}>Retry</Button>}</p>}
  <BuyCreditsModal open={buy&&(!draftId||!!draft.data)} draft={draftId?draft.data:undefined} onStartNew={clearDraftLink} onOpenChange={open=>{setBuy(open);if(!open){clearDraftLink();refresh();}}} onPurchaseComplete={refresh}/>
  {error?<p role="alert">Could not load your credit balance. Please refresh.</p>:isLoading?<PageLoading label="Loading SMS credits"/>:<div className="grid gap-4 md:grid-cols-3">
   <MetricCard prominent title="Available SMS credits" value={smsCredits.toLocaleString()} change="One credit sends one billable SMS segment" icon={WalletIcon} iconColor="primary" footer={smsCredits<50?<div className="flex flex-wrap items-center gap-3"><span className="text-sm text-muted-foreground">Low balance</span><Button onClick={()=>setBuy(true)}>Buy credits</Button></div>:undefined}/>
   <MetricCard title="Used this month" value={usedThisMonth.toLocaleString()} change="Billable segments sent this month" icon={TrendingUp} iconColor="accent"/>
   <MetricCard title="Payments" value={paid===null?'—':`R${paid.toFixed(2)}`} change={paid===0?'No payment recorded yet':'Amount paid'} icon={CreditCard} iconColor="info"/>
  </div>}
  <section id="buy-credits" className="mt-6"><h2 className="mb-3 text-lg font-semibold">Buy SMS credits</h2><div className="rounded-xl border bg-card p-5"><p className="text-sm text-muted-foreground">Buy from 30 credits for R10.50. Volume pricing ranges from R0.35 to R0.22 per credit. Review your exact price and any bank transfer saving before paying. No VAT charged.</p><Button className="mt-4" onClick={()=>setBuy(true)}>Buy SMS credits</Button></div></section>
  <CashBalanceCard/>
  <CreditPurchasePolicy/>
  <BankTransferOrders/>
  <DraftList kind="purchase"/>
  <WalletActivity onTotalPaid={setPaid}/>
 </DashboardLayout>;
}
