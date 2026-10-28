import {useRef, useState} from 'react';
import {useQueryClient} from '@tanstack/react-query';
import {Button} from '@/components/ui/button';
import {Input} from '@/components/ui/input';
import {Label} from '@/components/ui/label';
import {Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle} from '@/components/ui/dialog';
import {DraftKind, DraftPayloads, DraftReference, saveWorkDraft, WorkDraft} from '@/lib/work-drafts';
import {toast} from '@/hooks/use-toast';
interface Props<K extends DraftKind> {
  kind: K; payload: DraftPayloads[K]; reference?: DraftReference | null; defaultTitle: string;
  disabled?: boolean; onSaved: (draft: WorkDraft<K>) => void; onExit?: () => void; onSavingChange?: (saving: boolean) => void;
}
export function SaveDraftButton<K extends DraftKind>({kind, payload, reference, defaultTitle, disabled, onSaved, onExit, onSavingChange}: Props<K>) {
  const [open, setOpen] = useState(false), [title, setTitle] = useState(''), [saving, setSaving] = useState(false), [error, setError] = useState('');
  const attemptId = useRef(crypto.randomUUID());const client = useQueryClient();
  const save = async (exit: boolean) => {
    if (saving || !title.trim()) return;
    setSaving(true);onSavingChange?.(true);setError('');
    try {
      const sessionKey = 'submissionKey' in payload ? payload.submissionKey : payload.requestKey;
      // Existing stable form keys survive refresh, including a lost first-save response.
      const stableId = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(sessionKey) ? sessionKey.toLowerCase() : attemptId.current;
      const saved = await saveWorkDraft(reference?.id ?? stableId, kind, title.trim(), payload, reference?.version ?? 0);
      onSaved(saved);void client.invalidateQueries({queryKey: ['work-drafts']});void client.invalidateQueries({queryKey: ['work-draft']});
      setOpen(false);toast({title: 'Draft saved', description: `Resume it from ${kind === 'sms' ? 'SMS Campaigns' : 'Wallet'}. Nothing has been sent or paid.`});
      if (exit) onExit?.();
    } catch (e) {setError(e instanceof Error ? e.message : 'Could not save your draft. Keep this form open and retry.');}
    finally {setSaving(false);onSavingChange?.(false);}
  };
  return <><Button type="button" variant="outline" disabled={disabled || saving} onClick={() => {setTitle(reference?.title || defaultTitle);setError('');setOpen(true);}}>Save draft</Button>
    <Dialog open={open} onOpenChange={v => {if (!saving) setOpen(v);}}><DialogContent className="max-w-md">
      <DialogHeader><DialogTitle>Save for later</DialogTitle><DialogDescription>Save to your account and resume from another browser after signing in. This will not send an SMS, start a payment or reserve credits.</DialogDescription></DialogHeader>
      <Label htmlFor={`draft-name-${kind}`}>Draft name</Label><Input id={`draft-name-${kind}`} autoFocus maxLength={100} value={title} disabled={saving} onChange={e => setTitle(e.target.value)}/>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <div className="flex flex-wrap gap-2"><Button disabled={saving || !title.trim()} onClick={() => void save(false)}>{saving ? 'Saving draft...' : 'Save draft'}</Button>{onExit && <Button variant="secondary" disabled={saving || !title.trim()} onClick={() => void save(true)}>Save and exit</Button>}<Button variant="ghost" disabled={saving} onClick={() => setOpen(false)}>Cancel</Button></div>
    </DialogContent></Dialog>
  </>;
}
