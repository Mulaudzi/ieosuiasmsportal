import { useState } from 'react';
import { useAuth } from '@/hooks/useAuth';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { CircleHelp } from 'lucide-react';

export function usePortalPreference(name: string) {
  const { user } = useAuth();
  const key = `sms-ui:${user?.id}:${name}`;
  const [values, setValues] = useState<Record<string,boolean>>({});
  let value=values[key];
  if(value===undefined){try{value=localStorage.getItem(key)==='true';}catch{value=false;}}
  return [value, (next: boolean) => { setValues(previous=>({...previous,[key]:next})); try { localStorage.setItem(key, String(next)); } catch { /* Preferences must never block work. */ } }] as const;
}

export function DarkModeControl({checked, onChange}: {checked:boolean;onChange:(value:boolean)=>void}) {
  return <label title="Dark mode" className="flex cursor-pointer items-center gap-2 rounded-lg p-2 text-sm hover:bg-muted"><span>Dark mode</span><Switch aria-label="Dark mode" checked={checked} onCheckedChange={onChange}/></label>;
}

const guides: Record<string,string[]> = {
  Dashboard: ['Choose a reporting period to review SMS activity.', 'Delivered means network-confirmed delivery. Sending complete does not mean every recipient received the SMS.'],
  'SMS Campaigns': ['Create a campaign or use the eye action to view an existing campaign.', 'Open a delivery breakdown bar to see all five delivery outcomes.'],
  Contacts: ['Add contacts or import your contact file using the existing import guide.', 'Groups help select campaign recipients. Opted-out recipients remain excluded from sending.'],
  Templates: ['Create an SMS template with a clear name and reusable message.', 'Select a saved template in the campaign message step, then edit it before sending.'],
  Wallet: ['Your balance is shown in SMS credits. One credit pays for one billable SMS segment.', 'Choose a purchase amount and follow the existing payment flow.'],
  Reports: ['Select a period and switch between Message Volume and Delivery Status.', 'Export the selected reporting period. Missing delivery confirmation is not a confirmed failure.'],
};
const campaignGuides = [
  ['Give this campaign a name so you can find it later.', 'The recipient step becomes available once the name is filled in. Your draft is saved on this browser.'],
  ['Add numbers, contacts or groups. You need at least one valid recipient to continue.', 'Removing a recipient from a campaign does not delete the contact.'],
  ['Write your message or choose a template. Check the character counter and opt-out choice.', 'The estimate includes the enabled footer. Calculate recipients and credits for the final personalised total.'],
  ['Review the campaign name, recipients, message and server-calculated credit total.', 'If credits are insufficient, buy credits or adjust recipients using the existing controls.'],
  ['Queue now or select a schedule. Nothing is sent until you press one of these actions.', 'Use an earlier completed step to review your entries. Changes to recipients or message require a fresh credit review.'],
];
export function Tutorial({title, step}: {title:string;step?:number}) {
  const [,setDismissed] = usePortalPreference(`tutorial:${title}:${step ?? ''}`);
  const [open,setOpen] = useState(false);
  const [index,setIndex] = useState(0);
  const entries = step ? campaignGuides[step-1] : guides[title==='SMS Credits'?'Wallet':title] ?? ['Use the page controls to view and manage this record. For more assistance, open Need help.'];
  const close = () => { setOpen(false); setDismissed(true); };
  if(title==='Dashboard')return <Button variant="ghost" title="Tutorial" aria-label="Tutorial" onClick={()=>window.dispatchEvent(new Event('sms:dashboard-tour'))}><CircleHelp className="h-4 w-4 mr-2"/>Tutorial</Button>;
  return <><Button variant="ghost" title="Tutorial" aria-label="Tutorial" onClick={()=>{setIndex(0);setOpen(true);}}><CircleHelp className="h-4 w-4 mr-2"/>Tutorial</Button>
    <Dialog open={open} onOpenChange={value=>value?setOpen(true):close()}><DialogContent><DialogTitle>{step ? `Step ${step} tutorial` : `${title} tutorial`}</DialogTitle><DialogDescription>{entries[index]}</DialogDescription><div className="flex justify-between gap-2"><Button variant="ghost" onClick={close}>Skip</Button><Button variant="outline" onClick={close}>Close</Button><Button onClick={()=>index+1<entries.length?setIndex(index+1):close()}>{index+1<entries.length?'Next':'Finish'}</Button></div></DialogContent></Dialog>
  </>;
}
