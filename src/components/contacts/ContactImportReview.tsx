import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { importContactValue, importEmailValid, reviewImportContact, type ImportContact, type ImportDecision } from '@/lib/contact-import-review';

interface Props {
  contacts: ImportContact[];
  rowNumbers: number[];
  decisions: Record<number, ImportDecision>;
  onChange: (decisions: Record<number, ImportDecision>) => void;
}

export function ContactImportReview({ contacts, rowNumbers, decisions, onChange }: Props) {
  const issues=useMemo(()=>contacts.flatMap((contact,index)=>reviewImportContact(contact).status==='review'?[{contact,index}]:[]),[contacts]);
  const [edits,setEdits]=useState<Record<number,{phone:string;email:string}>>({});
  const [selected,setSelected]=useState<Set<number>>(new Set());
  const [filter,setFilter]=useState('pending');const [page,setPage]=useState(0);const [search,setSearch]=useState('');
  const decisionFor=(issue:typeof issues[number],action:ImportDecision['action']='accept'):ImportDecision=>({action,original:issue.contact.phone??'',originalEmail:issue.contact.email??'',value:edits[issue.index]?.phone??decisions[issue.index]?.value??issue.contact.phone??'',email:edits[issue.index]?.email??decisions[issue.index]?.email??issue.contact.email??''});
  const statusFor=(issue:typeof issues[number])=>reviewImportContact(issue.contact,decisions[issue.index]).status;
  const pending=issues.filter(issue=>statusFor(issue)==='review').length;
  const accepted=issues.filter(issue=>statusFor(issue)==='ready').length;
  const rejected=issues.filter(issue=>statusFor(issue)==='rejected').length;
  const filtered=issues.filter(issue=>(filter==='all'||(filter==='pending'?statusFor(issue)==='review':statusFor(issue)===filter))&&`${rowNumbers[issue.index]} ${issue.contact.name??''} ${issue.contact.phone??''} ${issue.contact.email??''}`.toLowerCase().includes(search.toLowerCase()));
  const pages=Math.max(1,Math.ceil(filtered.length/25));const safePage=Math.min(page,pages-1);
  useEffect(()=>{setPage(0);setSelected(new Set());},[filter,search]);
  useEffect(()=>{setEdits({});setSelected(new Set());setPage(0);},[contacts]);
  const apply=(indexes:Set<number>,action:ImportDecision['action'])=>{
    const next={...decisions};
    issues.filter(issue=>indexes.has(issue.index)).forEach(issue=>{
      const decision=decisionFor(issue,action);
      if(action==='reject'||reviewImportContact(issue.contact,decision).status==='ready')next[issue.index]=decision;
    });
    onChange(next);
  };
  if(!issues.length)return null;
  const chosen=filtered.filter(issue=>selected.has(issue.index));
  const canAccept=chosen.some(issue=>reviewImportContact(issue.contact,decisionFor(issue)).status==='ready');
  const allSelected=filtered.length>0&&filtered.every(issue=>selected.has(issue.index));
  return <section aria-label="Contact details needing review" className="min-w-0 space-y-3 rounded-lg border border-amber-200 p-3">
    <div><h3 className="font-semibold">Only these contacts need a choice</h3><p className="text-sm text-muted-foreground">Safe formatting and missing leading zeros are already fixed. We never guess missing digits or choose between multiple numbers.</p><p role="status" className="mt-1 text-sm">{pending} awaiting your choice · {accepted} corrections accepted · {rejected} rows rejected</p></div>
    <div className="grid gap-2 sm:grid-cols-2">
      <div><label className="text-xs" htmlFor="import-issue-filter">Review filter</label><select id="import-issue-filter" className="h-10 w-full rounded-md border bg-background px-2 text-sm" value={filter} onChange={event=>setFilter(event.target.value)}><option value="pending">Needs review ({pending})</option><option value="ready">Resolved ({accepted})</option><option value="rejected">Rejected ({rejected})</option><option value="all">All flagged ({issues.length})</option></select></div>
      <div><label className="text-xs" htmlFor="import-issue-search">Find a contact or worksheet row</label><Input id="import-issue-search" value={search} onChange={event=>setSearch(event.target.value)} placeholder="Name, number, email or row"/></div>
    </div>
    {filtered.length>0&&<><div className="flex items-center gap-2"><Checkbox id="select-review-numbers" checked={allSelected?true:chosen.length?'indeterminate':false} onCheckedChange={checked=>setSelected(checked===true?new Set(filtered.map(issue=>issue.index)):new Set())}/><label htmlFor="select-review-numbers" className="text-sm">Select all {filtered.length} matching rows</label></div><div className="flex flex-wrap gap-2"><Button size="sm" variant="outline" disabled={!canAccept} onClick={()=>apply(new Set(chosen.map(issue=>issue.index)),'accept')}>Accept selected ({chosen.length})</Button><Button size="sm" variant="outline" disabled={!chosen.length} onClick={()=>apply(new Set(chosen.map(issue=>issue.index)),'reject')}>Skip selected ({chosen.length})</Button></div></>}
    {!filtered.length&&<p className="rounded-md bg-muted/50 p-3 text-sm">{pending===0&&filter==='pending'?'All checks are resolved. You can import your ready contacts below.':'No contacts match this filter.'}</p>}
    <div className="space-y-3">{filtered.slice(safePage*25,(safePage+1)*25).map(issue=>{
      const row=rowNumbers[issue.index]??issue.index+2;const draft=decisionFor(issue);const ready=reviewImportContact(issue.contact,draft).status==='ready';const review=reviewImportContact(issue.contact,decisions[issue.index]);
      const edit=(field:'phone'|'email',value:string)=>{setEdits(previous=>({...previous,[issue.index]:{phone:draft.value??'',email:draft.email??'',[field]:value}}));const next={...decisions};delete next[issue.index];onChange(next);};
      const canKeepEmail=Boolean(importContactValue(draft.email??''))&&importEmailValid(importContactValue(draft.email??''));
      return <div key={issue.index} data-review-row={row} className="min-w-0 space-y-2 rounded-md border bg-card p-3">
        <div className="flex items-center gap-2"><Checkbox aria-label={`Select row ${row}`} checked={selected.has(issue.index)} onCheckedChange={checked=>setSelected(previous=>{const next=new Set(previous);if(checked===true)next.add(issue.index);else next.delete(issue.index);return next;})}/><span className="text-sm font-medium">Row {row}{issue.contact.name?` · ${issue.contact.name}`:''}</span></div>
        <p className="break-all text-xs text-muted-foreground">Original number: {issue.contact.phone||'Not provided'}</p>
        <div className="grid min-w-0 gap-3 sm:grid-cols-2"><div className="min-w-0"><label className="text-xs" htmlFor={`phone-proposal-${issue.index}`}>Phone number</label><Input id={`phone-proposal-${issue.index}`} aria-label={`Proposed correction for row ${row}`} type="tel" maxLength={200} value={draft.value??''} onChange={event=>edit('phone',event.target.value)} placeholder="Complete mobile number"/></div><div className="min-w-0"><label className="text-xs" htmlFor={`email-proposal-${issue.index}`}>Email (optional)</label><Input id={`email-proposal-${issue.index}`} aria-label={`Email correction for row ${row}`} maxLength={254} value={draft.email??''} onChange={event=>edit('email',event.target.value)}/></div></div>
        <p className="text-xs text-muted-foreground">{review.status==='ready'?(review.emailOnly?'Resolved: keep email only; no SMS will be sent to this contact.':'Resolved: corrected details are ready to import.'):review.status==='rejected'?'Skipped from this import. Existing saved contacts are not deleted.':review.reason}</p>
        <div className="flex flex-wrap gap-2"><Button size="sm" variant="outline" aria-label={`Accept correction for row ${row}`} disabled={!ready} onClick={()=>apply(new Set([issue.index]),'accept')}>Use corrected details</Button>{canKeepEmail&&<Button size="sm" variant="outline" onClick={()=>apply(new Set([issue.index]),'email_only')}>Keep email only</Button>}<Button size="sm" variant="outline" aria-label={`Reject row ${row}`} onClick={()=>apply(new Set([issue.index]),'reject')}>Skip contact</Button></div>
      </div>;
    })}</div>
    {pages>1&&<div className="flex items-center justify-between gap-2 text-sm"><Button size="sm" variant="outline" disabled={safePage===0} onClick={()=>setPage(safePage-1)}>Previous rows</Button><span>{safePage+1} / {pages}</span><Button size="sm" variant="outline" disabled={safePage+1===pages} onClick={()=>setPage(safePage+1)}>Next rows</Button></div>}
    {pending>0&&<p className="text-xs text-muted-foreground">Fix or skip the remaining contacts. Skipping affects this import only; saved contacts are never deleted.</p>}
  </section>;
}
