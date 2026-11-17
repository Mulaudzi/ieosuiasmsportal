import { useEffect, useRef, useState } from 'react';
import { api } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export function ExistingSmsCampaignPicker({onSelect, disabled = false}: {
  onSelect: (campaign: {id: string; name: string; content: string}) => void;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false), [search, setSearch] = useState('');
  const [rows, setRows] = useState<Array<{id: string; name: string; state: string}>>([]);
  const [loading, setLoading] = useState(false), [picking, setPicking] = useState(false), [error, setError] = useState('');
  const choosing = useRef(false), generation = useRef(0);
  useEffect(() => {
    if (!open) return;
    const current = ++generation.current;
    setLoading(true); setError('');
    const timer = window.setTimeout(async () => {
      try {
        const response = await api.get<{campaigns: typeof rows}>('/sms/campaigns-v2', {search, range: 'all', per_page: '100'});
        if (current !== generation.current) return;
        const data = (response.data ?? response) as {campaigns?: typeof rows};
        if (!response.success || !Array.isArray(data.campaigns)) throw new Error();
        setRows(data.campaigns.map(row => ({...row, id: String(row.id)})));
      } catch {if (current === generation.current) {setRows([]); setError('Campaigns could not be loaded. Close and reopen this dialog to try again.');}}
      finally {if (current === generation.current) setLoading(false);}
    }, 250);
    return () => {generation.current++; window.clearTimeout(timer);};
  }, [open, search]);
  const select = async (id: string) => {
    if (choosing.current) return;
    choosing.current = true; setPicking(true); setError('');
    try {
      const response = await api.get<{campaign: {id: string; name: string; content: string}}>(`/sms/campaigns-v2/${id}`);
      const data = (response.data ?? response) as {campaign?: {id: string; name: string; content: string}};
      if (!response.success || String(data.campaign?.id) !== id || typeof data.campaign.content !== 'string') throw new Error();
      onSelect({...data.campaign, id}); setOpen(false);
    } catch {setError('This campaign could not be opened. Nothing has been copied or sent. Try again.');}
    finally {choosing.current = false; setPicking(false);}
  };
  return <>
    <Button type="button" variant="outline" disabled={disabled} onClick={() => setOpen(true)}>Choose an existing campaign</Button>
    <Dialog open={open} onOpenChange={next => {if (!choosing.current) setOpen(next);}}><DialogContent className="max-h-[90dvh] overflow-y-auto">
      <DialogHeader><DialogTitle>Start from an existing SMS campaign</DialogTitle><DialogDescription>Copy its name and message into a new campaign. The original campaign, recipients and history stay unchanged. Nothing is sent until you review and confirm.</DialogDescription></DialogHeader>
      <Label htmlFor="reuse-campaign-search">Find a campaign</Label><Input id="reuse-campaign-search" value={search} onChange={e => setSearch(e.target.value)} disabled={picking} placeholder="Search campaign names"/>
      {error && <p role="alert">{error}</p>}
      {loading ? <p role="status">Loading campaigns...</p> : <div className="max-h-72 space-y-2 overflow-auto">{rows.map(row => <Button key={row.id} variant="outline" className="h-auto min-h-12 w-full justify-start whitespace-normal text-left" disabled={picking} onClick={() => void select(row.id)}>{row.name} — {row.state}</Button>)}{!rows.length && !error && <p>No matching campaigns. You can name a new campaign instead.</p>}</div>}
      <p className="text-xs text-muted-foreground">Showing up to 100 matches. Search to find an older campaign. To continue a saved draft, open Saved SMS drafts on the SMS Campaigns page.</p>
    </DialogContent></Dialog>
  </>;
}
