import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/hooks/useAuth';
import { useWallet } from '@/hooks/useWallet';
import { usePortalOverview } from './GetStartedChecklist';

export function DashboardTour({onSettled}:{onSettled?:()=>void} = {}){
 const {user}=useAuth();const wallet=useWallet();const {data}=usePortalOverview();
 const [step,setStep]=useState<number|null>(null);const [box,setBox]=useState<DOMRect|null>(null);const [cardHeight,setCardHeight]=useState(240);
 const card=useRef<HTMLDivElement>(null);const prior=useRef<HTMLElement|null>(null);const autoChecked=useRef('');
 const key=`sms-ui:${user?.id}:dashboard-tour-seen`;
 const start=()=>{prior.current=document.activeElement as HTMLElement;setStep(0);try{localStorage.setItem(key,'true');}catch{/* Storage unavailable */}};
 const finish=()=>{setStep(null);prior.current?.focus();onSettled?.();};
 useEffect(()=>{const open=()=>start();window.addEventListener('sms:dashboard-tour',open);return()=>window.removeEventListener('sms:dashboard-tour',open);},[key]);
 useEffect(()=>{if(!user||!data||wallet.isLoading||wallet.error||autoChecked.current===key)return;autoChecked.current=key;try{if(localStorage.getItem(key)){onSettled?.();return;}}catch{onSettled?.();return;}start();},[user,data,wallet.isLoading,wallet.error,key,onSettled]);
 useLayoutEffect(()=>{
  if(step===null)return;
  const targets=['[data-tour="credits"]','[data-tour="contacts"]','[data-tour="campaign"]','[data-tour="delivery"]'];
  const choose=()=>Array.from(document.querySelectorAll<HTMLElement>(targets[step])).find(el=>el.getBoundingClientRect().width>0);
  let frame=0;const update=()=>{cancelAnimationFrame(frame);frame=requestAnimationFrame(()=>setBox(choose()?.getBoundingClientRect()??null));};
  choose()?.scrollIntoView({block:'nearest',behavior:'auto'});update();
  const timer=setTimeout(update,200);window.addEventListener('resize',update);window.addEventListener('scroll',update,true);
  const resize=new ResizeObserver(()=>{if(card.current)setCardHeight(card.current.getBoundingClientRect().height);update();});if(card.current)resize.observe(card.current);
  card.current?.focus();return()=>{clearTimeout(timer);cancelAnimationFrame(frame);resize.disconnect();window.removeEventListener('resize',update);window.removeEventListener('scroll',update,true);};
 },[step]);
 if(step===null||!data)return null;
 const steps=[
  ['Check your credits',`You have ${wallet.smsCredits.toLocaleString()} credits at R${wallet.pricePerCredit.toFixed(2)} each; 1 credit sends 1 SMS segment, so buy more before a large send.`],
  ['Add your people',`Import a CSV or add a contact here${data.contacts>0?`; you already have ${data.contacts.toLocaleString()} contacts`:''}.`],
  ['Send a campaign','Choose contacts, write the message, check the cost, then send.'],
  ['Read the result','Delivered means the network confirmed it; Not delivered means that number failed, so open the campaign to see who.'],
 ];
 const width=Math.min(420,window.innerWidth-24);const r=box;
 const beside=!!r&&(r.right+width+24<window.innerWidth||r.left-width-12>=12);
 const left=r&&r.right+width+24<window.innerWidth?r.right+12:r&&r.left-width-12>=12?r.left-width-12:12;
 const top=r?Math.max(12,Math.min(window.innerHeight-cardHeight-12,beside?r.top:r.bottom+cardHeight+12<window.innerHeight?r.bottom+12:r.top-cardHeight-12)):80;
 const trap=(event:React.KeyboardEvent)=>{if(event.key==='Escape'){event.preventDefault();finish();}if(event.key==='Tab'){const focusable=Array.from(card.current?.querySelectorAll<HTMLElement>('a,button')??[]);const first=focusable[0],last=focusable.at(-1);if(event.shiftKey&&(document.activeElement===first||document.activeElement===card.current)){event.preventDefault();last?.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}}};
 return createPortal(<div className="fixed inset-0 z-[100]" aria-label="Dashboard spotlight tour">
  {r?<><div className="absolute inset-x-0 top-0 bg-black/50" style={{height:Math.max(0,r.top-4)}}/><div className="absolute inset-x-0 bottom-0 bg-black/50" style={{top:r.bottom+4}}/><div className="absolute left-0 bg-black/50" style={{top:r.top-4,height:r.height+8,width:Math.max(0,r.left-4)}}/><div className="absolute right-0 bg-black/50" style={{top:r.top-4,height:r.height+8,left:r.right+4}}/><div className="pointer-events-none absolute rounded-xl border-2 border-primary" style={{left:r.left-4,top:r.top-4,width:r.width+8,height:r.height+8}}/></>:<div className="absolute inset-0 bg-black/50"/>}
  <div ref={card} role="dialog" aria-modal="true" aria-labelledby="tour-title" aria-describedby="tour-body" tabIndex={-1} onKeyDown={trap} className="absolute max-h-[calc(100dvh-24px)] overflow-y-auto rounded-xl border bg-card p-5 text-foreground shadow-xl outline-none" style={{left,top,width}}>
   <p className="mb-2 text-xs text-muted-foreground">{step+1} of 4</p><h2 id="tour-title" className="text-lg font-semibold">{steps[step][0]}</h2><p id="tour-body" className="mt-2 text-sm text-muted-foreground">{steps[step][1]}</p>
   {step===0&&<Button asChild className="mt-3"><Link to="/wallet" onClick={finish}>Buy credits</Link></Button>}
   <div className="mt-4 flex justify-between gap-3"><Button variant="ghost" onClick={finish}>Skip tour</Button><Button onClick={()=>step===3?finish():setStep(step+1)}>{step===3?'Done':'Next'}</Button></div>
  </div>
 </div>,document.body);
}
