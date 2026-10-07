import { useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { Link, useNavigate } from "react-router-dom";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "@/hooks/use-toast";
import { api, createSmsCampaign, getTemplates, previewSmsCampaign, SmsPreview } from "@/lib/api";
import { X } from "lucide-react";
import { SmsMessageCounter } from "@/components/marketing/SmsMessageCounter";
import { Switch } from '@/components/ui/switch';

const newKey = () => crypto.randomUUID();

type Draft = { version: 1; step: number; name: string; manual: string; groupIds: string[]; contactIds: string[]; excluded: string[]; selectedTemplate: string; content: string; includeOptOut: boolean; schedule: string; submissionKey: string };
function readDraft(key: string): Draft | null {
  try {
    const d = JSON.parse(localStorage.getItem(key) || 'null');
    if (!d || d.version !== 1 || !Number.isInteger(d.step) || d.step < 1 || d.step > 5 || typeof d.includeOptOut !== 'boolean') return null;
    if (!['name','manual','selectedTemplate','content','schedule','submissionKey'].every(k => typeof d[k] === 'string')) return null;
    if (!['groupIds','contactIds','excluded'].every(k => Array.isArray(d[k]) && d[k].every((v: unknown) => typeof v === 'string'))) return null;
    if (!/^[a-zA-Z0-9-]{1,80}$/.test(d.submissionKey)) return null;
    return d;
  } catch { return null; }
}

export default function CreateSmsCampaign() {
  const { user } = useAuth();
  if (!user) return null;
  return <CampaignComposer key={String(user.id)} draftKey={`sms-campaign-draft:v1:${user.id}`} />;
}

function CampaignComposer({ draftKey }: { draftKey: string }) {
  const [draft] = useState(() => readDraft(draftKey));
  const finished = useRef(false);
  const [storageFailed, setStorageFailed] = useState(false);
  const navigate = useNavigate();
  const [step, setStep] = useState(Math.min(draft?.step ?? 1, 3));
  const [furthestStep,setFurthestStep] = useState(Math.min(draft?.step ?? 1,3));
  useEffect(()=>setFurthestStep(previous=>Math.max(previous,step)),[step]);
  const [name, setName] = useState(draft?.name ?? "");
  const [manual, setManual] = useState(draft?.manual ?? "");
  const [groupIds, setGroupIds] = useState<string[]>(draft?.groupIds ?? []);
  const [contactIds, setContactIds] = useState<string[]>(draft?.contactIds ?? []);
  const [excluded, setExcluded] = useState<string[]>(draft?.excluded ?? []);
  const [contacts, setContacts] = useState<Array<{id:string;name:string;phone:string}>>([]);
  const [groups, setGroups] = useState<Array<{id:string;name:string}>>([]);
  const [templates,setTemplates]=useState<Array<{id:string;name:string;content:string}>>([]);
  const [selectedTemplate,setSelectedTemplate]=useState(draft?.selectedTemplate ?? "none");
  const [content, setContent] = useState(draft?.content ?? "");
  const [includeOptOut,setIncludeOptOut]=useState(draft?.includeOptOut ?? true);
  const [optOutText,setOptOutText]=useState('');
  const [optOutLoaded,setOptOutLoaded]=useState(false);
  useEffect(()=>{api.get<{opt_out_text:string}>('/settings/sms').then(r=>{const data=(r.data??r) as {opt_out_text:string};setOptOutText(data.opt_out_text);setOptOutLoaded(true);}).catch(()=>toast({title:'Unable to load opt-out text',description:'Reload before creating your campaign.',variant:'destructive'}));},[]);
  const [schedule, setSchedule] = useState(draft?.schedule ?? "");
  const [preview, setPreview] = useState<SmsPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const optOutControl=<div className="rounded-lg border p-4 space-y-2"><div className="flex items-center justify-between gap-4"><Label htmlFor="include-opt-out">Include opt-out text</Label><Switch id="include-opt-out" checked={includeOptOut} disabled={busy||!optOutLoaded} onCheckedChange={value=>{setIncludeOptOut(value);setPreview(null);setStep(3);}}/></div><p className="text-sm whitespace-pre-wrap">{optOutLoaded?optOutText:'Loading saved opt-out text…'}</p><p className="text-xs text-muted-foreground">Included in the SMS and credit calculation when enabled. Disable only when appropriate for this message. This switch does not change recipient opt-out suppression or configure reply handling.</p></div>;
  const [submissionKey, setSubmissionKey] = useState(() => draft?.submissionKey ?? newKey());
  useEffect(() => {
    if (finished.current) return;
    try {
      const hasWork = name || manual || content || groupIds.length || contactIds.length || schedule;
      if (hasWork) localStorage.setItem(draftKey, JSON.stringify({version: 1, step, name, manual, groupIds, contactIds, excluded, selectedTemplate, content, includeOptOut, schedule, submissionKey} satisfies Draft));
      else localStorage.removeItem(draftKey);
      setStorageFailed(false);
    } catch { setStorageFailed(true); }
  }, [draftKey, step, name, manual, groupIds, contactIds, excluded, selectedTemplate, content, includeOptOut, schedule, submissionKey]);
  const discard = () => {
    if (!window.confirm('Discard this saved draft and start a new campaign?')) return;
    setName(''); setManual(''); setContent(''); setGroupIds([]); setContactIds([]); setExcluded([]);
    setSelectedTemplate('none'); setIncludeOptOut(true); setSchedule(''); setPreview(null); setStep(1); setFurthestStep(1); setSubmissionKey(newKey());
  };
  const recipients = useMemo(() => ({
    manual: manual.split(/[\n,;]+/).map(v => v.trim()).filter(Boolean),
    contact_ids: contactIds,
    group_ids: groupIds,
    excluded,
  }), [manual, contactIds, groupIds, excluded]);
  // A review is only reusable until its input changes; step shortcuts never bypass review.
  useEffect(()=>setPreview(null),[recipients,content,includeOptOut]);
  const [validRecipients,setValidRecipients] = useState(false);
  const [checkingRecipients,setCheckingRecipients] = useState(false);
  useEffect(()=>{
    if(step!==2)return;
    let cancelled=false;
    setValidRecipients(false);
    if(!recipients.manual.length&&!contactIds.length&&!groupIds.length){setCheckingRecipients(false);return;}
    setCheckingRecipients(true);
    const timer=window.setTimeout(()=>{
      previewSmsCampaign({content:'Recipient validation',include_opt_out:false,recipients}).then(response=>{
        if(!cancelled)setValidRecipients(Number((response.preview??response.data)?.sendable_count)>0);
      }).catch(()=>{if(!cancelled)toast({title:'Could not validate recipients',description:'Check your connection and try again.',variant:'destructive'});}).finally(()=>{if(!cancelled)setCheckingRecipients(false);});
    },300);
    return()=>{cancelled=true;window.clearTimeout(timer);};
  },[step,recipients,contactIds.length,groupIds.length]);
  useEffect(()=>{void Promise.all([api.get<unknown[]>("/contacts",{per_page:"100"}),api.get<unknown[]>("/contact-groups"),getTemplates('sms')]).then(([contactResponse,groupResponse,templateResponse])=>{const contactRows=(Array.isArray(contactResponse.data)?contactResponse.data:[]) as Array<{id:string|number;name:string;phone:string}>;const groupRows=((groupResponse as typeof groupResponse&{groups?:unknown[]}).groups??groupResponse.data??[]) as Array<{id:string|number;name:string}>;const templatePayload=templateResponse as typeof templateResponse&{templates?:unknown[]};const templateRows=(templatePayload.templates??templateResponse.data??[]) as Array<{id:string|number;name:string;content:string}>;setContacts(contactRows.map(row=>({...row,id:String(row.id)})));setGroups(groupRows.map(row=>({...row,id:String(row.id)})));setTemplates(templateRows.map(row=>({...row,id:String(row.id)})));}).catch(()=>toast({title:"Campaign resources could not be fully loaded",description:"Manual entry remains available.",variant:"destructive"}))},[]);
  const chooseTemplate=(id:string)=>{setSelectedTemplate(id);if(id==='none')return;const template=templates.find(item=>item.id===id);if(template){setContent(template.content);setPreview(null);}};
  const toggle=(values:string[],setValues:(value:string[])=>void,id:string)=>setValues(values.includes(id)?values.filter(value=>value!==id):[...values,id]);

  const calculate = async (nextExcluded = excluded) => {
    setBusy(true);
    setPreview(null);
    try {
      const response = await previewSmsCampaign({ content, include_opt_out:includeOptOut, recipients: { ...recipients, excluded: nextExcluded } });
      const value = response.preview ?? response.data;
      if (!value) throw new Error("Preview was not returned");
      setPreview(value); setStep(4);
    } catch (error) {
      setStep(3);
      toast({ title: "Cannot calculate campaign", description: error instanceof Error ? error.message : "Check the recipients and message.", variant: "destructive" });
    } finally { setBusy(false); }
  };

  const excludeRecipient = (phone: string) => {
    const next = [...new Set([...excluded, phone])];
    setExcluded(next);
    void calculate(next);
  };

  const fitToAvailableCredits = () => {
    if (!preview || preview.segment_count < 1) return;
    const affordableRecipients = Math.floor(preview.available_credits / preview.segment_count);
    const next = [...new Set([...excluded, ...preview.recipients.slice(Math.max(0, affordableRecipients))])];
    setExcluded(next);
    void calculate(next);
  };

  const submit = async (sendNow: boolean) => {
    setBusy(true);
    try {
      const response = await createSmsCampaign({ name, content, include_opt_out:includeOptOut, recipients, scheduled_at: sendNow ? null : schedule, send_now: sendNow, idempotency_key: submissionKey });
      const campaign = response.campaign ?? response.data?.campaign;
      if (!campaign) throw new Error("Campaign was not created");
      finished.current = true;
      try { localStorage.removeItem(draftKey); } catch { /* The stable submission key prevents duplicate submission if storage is unavailable. */ }
      toast({ title: sendNow ? "Campaign queued" : "Campaign scheduled", description: "Processing continues in the background." });
      navigate(`/sms-campaigns/${campaign.id}`);
    } catch (error) {
      toast({ title: "Campaign was not submitted", description: error instanceof Error ? error.message : "Please try again.", variant: "destructive" });
    } finally { setBusy(false); }
  };

  return <DashboardLayout title="Create SMS Campaign" subtitle="Accurate recipients, segments and cost before sending" campaignStep={step}>
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="rounded-lg border p-4 text-sm space-y-2">
        <p role="status">{storageFailed ? 'Draft could not be saved. Keep this page open and enable browser storage before leaving.' : 'Draft saved automatically on this browser for your account. You can refresh or leave and return.'}</p>
        {draft && <p className="text-muted-foreground">Your draft has been restored. Final review drafts return to the message step so you can recalculate current recipients and credits. Nothing is sent automatically.</p>}
        <Button type="button" variant="outline" size="sm" disabled={busy} onClick={discard}>Discard draft and start again</Button>
      </div>
      <nav aria-label="Campaign steps" className="grid grid-cols-5 gap-1 text-xs sm:text-sm">{['Campaign','Recipients','Message','Review','Send'].map((label,i)=>{const n=i+1;const unlocked=n<=furthestStep && (n<=step || (name.trim() && (n<3||validRecipients) && (n<4||content.trim()&&preview) && (n<5||preview?.sufficient_balance)));return <button key={label} type="button" title={label} aria-current={step===n?'step':undefined} disabled={busy||!unlocked} onClick={()=>setStep(n)} className={`rounded-lg border px-1 py-3 disabled:cursor-default ${step===n?'border-primary bg-primary/10 font-semibold text-primary':'enabled:hover:bg-muted text-muted-foreground'}`}><span aria-hidden="true" className="block font-semibold">{n<furthestStep&&n!==step?'✓':n}</span>{label}</button>;})}</nav>
      <div className="rounded-xl border bg-card p-6 space-y-5">
        {step === 1 && <><div><Label>Campaign name</Label><Input value={name} onChange={e=>setName(e.target.value)} maxLength={100}/>{!name.trim()&&<p className="mt-1 text-sm text-muted-foreground">Add a campaign name to continue.</p>}</div><span className="inline-block" title={!name.trim()?'Add a campaign name to continue':'Recipients'}><Button disabled={!name.trim()} onClick={()=>setStep(2)}>Next</Button></span></>}
        {step === 2 && <>
          <div><Label>Manual phone numbers</Label><Textarea rows={7} placeholder="One number per line, or comma-separated" value={manual} onChange={e=>setManual(e.target.value)}/>{!validRecipients&&<p role="status" className="text-sm text-muted-foreground">{checkingRecipients?'Checking recipients…':'Add at least one recipient to continue.'}</p>}</div>
          <div><Label>Contacts</Label><div className="mt-2 max-h-40 overflow-auto rounded-lg border p-2">{contacts.length?contacts.map(contact=><button type="button" key={contact.id} onClick={()=>toggle(contactIds,setContactIds,contact.id)} className={`block w-full rounded p-2 text-left text-sm ${contactIds.includes(contact.id)?"bg-primary text-primary-foreground":"hover:bg-muted"}`}>{contact.name || contact.phone} · {contact.phone}</button>):<p className="p-2 text-sm text-muted-foreground">No contacts available.</p>}</div></div>
          <div><Label>Contact groups</Label><div className="mt-2 max-h-40 overflow-auto rounded-lg border p-2">{groups.length?groups.map(group=><button type="button" key={group.id} onClick={()=>toggle(groupIds,setGroupIds,group.id)} className={`block w-full rounded p-2 text-left text-sm ${groupIds.includes(group.id)?"bg-primary text-primary-foreground":"hover:bg-muted"}`}>{group.name}</button>):<p className="p-2 text-sm text-muted-foreground">No groups available.</p>}</div></div>
          
          <div className="flex gap-2"><Button variant="outline" onClick={()=>setStep(1)}>Back</Button><Button disabled={!validRecipients||checkingRecipients} onClick={()=>setStep(3)}>Next</Button></div>
        </>}
        {step === 3 && <><div><Label>Saved SMS template</Label><Select value={selectedTemplate} onValueChange={chooseTemplate}><SelectTrigger className="mt-2"><SelectValue placeholder="Choose a saved template"/></SelectTrigger><SelectContent><SelectItem value="none">Write message from scratch</SelectItem>{templates.map(template=><SelectItem key={template.id} value={template.id}>{template.name}</SelectItem>)}</SelectContent></Select>{templates.length===0&&<p className="mt-1 text-xs text-muted-foreground">No saved SMS templates yet. You can still write the message below.</p>}</div><div><Label htmlFor="sms-message">SMS message</Label><Textarea id="sms-message" rows={8} value={content} onChange={e=>{setContent(e.target.value);setPreview(null)}}/>{!content.trim()&&<p className="text-sm text-muted-foreground">Add a message to continue.</p>}<p className="mt-1 text-xs text-muted-foreground">Selecting a template fills this field. You can edit it before continuing.</p></div>{optOutControl}<SmsMessageCounter content={content} includeFooter={includeOptOut} footer={optOutText} footerLoaded={optOutLoaded} onChange={value=>{setContent(value);setPreview(null)}}/><div className="flex gap-2"><Button variant="outline" onClick={()=>setStep(2)}>Back</Button><Button disabled={!content.trim()||busy||!optOutLoaded} onClick={() => void calculate()}>{busy?"Calculating…":"Next"}</Button></div></>}
        {step === 4 && preview && <>
          <section aria-label="Campaign summary" className="rounded-xl border border-primary/30 bg-primary/5 p-5 space-y-2"><h2 className="font-semibold">{name}</h2><p>{preview.sendable_count} recipients</p><p className="whitespace-pre-wrap text-sm">{preview.final_content}</p><p>{preview.required_credits.toLocaleString()} SMS credits</p></section>
          <p className="text-sm text-muted-foreground">Credits are charged per SMS part per recipient, not per campaign. Standard text fits 160 units in one part (153 per part for longer messages); Unicode fits 70 (67 per part for longer messages). Curly quotes and emoji can switch the whole message to Unicode. The total below includes the enabled footer and each recipient's personalisation.</p>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">{[["Entered",preview.entered_count],["Invalid",preview.invalid_count],["Duplicates",preview.duplicate_count],["Opted out",preview.opted_out_count],["Removed from campaign",preview.excluded_count],["Sendable",preview.sendable_count],["Message type",preview.encoding==='ucs2'?'Unicode text':'Standard text'],["Characters before personalisation",preview.character_count],["Maximum credits per recipient",preview.segment_count],["Credits required",preview.required_credits]].map(([label,value])=><div key={String(label)} className="rounded-lg bg-muted p-3"><p className="text-xs text-muted-foreground">{label}</p><p className="font-semibold">{value}</p></div>)}</div>
          <div className="rounded-lg border p-4 space-y-1"><p>Campaign requirement: <strong>{preview.required_credits.toLocaleString()} SMS credits</strong></p><p>Available: <strong>{preview.available_credits.toLocaleString()} SMS credits</strong></p><p className="text-xs text-muted-foreground">1 credit sends 1 billable SMS segment (R{Number(preview.price_per_segment).toFixed(2)}).</p><p className={preview.sufficient_balance?"text-success":"text-destructive"}>{preview.sufficient_balance?"You have enough SMS credits":"You do not have enough SMS credits"}</p></div>
          {!preview.sufficient_balance && <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 space-y-3"><p className="font-medium">Add credits or reduce this campaign</p><p className="text-sm text-muted-foreground">Removing recipients here affects only this campaign. Contacts stay in their contact groups.</p><div className="flex flex-wrap gap-2"><Button asChild><Link to="/wallet">Buy SMS credits</Link></Button><Button variant="outline" disabled={preview.available_credits < preview.segment_count} onClick={fitToAvailableCredits}>Fit campaign to available credits</Button></div></div>}
          <div className="rounded-lg border"><div className="border-b p-3"><p className="font-medium">Campaign recipients ({preview.sendable_count})</p><p className="text-xs text-muted-foreground">Remove individual numbers from this send without changing saved contacts or groups.</p></div><div className="max-h-56 divide-y overflow-auto">{preview.recipients.map(phone=><div key={phone} className="flex items-center justify-between gap-3 p-3 text-sm"><span className="font-mono">{phone}</span><Button type="button" size="sm" variant="ghost" onClick={()=>excludeRecipient(phone)} disabled={busy}><X className="mr-1 h-4 w-4"/>Remove</Button></div>)}</div></div>
          <div className="flex gap-2"><Button variant="outline" onClick={()=>setStep(3)}>Back</Button><Button disabled={!preview.sufficient_balance} onClick={()=>setStep(5)}>Continue</Button></div>
        </>}
        {step === 5 && preview && <>{optOutControl}<div className="rounded-lg border p-4 whitespace-pre-wrap">{preview.final_content}</div><div><Label>Schedule time (optional)</Label><Input type="datetime-local" value={schedule} onChange={e=>setSchedule(e.target.value)}/></div><div className="flex flex-wrap gap-2"><Button variant="outline" onClick={()=>setStep(4)}>Back</Button><Button disabled={busy} onClick={()=>submit(true)}>{busy?"Submitting…":"Queue now"}</Button><Button variant="secondary" disabled={busy||!schedule} onClick={()=>submit(false)}>Schedule</Button></div></>}
      </div>
    </div>
  </DashboardLayout>;
}
