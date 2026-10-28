import { useState, useEffect, useRef, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { MessageSquare, Users, CheckCircle, Plus, ArrowRight, Activity, FileText, BarChart3 } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { MetricCard } from '@/components/dashboard/MetricCard';
import { CampaignChart } from '@/components/dashboard/CampaignChart';
import { LatestCampaign, RecentCampaignTable, SummaryCampaign } from '@/components/dashboard/CampaignSummary';
import { DeliveryStats } from '@/components/dashboard/DeliveryStats';
import { DateRangeFilter } from '@/components/dashboard/DateRangeFilter';
import { Button } from '@/components/ui/button';
import { MetricCardSkeleton, ChartSkeleton } from '@/components/ui/loading-skeleton';
import { api, getDashboardStats } from '@/lib/api';
import { DashboardTour } from '@/components/dashboard/DashboardTour';
import { GetStartedChecklist } from '@/components/dashboard/GetStartedChecklist';
import { DashboardDeliverySummary } from '@/lib/delivery-visibility';
import { defaultDashboardPeriod, periodLabel, ReportingPeriod } from '@/lib/reporting-period';
import { useSmsRefresh } from '@/hooks/useSmsRefresh';
import { FirstContactGroupPrompt } from '@/components/contacts/ContactGroupSetup';

type Stats = DashboardDeliverySummary & {total_contacts:number;active_campaigns:number};
const quickActions=[
 {to:'/contacts/import',title:'Import Contacts',description:'Upload CSV or Excel file',icon:Users,color:'text-primary bg-primary/10'},
 {to:'/templates',title:'Manage Templates',description:'Build reusable messages',icon:FileText,color:'text-violet-600 bg-violet-500/10'},
 {to:'/reports',title:'View Reports',description:'Analytics and exports',icon:BarChart3,color:'text-success bg-success/10'},
 {to:'/sms-campaigns/new',title:'Send SMS',description:'Send SMS to your contacts',icon:MessageSquare,color:'text-orange-600 bg-orange-500/10'},
];
export default function Dashboard() {
 const [groupPromptReady,setGroupPromptReady]=useState(false);
 const allowGroupPrompt=useCallback(()=>setGroupPromptReady(true),[]);
 const [period,setPeriod]=useState<ReportingPeriod>(defaultDashboardPeriod);
 const [stats,setStats]=useState<Stats|null>(null);const [campaigns,setCampaigns]=useState<SummaryCampaign[]>([]);
 const [loading,setLoading]=useState(true);const [error,setError]=useState('');const sequence=useRef(0);
 const load=useCallback(async(showLoading=true)=>{
  const request=++sequence.current;
  if(showLoading){setLoading(true);setStats(null);setCampaigns([]);}setError('');
  try{
   const [s,c]=await Promise.all([getDashboardStats(period.range,period),api.get<{campaigns:SummaryCampaign[]}>('/dashboard/recent-campaigns',{...period})]);
   if(request!==sequence.current)return;
   if(!s.success||!c.success)throw new Error('Dashboard unavailable');
   setStats((s.data??s) as Stats);setCampaigns(((c.data??c) as {campaigns:SummaryCampaign[]}).campaigns??[]);
  }catch{if(request===sequence.current)setError('Could not refresh dashboard data. Please try again.');}
  finally{if(request===sequence.current)setLoading(false);}
 },[period]);
 const invalidate=useCallback(()=>{sequence.current++;},[]);
 useEffect(()=>{void load();return invalidate;},[load,invalidate]);
 useSmsRefresh(()=>load(false));
 const value=(field:'total_sent'|'total_contacts'|'active_campaigns')=>stats?Number(stats[field]??0).toLocaleString():'—';
 return <><DashboardTour onSettled={allowGroupPrompt}/><DashboardLayout title="Welcome back!" subtitle="Here's an overview of your messaging performance." actions={<><DateRangeFilter value={period} onChange={setPeriod}/><Button asChild className="gap-2"><Link to="/sms-campaigns/new" data-tour="campaign"><Plus size={16}/>Send SMS</Link></Button></>}>
  <GetStartedChecklist dashboard/>
  {groupPromptReady&&<FirstContactGroupPrompt/>}
  {error&&<div role="alert" className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm"><p>{error}</p><Button variant="outline" size="sm" onClick={()=>void load()}>Retry dashboard</Button></div>}
  <p className="mb-3 text-xs text-muted-foreground">{periodLabel(period)} · Message activity by creation date. Contacts and active campaigns reflect your account now.</p>
  <div id="metrics-section" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
   {loading?Array.from({length:4},(_,i)=><MetricCardSkeleton key={i}/>):<>
    <MetricCard title="Total Sent" value={value('total_sent')} icon={MessageSquare} footer={<p className="text-xs text-muted-foreground">{stats?`${Number(stats.total_delivered).toLocaleString()} delivered`:'Data unavailable'}</p>}/>
    <MetricCard title="Total Contacts" value={value('total_contacts')} icon={Users} iconColor="info" footer={<p className="text-xs text-muted-foreground">Current saved contacts</p>}/>
    <MetricCard title="Delivery Rate" value={stats&&Number(stats.total_sent)>0?`${Number(stats.delivery_rate).toFixed(1)}%`:'—'} icon={CheckCircle} iconColor="success" footer={<p className="text-xs text-muted-foreground">Confirmed deliveries / successfully sent</p>}/>
    <MetricCard title="Active Campaigns" value={value('active_campaigns')} icon={Activity} footer={<p className="text-xs text-muted-foreground">Currently scheduled or sending</p>}/>
   </>}
  </div>
  <div className="portal-analytics-grid mt-4"><div className="min-w-0">{loading?<ChartSkeleton/>:<LatestCampaign campaign={campaigns[0]}/>}</div><div data-tour="delivery" className="min-w-0"><DeliveryStats stats={stats} loading={loading}/></div></div>
  <div id="chart-section" className="portal-analytics-grid mt-4"><div className="min-w-0"><CampaignChart period={period}/></div><section id="actions-section" className="metric-card"><h2 className="panel-title mb-4">Quick Actions</h2><div className="grid gap-3 sm:grid-cols-2">{quickActions.map(action=><Link key={action.to} to={action.to} className="quick-action group"><span className={`rounded-xl p-3 ${action.color}`}><action.icon size={22}/></span><span className="min-w-0 flex-1"><span className="block text-sm font-semibold">{action.title}</span><span className="mt-1 block text-xs text-muted-foreground">{action.description}</span></span><ArrowRight size={16} className="shrink-0 text-muted-foreground group-hover:text-primary"/></Link>)}</div></section></div>
  <div id="campaigns-section" className="mt-4">{!loading&&<RecentCampaignTable campaigns={campaigns}/>}</div>
 </DashboardLayout></>;
}
