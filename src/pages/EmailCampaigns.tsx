import { useCallback, useEffect, useRef, useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { EmailCampaignDetail } from '@/components/email/EmailCampaignDetail';
import { EmailCampaignActions } from '@/components/email/EmailCampaignActions';
import { EmailCampaign, EmailCampaignInput, EmailLimits, EmailQuote, EmailSender, EmailDesign, EmailFile, defaultEmailDesign, emailApi } from '@/lib/email-campaigns';
import { EmailDesignBuilder } from '@/components/email/EmailDesignBuilder';
import { EmailReplyTo } from '@/components/email/EmailReplyTo';
import { EmailRiskFindings } from '@/components/email/EmailRiskFindings';
import { api } from '@/lib/api';
import { useAuth } from '@/hooks/useAuth';
import { Mail, RefreshCw, ShieldCheck, Send, Eye } from 'lucide-react';
import { toast } from 'sonner';

type Group = {id: string; name: string};
const emptyForm = {name:'',subject:'',body:'',emails:'',consent_evidence:'',consent_confirmed:false};
export default function EmailCampaigns() {
  const {user}=useAuth();
  const [limits,setLimits]=useState<EmailLimits|null>(null), [sender,setSender]=useState<EmailSender|null>(null);
  const [campaigns,setCampaigns]=useState<EmailCampaign[]>([]),[selected,setSelected]=useState<EmailCampaign|null>(null);
  const [loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState('');
  const [identity,setIdentity]=useState({business_name:'',postal_address:'',reply_to:''});
  const [design,setDesign]=useState<EmailDesign>({...defaultEmailDesign}),[files,setFiles]=useState<EmailFile[]>([]),[attachmentIds,setAttachmentIds]=useState<string[]>([]);
  const [form,setForm]=useState(emptyForm),[groups,setGroups]=useState<Group[]>([]),[groupIds,setGroupIds]=useState<string[]>([]);
  const [quote,setQuote]=useState<EmailQuote|null>(null),[quotedPayload,setQuotedPayload]=useState('');
  const request=useRef({payload:'',key:''});
  const load=useCallback(async()=>{
    setLoading(true);setError('');
    try {
      const l=await emailApi.limits();setLimits(l);
      if(l.enabled) {
        const [s,c]=await Promise.all([emailApi.sender(),emailApi.campaigns()]);setSender(s);setCampaigns(c);
        if(s)setIdentity({business_name:s.business_name,postal_address:s.postal_address,reply_to:s.reply_to});
        try {const g=await api.get('/contact-groups');setGroups((g.groups as Group[])||[]);}catch{setGroups([]);}
      }
    }catch(e){setError(e instanceof Error?e.message:'Could not load email campaigns.');}finally{setLoading(false);}
  },[]);
  useEffect(()=>{void load();},[load]);
  const input: EmailCampaignInput={name:form.name,subject:form.subject,body:form.body,design,attachment_ids:attachmentIds,consent_evidence:form.consent_evidence,consent_confirmed:form.consent_confirmed,recipients:{manual:form.emails.split(/[\s,;]+/).filter(Boolean),group_ids:groupIds,contact_ids:[]}};
  const senderMatches=!!sender&&identity.business_name===sender.business_name&&identity.postal_address===sender.postal_address&&(identity.reply_to||user?.email)===sender.reply_to;
  const payload=JSON.stringify({...input,sender_version:sender?.version}),quoteCurrent=!!quote && quotedPayload===payload;
  async function act(task:()=>Promise<void>){setBusy(true);setError('');try{await task();}catch(e){setError(e instanceof Error?e.message:'This action could not finish. Refresh before trying again.');}finally{setBusy(false);}}
  async function refreshSelected(c:EmailCampaign){setSelected(c);setCampaigns(await emailApi.campaigns());}
  return <DashboardLayout title="Email Campaigns" subtitle="Send consent-based email from your business — five emails per SMS credit" actions={<Button variant="outline" disabled={loading||busy} onClick={()=>void act(async()=>{await load();if(selected)setSelected(await emailApi.detail(selected.id));})}><RefreshCw className="mr-2 h-4 w-4"/>Refresh</Button>}>
    <div className="email-workspace space-y-6">
      {error && <div role="alert" className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">{error}</div>}
      {loading ? <div aria-label="Loading email campaigns" className="space-y-4"><Skeleton className="h-24 w-full"/><Skeleton className="h-80 w-full"/></div> : <>
        <div className="grid gap-4 md:grid-cols-3"><Info title="5 emails = 1 credit" text="0.2 SMS credit for each email accepted by the mail server. Failed submissions are not charged."/><Info title="Recognizable sender" text="Your approved business name via IEOSUIA. Replies go to your verified portal email."/><Info title="Permission comes first" text="Consent evidence and admin review are required. Unsubscribe and report-abuse links are always included."/></div>
        <EmailDesignBuilder design={design} onChange={setDesign} subject={form.subject} body={form.body} enabled={!!limits?.enabled} files={files} onFilesChange={setFiles} attachmentIds={attachmentIds} onAttachmentsChange={setAttachmentIds} businessName={sender?.business_name||identity.business_name||'Your business'} disabled={busy}/>
        {limits && !limits.readiness.ready && <section className="rounded-xl border border-amber-300/60 bg-amber-50/40 p-4 dark:bg-amber-950/20"><h2 className="font-semibold">Live sending is not enabled</h2><p className="mt-1 text-sm">No email is sent by previewing or submitting for review.</p><ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">{limits.readiness.reasons.map(r=><li key={r}>{r}</li>)}</ul></section>}
        {limits?.enabled && <>
          <section className="rounded-xl border bg-card p-4 sm:p-6"><div className="mb-4 flex flex-wrap items-center gap-3"><ShieldCheck className="h-5 w-5 text-primary"/><h2 className="text-lg font-semibold">1. Business identity & replies</h2><Badge variant="secondary">{sender?.state||'Not requested'}</Badge></div><p className="mb-4 text-sm text-muted-foreground">Business identity means the business name and postal address shown to recipients, plus your authority to send for that business. An administrator reviews this separately from inbox verification. Changing sender details or Reply-To requires a new review.</p>
            <div className="grid gap-4 lg:grid-cols-2"><label className="space-y-2 text-sm">Business or organization name<Input value={identity.business_name} maxLength={100} onChange={e=>setIdentity({...identity,business_name:e.target.value})}/></label><label className="space-y-2 text-sm">Business postal address<Textarea value={identity.postal_address} maxLength={400} onChange={e=>setIdentity({...identity,postal_address:e.target.value})}/></label></div>
            <EmailReplyTo accountEmail={user?.email||''} selected={identity.reply_to||user?.email||''} onChange={email=>setIdentity(v=>({...v,reply_to:email}))} onSenderChange={setSender} disabled={busy}/><p className="my-3 text-sm">Replies to <strong>{identity.reply_to||user?.email}</strong> · Recipients see <strong>{identity.business_name||'Your business'} via IEOSUIA</strong></p>
            {sender?.state==='approved'&&!senderMatches&&<p className="mb-3 text-sm text-amber-700">Your changes need a new sender review before you can prepare a campaign.</p>}{sender?.state==='approved' && <p className="mb-3 text-sm text-muted-foreground">Approved account allowance: {sender.daily_limit} submission attempts per rolling 24 hours.</p>}
            <Button variant="outline" disabled={busy||sender?.state==='suspended'||identity.business_name.trim().length<2||identity.postal_address.trim().length<10} onClick={()=>void act(async()=>{setSender(await emailApi.saveSender({...identity,reply_to:identity.reply_to||user?.email}));toast.success('Sender identity submitted for review.');})}>Submit sender for review</Button>
          </section>
          {sender?.state==='approved'&&senderMatches && <section className="rounded-xl border bg-card p-4 sm:p-6"><h2 className="mb-4 text-lg font-semibold">2. Prepare an email campaign</h2><div className="grid gap-5 xl:grid-cols-2"><div className="space-y-4">
            <label className="block space-y-2 text-sm">Campaign name (optional)<Input value={form.name} maxLength={100} onChange={e=>setForm({...form,name:e.target.value})}/></label>
            <label className="block space-y-2 text-sm">Subject<Input value={form.subject} maxLength={180} onChange={e=>setForm({...form,subject:e.target.value})}/></label>
            <label className="block space-y-2 text-sm">Email message<Textarea className="min-h-48" value={form.body} maxLength={20000} onChange={e=>setForm({...form,body:e.target.value})}/></label><p className="text-xs text-muted-foreground">Write plain text; the selected layout styles it safely. Only scanned, supported files can be attached. Public HTTPS destinations are included in review.</p>
            <label className="block space-y-2 text-sm">Email addresses<Textarea placeholder="Paste addresses separated by commas or new lines" value={form.emails} onChange={e=>setForm({...form,emails:e.target.value})}/></label>
            {!!groups.length && <fieldset className="max-h-48 space-y-2 overflow-auto rounded-lg border p-3"><legend className="px-1 text-sm font-medium">Or select contact groups</legend>{groups.map(g=><label key={g.id} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={groupIds.includes(String(g.id))} onChange={e=>setGroupIds(ids=>e.target.checked?[...ids,String(g.id)]:ids.filter(id=>id!==String(g.id)))}/>{g.name}</label>)}</fieldset>}
          </div><div className="space-y-4"><div className="rounded-lg bg-muted/40 p-4 text-sm"><h3 className="font-semibold">Email preview</h3><p className="mt-2 font-medium">{sender.business_name} via IEOSUIA</p><p className="text-muted-foreground">Reply-To: {sender.reply_to}</p><h4 className="mt-4 font-medium">{form.subject||'Your subject'}</h4><p className="mt-2 whitespace-pre-wrap break-words">{form.body||'Your message appears here.'}</p><hr className="my-4"/><p className="whitespace-pre-wrap">{sender.postal_address}</p><p className="mt-2 text-muted-foreground">Sent via IEOSUIA · Unsubscribe · Report abuse</p></div>
            <label className="block space-y-2 text-sm">Consent source and evidence reference<Textarea placeholder="Where and when did these recipients consent to emails from your business? Include a reference an administrator can verify. Do not paste passwords or private tokens." value={form.consent_evidence} maxLength={1500} onChange={e=>setForm({...form,consent_evidence:e.target.value})}/></label>
            <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={form.consent_confirmed} onChange={e=>setForm({...form,consent_confirmed:e.target.checked})}/>Every recipient explicitly agreed to email from this sender for this purpose. This is not a purchased or scraped list. SMS consent alone does not qualify.</label>
            <p className="text-xs text-muted-foreground">Phishing, impersonation, fraud, malware, harassment and unlawful content are prohibited. Reviews and limits reduce risk; they cannot guarantee that every harmful message is detected.</p>
            {quoteCurrent && quote && <div role="status" className="rounded-lg border bg-primary/5 p-4 text-sm"><div className="grid grid-cols-2 gap-2">{Object.entries({Entered:quote.entered,Invalid:quote.invalid,Duplicates:quote.duplicates,'Opted out':quote.opted_out,Eligible:quote.sendable,'SMS credits':quote.credits_required}).map(([k,v])=><p key={k}>{k}: <strong>{v}</strong></p>)}</div><p className="mt-3">Estimated charge: R{Number(quote.amount).toFixed(2)} · Available: R{Number(quote.available_balance).toFixed(2)}</p>{!quote.sufficient_balance&&<p className="mt-2 text-destructive">Top up before queueing. Review itself does not reserve or charge credits.</p>}</div>}
          </div></div>{quoteCurrent&&<EmailRiskFindings report={quote?.moderation}/>}<EmailCampaignActions><Button variant="outline" disabled={busy||!form.subject.trim()||!form.body.trim()} onClick={()=>void act(async()=>{setQuote(await emailApi.quote(input));setQuotedPayload(payload);})}><Eye className="mr-2 h-4 w-4"/>Preview recipients & credits</Button><Button disabled={busy||!quoteCurrent||!quote?.sendable||!form.consent_confirmed||form.consent_evidence.trim().length<20} onClick={()=>void act(async()=>{
              if(request.current.payload!==payload)request.current={payload,key:crypto.randomUUID()};
              await refreshSelected(await emailApi.create({...input,idempotency_key:request.current.key}));toast.success('Campaign submitted for review. No emails sent.');
            })}><Mail className="mr-2 h-4 w-4"/>Submit for review</Button></EmailCampaignActions>
          </section>}
          <section className="rounded-xl border bg-card p-4 sm:p-6"><h2 className="mb-4 text-lg font-semibold">3. Review and send</h2><p className="mb-4 text-sm text-muted-foreground">Campaigns are immutable after submission. Open an approved campaign to queue it. Cancel and create a new campaign if anything needs changing.</p>{!campaigns.length?<p className="rounded-lg bg-muted/40 p-6 text-center text-muted-foreground">No email campaigns yet.</p>:<div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-3">Campaign</th><th className="p-3">Recipients</th><th className="p-3">Status</th><th className="p-3">Action</th></tr></thead><tbody>{campaigns.map(c=><tr key={c.id} className="border-t"><td className="p-3">{c.name||c.subject}</td><td className="p-3">{c.recipient_count}</td><td className="p-3">{c.state.replace(/_/g,' ')}</td><td className="p-3"><Button variant="outline" size="sm" disabled={busy} onClick={()=>void act(async()=>setSelected(await emailApi.detail(c.id)))}>Open</Button></td></tr>)}</tbody></table></div>}</section>
          {selected && <><EmailCampaignDetail campaign={selected}/><div className="flex flex-wrap justify-between gap-3"><Button variant="outline" disabled={busy||['completed','cancelled'].includes(selected.state)} onClick={()=>void act(async()=>refreshSelected(await emailApi.cancel(selected.id)))}>Cancel unsent emails</Button>{selected.state==='approved'&&<Button disabled={busy||!limits.readiness.ready} onClick={()=>void act(async()=>{await refreshSelected(await emailApi.queue(selected.id));toast.success('Emails queued. Credits are reserved, not all charged upfront.');})}><Send className="mr-2 h-4 w-4"/>Queue approved emails</Button>}</div></>}
        </>}
      </>}
    </div>
  </DashboardLayout>;
}
function Info({title,text}:{title:string;text:string}) {return <section className="rounded-xl border bg-card p-4"><h2 className="font-semibold">{title}</h2><p className="mt-2 text-sm text-muted-foreground">{text}</p></section>;}
