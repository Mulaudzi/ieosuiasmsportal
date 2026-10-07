import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { Button } from '@/components/ui/button';
import { MetricCard } from '@/components/dashboard/MetricCard';
import { BuyCreditsModal } from '@/components/wallet/BuyCreditsModal';
import { WalletActivity } from '@/components/wallet/WalletActivity';
import { useWalletStats } from '@/hooks/useWallet';
import { Wallet as WalletIcon, CreditCard, TrendingUp, RefreshCw, ShoppingCart } from 'lucide-react';

export default function Wallet(){
 const [buy,setBuy]=useState(false);const [paid,setPaid]=useState<number|null>(null);const client=useQueryClient();
 const {smsCredits,pricePerCredit,usedThisMonth,isLoading,error,refetch}=useWalletStats();
 const refresh=()=>{void refetch();void client.invalidateQueries({queryKey:['wallet']});void client.invalidateQueries({queryKey:['wallet-activity']});};
 return <DashboardLayout title="SMS Credits" subtitle="Buy SMS credits and view your transaction history" actions={<><Button aria-label="Refresh credits" variant="outline" size="icon" onClick={refresh}><RefreshCw className="h-4 w-4"/></Button><Button onClick={()=>setBuy(true)} className="gap-2"><ShoppingCart className="h-4 w-4"/>Buy Credits</Button></>}>
  <BuyCreditsModal open={buy} onOpenChange={open=>{setBuy(open);if(!open)refresh();}} onPurchaseComplete={refresh}/>
  {error?<p role="alert">Could not load your credit balance. Please refresh.</p>:isLoading?<p role="status">Loading credits…</p>:<div className="grid gap-4 md:grid-cols-3">
   <MetricCard prominent title="Available SMS credits" value={smsCredits.toLocaleString()} change={`About ${smsCredits.toLocaleString()} one-segment SMSs · R${pricePerCredit.toFixed(2)} per credit`} icon={WalletIcon} iconColor="primary" footer={smsCredits<50?<div className="flex flex-wrap items-center gap-3"><span className="text-sm text-muted-foreground">Low balance</span><Button onClick={()=>setBuy(true)}>Buy credits</Button></div>:undefined}/>
   <MetricCard title="Used this month" value={usedThisMonth.toLocaleString()} change="Billable segments sent this month" icon={TrendingUp} iconColor="accent"/>
   <MetricCard title="Payments" value={paid===null?'—':`R${paid.toFixed(2)}`} change={paid===0?'No payment recorded yet':'Amount paid'} icon={CreditCard} iconColor="info"/>
  </div>}
  <section id="buy-credits" className="mt-6"><h2 className="mb-3 text-lg font-semibold">Buy SMS credits</h2><div className="rounded-xl border bg-card p-5"><p className="text-sm text-muted-foreground">Each credit costs R{pricePerCredit.toFixed(2)}, and 1 credit sends 1 segment.</p><Button className="mt-4" onClick={()=>setBuy(true)}>Buy SMS credits</Button></div></section>
  <WalletActivity onTotalPaid={setPaid}/>
 </DashboardLayout>;
}
