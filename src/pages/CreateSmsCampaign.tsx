import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { SaveDraftButton } from '@/components/drafts/SaveDraftButton';
import { deleteWorkDraft, draftReference, DraftReference, getWorkDraft, isSmsDraft, SmsDraftPayload, WorkDraft } from '@/lib/work-drafts';
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { toast } from "@/hooks/use-toast";
import { api, createSmsCampaign, getTemplates, previewSmsCampaign, SmsPreview } from "@/lib/api";
import { RefreshCw } from "lucide-react";
import { SmsWizard, smsStepDescriptions } from "@/components/dashboard/SmsWizard";
import { type PickableContactGroup } from '@/components/contacts/ContactGroupPicker';

const newKey = () => crypto.randomUUID();

type Draft = SmsDraftPayload & {savedDraft?: DraftReference};
function readDraft(key: string): Draft | null {
  try {
    const d = JSON.parse(localStorage.getItem(key) || 'null');
    if (!isSmsDraft(d)) return null;
    return d;
  } catch { return null; }
}

export default function CreateSmsCampaign() {
  const { user } = useAuth();
  const [params] = useSearchParams();const id = params.get('draft');
  const query = useQuery({queryKey: ['work-draft', user?.id, id], enabled: !!user && !!id, retry: false, refetchOnWindowFocus: false, queryFn: () => getWorkDraft(id!, 'sms')});
  if (!user) return null;
  if (id && !query.data) return <DashboardLayout title="Resume SMS draft"><p role={query.isError ? 'alert' : 'status'}>{query.isError ? 'This draft could not be opened. It may have been deleted or belongs to another account.' : 'Loading your draft...'}</p>{query.isError && <Button variant="outline" onClick={() => void query.refetch()}>Retry</Button>}<Link className="ml-3 text-primary underline" to="/sms-campaigns">Back to SMS Campaigns</Link></DashboardLayout>;
  return <CampaignComposer key={`${user.id}:${id ?? 'local'}`} draftKey={`sms-campaign-draft:v1:${user.id}${id ? `:${id}` : ''}`} accountDraft={id ? query.data : undefined} />;
}

function CampaignComposer({ draftKey, accountDraft }: { draftKey: string; accountDraft?: WorkDraft<'sms'> }) {
  const [draft] = useState<Draft | null>(() => {
    const local = readDraft(draftKey);
    if (!accountDraft) return local;
    return local?.savedDraft?.id === accountDraft.id ? local : {...accountDraft.payload, savedDraft: draftReference(accountDraft)};
  });
  const [savedDraft, setSavedDraft] = useState<DraftReference | null>(draft?.savedDraft ?? null);
  const [draftSaving, setDraftSaving] = useState(false);const queryClient = useQueryClient();
  const finished = useRef(false);
  const [storageFailed, setStorageFailed] = useState(false);
  const navigate = useNavigate();
  // Recovered contents are kept, but every entry starts with campaign naming.
  const [step, setStep] = useState(1);
  const [furthestStep,setFurthestStep] = useState(1);
  useEffect(()=>setFurthestStep(previous=>Math.max(previous,step)),[step]);
  const [name, setName] = useState(draft?.name ?? "");
  const [manual, setManual] = useState(draft?.manual ?? "");
  const [groupIds, setGroupIds] = useState<string[]>(draft?.groupIds ?? []);
  const [params] = useSearchParams();
  const initialGroup = useRef(params.get('group'));
  const [recipientPreview, setRecipientPreview] = useState<SmsPreview | null>(null);
  const [contactSearch, setContactSearch] = useState('');
  const [contactsLoading, setContactsLoading] = useState(false);
  const sending = useRef(false);
  const [contactIds, setContactIds] = useState<string[]>(draft?.contactIds ?? []);
  const [excluded, setExcluded] = useState<string[]>(draft?.excluded ?? []);
  const [contacts, setContacts] = useState<Array<{id:string;name:string;phone:string}>>([]);
  const [groups, setGroups] = useState<PickableContactGroup[]>([]);
  const [groupsLoaded, setGroupsLoaded] = useState(false);
  const [templates,setTemplates]=useState<Array<{id:string;name:string;content:string}>>([]);
  const [selectedTemplate,setSelectedTemplate]=useState(draft?.selectedTemplate ?? "none");
  const [content, setContent] = useState(draft?.content ?? "");
  const [includeOptOut,setIncludeOptOut]=useState(draft?.includeOptOut ?? false);
  const [optOutText,setOptOutText]=useState('');
  const [optOutLoaded,setOptOutLoaded]=useState(false);
  useEffect(()=>{api.get<{opt_out_text:string}>('/settings/sms').then(r=>{const data=(r.data??r) as {opt_out_text:string};setOptOutText(data.opt_out_text);setOptOutLoaded(true);}).catch(()=>toast({title:'Unable to load opt-out text',description:'Reload before creating your campaign.',variant:'destructive'}));},[]);
  const [schedule, setSchedule] = useState(draft?.schedule ?? "");
  const [preview, setPreview] = useState<SmsPreview | null>(null);
  const [working, setBusy] = useState(false);const busy = working || draftSaving;
  const [submissionKey, setSubmissionKey] = useState(() => draft?.submissionKey ?? newKey());
  useEffect(() => {
    if (finished.current) return;
    try {
      const hasWork = name || manual || content || groupIds.length || contactIds.length || schedule;
      if (hasWork) localStorage.setItem(draftKey, JSON.stringify({version: 1, step, name, manual, groupIds, contactIds, excluded, selectedTemplate, content, includeOptOut, schedule, submissionKey, ...(savedDraft ? {savedDraft} : {})} satisfies Draft));
      else localStorage.removeItem(draftKey);
      setStorageFailed(false);
    } catch { setStorageFailed(true); }
  }, [draftKey, step, name, manual, groupIds, contactIds, excluded, selectedTemplate, content, includeOptOut, schedule, submissionKey, savedDraft]);
  const discard = () => {
    if (!window.confirm('Discard this browser recovery and start a new campaign? Account-saved drafts will remain in Saved SMS drafts.')) return;
    try {localStorage.removeItem(draftKey);if(accountDraft)localStorage.removeItem(draftKey.slice(0,-accountDraft.id.length-1));} catch {/* The empty form will retry clearing browser storage. */}
    setSavedDraft(null);
    setName(''); setManual(''); setContent(''); setGroupIds([]); setContactIds([]); setExcluded([]);
    setSelectedTemplate('none'); setIncludeOptOut(false); setSchedule(''); setPreview(null); setStep(1); setFurthestStep(1); setSubmissionKey(newKey());
    if (accountDraft) {finished.current = true;navigate('/sms-campaigns/new', {replace: true});}
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
    setValidRecipients(false);setRecipientPreview(null);
    if(!recipients.manual.length&&!contactIds.length&&!groupIds.length){setCheckingRecipients(false);return;}
    setCheckingRecipients(true);
    const timer=window.setTimeout(()=>{
      previewSmsCampaign({content:'Recipient validation',include_opt_out:false,recipients}).then(response=>{
        if(!cancelled){const count=Number((response.preview??response.data)?.sendable_count ?? 0);setValidRecipients(count>0);setRecipientPreview(response.preview??response.data??null);}
      }).catch(()=>{if(!cancelled)toast({title:'Could not validate recipients',description:'Check your connection and try again.',variant:'destructive'});}).finally(()=>{if(!cancelled)setCheckingRecipients(false);});
    },300);
    return()=>{cancelled=true;window.clearTimeout(timer);};
  },[step,recipients,contactIds.length,groupIds.length]);
  const refreshGroups = useCallback(async () => {
    try {
      const response = await api.get<PickableContactGroup[]>('/contact-groups');
      const rows = (response as typeof response & {groups?: PickableContactGroup[]}).groups ?? response.data;
      if (!response.success || !Array.isArray(rows)) throw new Error('Groups unavailable');
      setGroups(rows.map(row => ({...row, id: String(row.id), ...(row.contact_count === undefined ? {} : {contact_count: Number(row.contact_count)})}))); setGroupsLoaded(true);
    } catch {toast({title: 'Groups could not be loaded', description: 'Retry loading groups or enter numbers manually.', variant: 'destructive'});}
  }, []);
  useEffect(() => {
    void refreshGroups();
    void getTemplates('sms').then(response => {const rows = (response as typeof response & {templates?: Array<{id:string;name:string;content:string}>}).templates ?? response.data ?? [];setTemplates(Array.isArray(rows) ? rows.map(row => ({...row, id: String(row.id)})) : []);}).catch(() => toast({title: 'Templates could not be loaded', variant: 'destructive'}));
  }, [refreshGroups]);
  const chooseTemplate=(id:string)=>{setSelectedTemplate(id);if(id==='none')return;const template=templates.find(item=>item.id===id);if(template){setContent(template.content);setPreview(null);}};

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
    if (sending.current || busy || !preview?.sufficient_balance) return;
    sending.current = true;
    setBusy(true);
    try {
      const response = await createSmsCampaign({ name, content, include_opt_out:includeOptOut, recipients, scheduled_at: sendNow ? null : schedule, send_now: sendNow, idempotency_key: submissionKey });
      const campaign = response.campaign ?? response.data?.campaign;
      if (!campaign) throw new Error("Campaign was not created");
      finished.current = true;
      try { localStorage.removeItem(draftKey); } catch { /* The stable submission key prevents duplicate submission if storage is unavailable. */ }
      if (savedDraft) {
        try {await deleteWorkDraft(savedDraft);void queryClient.invalidateQueries({queryKey: ['work-drafts']});}
        catch {toast({title: 'Campaign created; saved draft retained', description: 'The draft could not be removed or was edited in another tab. Its original submission key prevents a duplicate campaign.'});}
      }
      toast({ title: sendNow ? "Your messages are queued" : "Your messages are scheduled", description: "Track sending and delivery progress from your dashboard.", className: "border-emerald-600 bg-emerald-600 text-white" });
      navigate("/dashboard");
    } catch (error) {
      toast({ title: "Campaign was not submitted", description: error instanceof Error ? error.message : "Please try again.", variant: "destructive" });
    } finally { sending.current = false; setBusy(false); }
  };

  useEffect(() => {
    const id=initialGroup.current;
    if (!id || !groups.length) return;
    initialGroup.current=null;
    if (!draft && groups.some(group=>group.id===id && !group.is_virtual && group.contact_count!==0)) {setGroupIds([id]);}
  }, [groups, draft]);
  useEffect(() => {
    // Empty legacy groups named Uncategorized must not survive a restored
    // selection or group shortcut as a visible recipient chip either.
    const empty = new Set(groups.filter(group => group.name.trim().toLowerCase()==='uncategorized' && Number(group.contact_count)===0).map(group=>group.id));
    setGroupIds(previous=>previous.some(id=>empty.has(id))?previous.filter(id=>!empty.has(id)):previous);
  }, [groups]);
  useEffect(() => {
    let cancelled=false;
    setContactsLoading(true);
    const timer=window.setTimeout(()=>{api.get<Array<{id:string;name:string;phone:string}>>('/contacts',{per_page:'50',search:contactSearch}).then(res=>{if(!cancelled)setContacts((res.data??[]).map(row=>({...row,id:String(row.id)})));}).catch(()=>{if(!cancelled)toast({title:'Unable to search contacts',variant:'destructive'});}).finally(()=>{if(!cancelled)setContactsLoading(false);});},250);
    return()=>{cancelled=true;window.clearTimeout(timer);};
  }, [contactSearch]);


  return <DashboardLayout title="Send Messages" subtitle={`Step ${step} of 5: ${smsStepDescriptions[step-1]}`} campaignStep={step}>
    <SmsWizard {...{step,setStep,furthestStep,busy,name,setName,content,setContent,manual,setManual,groupIds,setGroupIds,contactIds,setContactIds,groups,groupsLoaded,refreshGroups,contacts,contactsLoading,contactSearch,setContactSearch,templates,selectedTemplate,chooseTemplate,includeOptOut,setIncludeOptOut,optOutText,optOutLoaded,preview,setPreview,recipientPreview,validRecipients,checkingRecipients,schedule,setSchedule,calculate,submit,excludeRecipient,fitToAvailableCredits,storageFailed}} restored={!!draft} draftActions={<>
      <SaveDraftButton key={savedDraft?.id ?? 'new'} kind="sms" reference={savedDraft} payload={{version:1,step,name,manual,groupIds,contactIds,excluded,selectedTemplate,content,includeOptOut,schedule,submissionKey}} defaultTitle={name.trim() || 'Untitled SMS campaign'} disabled={working || !(name || manual || content || groupIds.length || contactIds.length || schedule)} onSavingChange={setDraftSaving} onSaved={d=>setSavedDraft(draftReference(d))} onExit={()=>navigate('/sms-campaigns')}/>
      <Button type="button" variant="outline" size="sm" disabled={busy} onClick={discard}><RefreshCw className="h-4 w-4"/>Discard draft and start again</Button>
      {accountDraft && savedDraft && accountDraft.version > savedDraft.version && <p role="alert">This account draft changed elsewhere. Your browser edits are kept; saving will not overwrite the newer copy.</p>}
    </>}/>
  </DashboardLayout>;
}
