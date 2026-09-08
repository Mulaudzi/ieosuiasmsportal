import { useEffect, useMemo, useState } from "react";
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

const newKey = () => crypto.randomUUID();

export default function CreateSmsCampaign() {
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const [name, setName] = useState("");
  const [manual, setManual] = useState("");
  const [groupIds, setGroupIds] = useState<string[]>([]);
  const [contactIds, setContactIds] = useState<string[]>([]);
  const [excluded, setExcluded] = useState<string[]>([]);
  const [contacts, setContacts] = useState<Array<{id:string;name:string;phone:string}>>([]);
  const [groups, setGroups] = useState<Array<{id:string;name:string}>>([]);
  const [templates,setTemplates]=useState<Array<{id:string;name:string;content:string}>>([]);
  const [selectedTemplate,setSelectedTemplate]=useState("none");
  const [content, setContent] = useState("");
  const [schedule, setSchedule] = useState("");
  const [preview, setPreview] = useState<SmsPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const recipients = useMemo(() => ({
    manual: manual.split(/[\n,;]+/).map(v => v.trim()).filter(Boolean),
    contact_ids: contactIds,
    group_ids: groupIds,
    excluded,
  }), [manual, contactIds, groupIds, excluded]);
  useEffect(()=>{void Promise.all([api.get<unknown[]>("/contacts",{per_page:"100"}),api.get<unknown[]>("/contact-groups"),getTemplates('sms')]).then(([contactResponse,groupResponse,templateResponse])=>{const contactRows=(Array.isArray(contactResponse.data)?contactResponse.data:[]) as Array<{id:string|number;name:string;phone:string}>;const groupRows=((groupResponse as typeof groupResponse&{groups?:unknown[]}).groups??groupResponse.data??[]) as Array<{id:string|number;name:string}>;const templatePayload=templateResponse as typeof templateResponse&{templates?:unknown[]};const templateRows=(templatePayload.templates??templateResponse.data??[]) as Array<{id:string|number;name:string;content:string}>;setContacts(contactRows.map(row=>({...row,id:String(row.id)})));setGroups(groupRows.map(row=>({...row,id:String(row.id)})));setTemplates(templateRows.map(row=>({...row,id:String(row.id)})));}).catch(()=>toast({title:"Campaign resources could not be fully loaded",description:"Manual entry remains available.",variant:"destructive"}))},[]);
  const chooseTemplate=(id:string)=>{setSelectedTemplate(id);if(id==='none')return;const template=templates.find(item=>item.id===id);if(template){setContent(template.content);setPreview(null);}};
  const toggle=(values:string[],setValues:(value:string[])=>void,id:string)=>setValues(values.includes(id)?values.filter(value=>value!==id):[...values,id]);

  const calculate = async (nextExcluded = excluded) => {
    setBusy(true);
    setPreview(null);
    try {
      const response = await previewSmsCampaign({ content, recipients: { ...recipients, excluded: nextExcluded } });
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
      const response = await createSmsCampaign({ name, content, recipients, scheduled_at: sendNow ? null : schedule, send_now: sendNow, idempotency_key: newKey() });
      const campaign = response.campaign ?? response.data?.campaign;
      if (!campaign) throw new Error("Campaign was not created");
      toast({ title: sendNow ? "Campaign queued" : "Campaign scheduled", description: "Processing continues in the background." });
      navigate(`/sms-campaigns/${campaign.id}`);
    } catch (error) {
      toast({ title: "Campaign was not submitted", description: error instanceof Error ? error.message : "Please try again.", variant: "destructive" });
    } finally { setBusy(false); }
  };

  return <DashboardLayout title="Create SMS Campaign" subtitle="Accurate recipients, segments and cost before sending">
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="flex gap-2 text-sm text-muted-foreground">{[1,2,3,4,5].map(n => <span key={n} className={step === n ? "font-semibold text-primary" : ""}>Step {n}</span>)}</div>
      <div className="rounded-xl border bg-card p-6 space-y-5">
        {step === 1 && <><div><Label>Campaign name</Label><Input value={name} onChange={e=>setName(e.target.value)} maxLength={100}/></div><Button disabled={!name.trim()} onClick={()=>setStep(2)}>Recipients</Button></>}
        {step === 2 && <>
          <div><Label>Manual phone numbers</Label><Textarea rows={7} placeholder="One number per line, or comma-separated" value={manual} onChange={e=>setManual(e.target.value)}/></div>
          <div><Label>Contacts</Label><div className="mt-2 max-h-40 overflow-auto rounded-lg border p-2">{contacts.length?contacts.map(contact=><button type="button" key={contact.id} onClick={()=>toggle(contactIds,setContactIds,contact.id)} className={`block w-full rounded p-2 text-left text-sm ${contactIds.includes(contact.id)?"bg-primary text-primary-foreground":"hover:bg-muted"}`}>{contact.name || contact.phone} · {contact.phone}</button>):<p className="p-2 text-sm text-muted-foreground">No contacts available.</p>}</div></div>
          <div><Label>Contact groups</Label><div className="mt-2 max-h-40 overflow-auto rounded-lg border p-2">{groups.length?groups.map(group=><button type="button" key={group.id} onClick={()=>toggle(groupIds,setGroupIds,group.id)} className={`block w-full rounded p-2 text-left text-sm ${groupIds.includes(group.id)?"bg-primary text-primary-foreground":"hover:bg-muted"}`}>{group.name}</button>):<p className="p-2 text-sm text-muted-foreground">No groups available.</p>}</div></div>
          <div className="flex gap-2"><Button variant="outline" onClick={()=>setStep(1)}>Back</Button><Button onClick={()=>setStep(3)}>Message</Button></div>
        </>}
        {step === 3 && <><div><Label>Saved SMS template</Label><Select value={selectedTemplate} onValueChange={chooseTemplate}><SelectTrigger className="mt-2"><SelectValue placeholder="Choose a saved template"/></SelectTrigger><SelectContent><SelectItem value="none">Write message from scratch</SelectItem>{templates.map(template=><SelectItem key={template.id} value={template.id}>{template.name}</SelectItem>)}</SelectContent></Select>{templates.length===0&&<p className="mt-1 text-xs text-muted-foreground">No saved SMS templates yet. You can still write the message below.</p>}</div><div><Label>SMS message</Label><Textarea rows={8} value={content} onChange={e=>{setContent(e.target.value);setPreview(null)}}/><p className="mt-1 text-xs text-muted-foreground">Selecting a template fills this field. You can edit it before continuing.</p></div><p className="text-sm text-muted-foreground">The server is authoritative for GSM-7/UCS-2 analysis and credit calculation.</p><div className="flex gap-2"><Button variant="outline" onClick={()=>setStep(2)}>Back</Button><Button disabled={!content.trim()||busy} onClick={() => void calculate()}>{busy?"Calculating…":"Calculate recipients and credits"}</Button></div></>}
        {step === 4 && preview && <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">{[["Entered",preview.entered_count],["Invalid",preview.invalid_count],["Duplicates",preview.duplicate_count],["Opted out",preview.opted_out_count],["Removed from campaign",preview.excluded_count],["Sendable",preview.sendable_count],["Encoding",preview.encoding.toUpperCase()],["Characters",preview.character_count],["Credits/message",preview.segment_count],["Credits required",preview.required_credits]].map(([label,value])=><div key={String(label)} className="rounded-lg bg-muted p-3"><p className="text-xs text-muted-foreground">{label}</p><p className="font-semibold">{value}</p></div>)}</div>
          <div className="rounded-lg border p-4 space-y-1"><p>Campaign requirement: <strong>{preview.required_credits.toLocaleString()} SMS credits</strong></p><p>Available: <strong>{preview.available_credits.toLocaleString()} SMS credits</strong></p><p className="text-xs text-muted-foreground">1 credit sends 1 billable SMS segment (R{Number(preview.price_per_segment).toFixed(2)}).</p><p className={preview.sufficient_balance?"text-success":"text-destructive"}>{preview.sufficient_balance?"You have enough SMS credits":"You do not have enough SMS credits"}</p></div>
          {!preview.sufficient_balance && <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 space-y-3"><p className="font-medium">Add credits or reduce this campaign</p><p className="text-sm text-muted-foreground">Removing recipients here affects only this campaign. Contacts stay in their contact groups.</p><div className="flex flex-wrap gap-2"><Button asChild><Link to="/wallet">Buy SMS credits</Link></Button><Button variant="outline" disabled={preview.available_credits < preview.segment_count} onClick={fitToAvailableCredits}>Fit campaign to available credits</Button></div></div>}
          <div className="rounded-lg border"><div className="border-b p-3"><p className="font-medium">Campaign recipients ({preview.sendable_count})</p><p className="text-xs text-muted-foreground">Remove individual numbers from this send without changing saved contacts or groups.</p></div><div className="max-h-56 divide-y overflow-auto">{preview.recipients.map(phone=><div key={phone} className="flex items-center justify-between gap-3 p-3 text-sm"><span className="font-mono">{phone}</span><Button type="button" size="sm" variant="ghost" onClick={()=>excludeRecipient(phone)} disabled={busy}><X className="mr-1 h-4 w-4"/>Remove</Button></div>)}</div></div>
          <div className="flex gap-2"><Button variant="outline" onClick={()=>setStep(3)}>Back</Button><Button disabled={!preview.sufficient_balance} onClick={()=>setStep(5)}>Continue</Button></div>
        </>}
        {step === 5 && preview && <><div><Label>Schedule time (optional)</Label><Input type="datetime-local" value={schedule} onChange={e=>setSchedule(e.target.value)}/></div><div className="flex flex-wrap gap-2"><Button variant="outline" onClick={()=>setStep(4)}>Back</Button><Button disabled={busy} onClick={()=>submit(true)}>{busy?"Submitting…":"Queue now"}</Button><Button variant="secondary" disabled={busy||!schedule} onClick={()=>submit(false)}>Schedule</Button></div></>}
      </div>
    </div>
  </DashboardLayout>;
}
