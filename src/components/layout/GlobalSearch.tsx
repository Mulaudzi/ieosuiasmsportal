import { useEffect, useId, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight, Search } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/hooks/useAuth';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface Result {type:'campaign'|'contact'|'template';id:string;label:string;search:string}
const actions=[{label:'Send messages',path:'/sms-campaigns/new'},{label:'Contacts and tags',path:'/contacts'},{label:'Buy or transfer credits',path:'/wallet'},{label:'Account security',path:'/settings?tab=security'}];
export function GlobalSearch(){
 const {user}=useAuth();const userId=user?.id;const navigate=useNavigate();const id=useId();
 const [open,setOpen]=useState(false),[query,setQuery]=useState(''),[results,setResults]=useState<Result[]>([]),[index,setIndex]=useState(-1),[loading,setLoading]=useState(false),[error,setError]=useState(''),[retry,setRetry]=useState(0);
 const input=useRef<HTMLInputElement>(null);
 useEffect(()=>{const key=(e:KeyboardEvent)=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();setOpen(value=>!value);}};window.addEventListener('keydown',key);return()=>window.removeEventListener('keydown',key);},[]);
 useEffect(()=>{setQuery('');setResults([]);setOpen(false);},[user?.id]);
 useEffect(()=>{
  let active=true;setResults([]);setIndex(-1);setError('');setLoading(false);
  if(!open||!userId||query.trim().length<2)return;
  setLoading(true);
  const timer=setTimeout(async()=>{try{
   const r=await api.get<{results:Result[]}>('/search',{q:query.trim()});const rows=((r.data??r)as{results?:Result[]}).results;
   if(!r.success||!Array.isArray(rows))throw Error('Search unavailable');
   if(active)setResults(rows.filter(row=>['campaign','contact','template'].includes(row.type)&&/^\d+$/.test(row.id)&&typeof row.label==='string'&&typeof row.search==='string').slice(0,15));
  }catch{if(active)setError('Search is unavailable. Please retry.');}finally{if(active)setLoading(false);}},250);
  return()=>{active=false;clearTimeout(timer);};
 },[open,query,userId,retry]);
 const items=query.trim().length<2?actions.map(item=>({label:item.label,path:item.path})):results.map(row=>({label:row.label,path:row.type==='campaign'?`/sms-campaigns/${row.id}`:`/${row.type==='contact'?'contacts':'templates'}?search=${encodeURIComponent(row.search)}`,kind:row.type}));
 const choose=(path:string)=>{setOpen(false);setQuery('');navigate(path);};
 return <>
  <Button variant="ghost" aria-label="Search portal" onClick={()=>setOpen(true)} className="gap-2 px-2 sm:w-56 sm:justify-start sm:border sm:bg-muted/40 xl:w-96"><Search className="h-4 w-4 shrink-0"/><span className="hidden truncate text-sm text-muted-foreground sm:inline">Search campaigns, contacts, templates…</span><kbd className="ml-auto hidden text-[10px] text-muted-foreground xl:inline">Ctrl K</kbd></Button>
  <Dialog open={open} onOpenChange={setOpen}><DialogContent className="flex max-h-[85dvh] w-[calc(100%-1rem)] max-w-xl flex-col overflow-hidden" onOpenAutoFocus={event=>{event.preventDefault();input.current?.focus();}}>
   <DialogHeader><DialogTitle>Search your portal</DialogTitle><DialogDescription>Find your campaigns, contacts and saved templates. Use arrow keys and Enter to choose.</DialogDescription></DialogHeader>
   <Input ref={input} aria-label="Search portal content" role="combobox" aria-expanded={open} aria-controls={id} aria-autocomplete="list" aria-activedescendant={index>=0?`${id}-${index}`:undefined} autoComplete="off" maxLength={80} placeholder="Type a name, campaign or phone number" value={query} onChange={event=>setQuery(event.target.value)} onKeyDown={event=>{
    if(event.key==='ArrowDown'){event.preventDefault();setIndex(old=>items.length?(old+1)%items.length:-1);}
    if(event.key==='ArrowUp'){event.preventDefault();setIndex(old=>items.length?(old<=0?items.length-1:old-1):-1);}
    if(event.key==='Enter'&&index>=0&&items[index]){event.preventDefault();choose(items[index].path);}
   }}/>
   {loading&&<p role="status" className="text-sm text-muted-foreground">Finding matches…</p>}
   {error&&<p role="alert" className="text-sm">{error}<Button variant="link" onClick={()=>setRetry(value=>value+1)}>Retry search</Button></p>}
   <ul id={id} role="listbox" aria-label="Search suggestions" className="min-h-0 overflow-y-auto rounded-xl border">{items.map((item,i)=><li key={item.path} id={`${id}-${i}`} role="option" aria-selected={index===i}><button type="button" onMouseEnter={()=>setIndex(i)} onClick={()=>choose(item.path)} className={`flex w-full items-center justify-between gap-2 p-3 text-left text-sm ${index===i?'bg-primary/10':'hover:bg-muted'}`}><span className="min-w-0 break-words">{item.label}{'kind'in item&&<span className="ml-2 text-xs text-muted-foreground">{String(item.kind)}</span>}</span><ArrowRight aria-hidden="true" className="h-4 w-4 shrink-0"/></button></li>)}</ul>
   {!loading&&!error&&query.trim().length>=2&&!items.length&&<p role="status" className="text-sm text-muted-foreground">No matches. Try a different name or number.</p>}
   {query.trim().length<2&&<p className="text-xs text-muted-foreground">Type at least two characters for suggestions, or choose a shortcut.</p>}
  </DialogContent></Dialog>
 </>;
}
