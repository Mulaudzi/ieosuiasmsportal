import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { toast } from '@/hooks/use-toast';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export function SmsOptOutSettings() {
  const [text,setText]=useState('');
  const [loaded,setLoaded]=useState(false);
  const [saving,setSaving]=useState(false);
  useEffect(()=>{api.get<{opt_out_text:string}>('/settings/sms').then(r=>{
    const data=(r.data??r) as {opt_out_text:string};setText(data.opt_out_text);setLoaded(true);
  }).catch(()=>toast({title:'Unable to load opt-out text',description:'Reload Settings to try again.',variant:'destructive'}));},[]);
  const save=async()=>{setSaving(true);try{await api.put('/settings/sms',{opt_out_text:text});toast({title:'Opt-out text saved'});}catch(e){toast({title:'Unable to save opt-out text',description:e instanceof Error?e.message:'Please retry.',variant:'destructive'});}finally{setSaving(false);}};
  return <div className="space-y-2"><Label htmlFor="optOutText">Default Opt-Out Text</Label><Textarea id="optOutText" value={text} onChange={e=>setText(e.target.value)} maxLength={500} rows={2} disabled={!loaded||saving}/><p className="text-xs text-muted-foreground">Added when Include opt-out text is enabled for a new campaign. It counts toward message length and credits. Existing campaigns are unchanged.</p><Button onClick={()=>void save()} disabled={!loaded||saving||!text.trim()}>{saving?'Saving…':'Save opt-out text'}</Button></div>;
}
