import { useCallback, useEffect, useState } from 'react';
import { AdminLayout } from '@/components/layout/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { EmailCampaignDetail } from '@/components/email/EmailCampaignDetail';
import { EmailCampaign, EmailLimits, EmailSender, emailApi } from '@/lib/email-campaigns';
import { RefreshCw, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';

export default function AdminEmailCampaigns() {
  const [limits,setLimits]=useState<EmailLimits|null>(null),[loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState('');
  const [senders,setSenders]=useState<EmailSender[]>([]),[campaigns,setCampaigns]=useState<EmailCampaign[]>([]);
  const [sender,setSender]=useState<EmailSender|null>(null),[campaign,setCampaign]=useState<EmailCampaign|null>(null);
  const [evidence,setEvidence]=useState(''),[dailyLimit,setDailyLimit]=useState(''),[checked,setChecked]=useState(false);
  const [riskChecked,setRiskChecked]=useState(false),[filesChecked,setFilesChecked]=useState(false);
  const load=useCallback(async()=>{
    setLoading(true);setError('');
    try {const l=await emailApi.limits();setLimits(l);if(l.enabled){const data=await emailApi.overview();setSenders(data.senders);setCampaigns(data.campaigns);}}
    catch(e){setError(e instanceof Error?e.message:'Email review could not load.');}finally{setLoading(false);}
  },[]);
  useEffect(()=>{void load();},[load]);
  function reset(){setSender(null);setCampaign(null);setEvidence('');setDailyLimit('');setChecked(false);setRiskChecked(false);setFilesChecked(false);}
  async function act(task:()=>Promise<void>){setBusy(true);setError('');try{await task();}catch(e){setError(e instanceof Error?e.message:'Review could not finish. Refresh before retrying.');}finally{setBusy(false);}}
  async function decide(decision:string) {
    await act(async()=>{
      if(sender) await emailApi.reviewSender(sender.user_id,{decision,evidence,daily_limit:Number(dailyLimit),expected_version:sender.version});
      else if(campaign) await emailApi.reviewCampaign(campaign.id,{decision,evidence,fingerprint:campaign.fingerprint||'',risk_acknowledged:riskChecked,files_reviewed:filesChecked});
      reset();await load();toast.success(`Email review recorded: ${decision}. No emails were submitted.`);
    });
  }
  return <AdminLayout title="Email safety & review" subtitle="Approve identities, inspect consent and content, and stop abusive senders" actions={<Button variant="outline" disabled={busy||loading} onClick={()=>void load()}><RefreshCw className="mr-2 h-4 w-4"/>Refresh</Button>}>
    <div className="email-workspace space-y-6">
      {error&&<p role="alert" className="rounded-lg border border-destructive/40 p-4 text-sm text-destructive">{error}</p>}
      {loading?<Skeleton className="h-80 w-full"/>:<>
        {limits&&!limits.readiness.ready&&<section className="rounded-xl border border-amber-300/60 bg-amber-50/40 p-4 dark:bg-amber-950/20"><h2 className="font-semibold">Live sending is disabled</h2><ul className="mt-2 list-disc space-y-1 pl-5 text-sm">{limits.readiness.reasons.map(r=><li key={r}>{r}</li>)}</ul></section>}
        {limits?.enabled&&<>
          <div className="grid gap-5 xl:grid-cols-2"><section className="rounded-xl border bg-card p-4 sm:p-6"><h2 className="mb-3 text-lg font-semibold">Sender identities</h2><p className="mb-3 text-sm text-muted-foreground">Check identity and authorization to represent the named business. A verified login email is not sufficient business-ownership evidence.</p><div className="max-h-96 space-y-2 overflow-auto">{!senders.length&&<p>No sender requests yet.</p>}{senders.map(s=><button key={s.user_id} disabled={busy} className="w-full rounded-lg border p-3 text-left hover:bg-muted" onClick={()=>{reset();setSender(s);setDailyLimit(String(s.daily_limit||''));}}><strong>{s.business_name}</strong><span className="ml-2 text-xs">{s.state}</span><p className="break-all text-sm text-muted-foreground">{s.reply_to}</p></button>)}</div></section>
          <section className="rounded-xl border bg-card p-4 sm:p-6"><h2 className="mb-3 text-lg font-semibold">Campaign review queue & history</h2><p className="mb-3 text-sm text-muted-foreground">Every campaign needs a content, destination-link and recipient-consent review. Reviews never submit emails or deduct credits.</p><div className="max-h-96 space-y-2 overflow-auto">{!campaigns.length&&<p>No email campaigns yet.</p>}{campaigns.map(c=><button key={c.id} disabled={busy} className="w-full rounded-lg border p-3 text-left hover:bg-muted" onClick={()=>void act(async()=>{reset();setCampaign(await emailApi.detail(c.id,true));})}><strong>{c.name||c.subject}</strong><p className="text-sm text-muted-foreground">Account {c.user_id} · {c.recipient_count} recipients · {c.state.replace(/_/g,' ')}</p></button>)}</div></section></div>
          {sender&&<section className="space-y-3 rounded-xl border bg-card p-4 sm:p-6"><h2 className="text-lg font-semibold">Review {sender.business_name}</h2><p>Verified reply inbox: {sender.reply_to}</p><p className="text-sm text-muted-foreground">Verified account: {sender.account_email||sender.reply_to}</p><p className="whitespace-pre-wrap">{sender.postal_address}</p><p className="text-sm text-muted-foreground">Current status: {sender.state}. No arbitrary customer From address is allowed. The configured, authenticated platform address is always used.</p><label className="block max-w-sm space-y-2 text-sm">Approved daily attempt allowance<Input type="number" min={1} value={dailyLimit} onChange={e=>setDailyLimit(e.target.value)}/></label></section>}
          {campaign&&<EmailCampaignDetail campaign={campaign}/>}
          {(sender||campaign?.state==='awaiting_review')&&<section className="space-y-4 rounded-xl border bg-card p-4 sm:p-6"><h2 className="flex items-center gap-2 font-semibold"><ShieldCheck className="h-5 w-5"/>Record an accountable review</h2><label className="block space-y-2 text-sm">Evidence reference and review notes<Textarea value={evidence} maxLength={1500} onChange={e=>setEvidence(e.target.value)} placeholder="Record verifiable evidence, checks performed and any rejection/suspension reason. Do not include passwords or access tokens."/></label><label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={checked} onChange={e=>setChecked(e.target.checked)}/>{sender?'I checked identity and authority to represent this business.':'I verified consent for the exact recipient list and checked the content and destination links for abuse.'}</label>{campaign?.moderation?.review_required&&<label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={riskChecked} onChange={e=>setRiskChecked(e.target.checked)}/>I investigated every automated content warning and destination.</label>}{!!campaign?.files?.length&&<label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={filesChecked} onChange={e=>setFilesChecked(e.target.checked)}/>I opened and inspected every exact attachment and inline image.</label>}<p className="text-xs text-muted-foreground">Self-approval is blocked. Uncertain SMTP results require evidence-based reconciliation, not a resend. Sender suspension stops future claims, but cannot recall email already in transit.</p><div className="flex flex-wrap justify-end gap-3"><Button variant="outline" disabled={busy||evidence.trim().length<20} onClick={()=>void decide('rejected')}>Reject</Button>{sender&&<Button variant="destructive" disabled={busy||evidence.trim().length<20} onClick={()=>void decide('suspended')}>Suspend sending</Button>}<Button disabled={busy||!checked||evidence.trim().length<20||(!!sender&&Number(dailyLimit)<1)||(!!campaign?.moderation?.review_required&&!riskChecked)||(!!campaign?.files?.length&&!filesChecked)} onClick={()=>void decide('approved')}>Approve {sender?'sender':'campaign'}</Button></div></section>}
        </>}
      </>}
    </div>
  </AdminLayout>;
}
