import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, ArrowRightLeft, Mail, RefreshCw, ShieldCheck } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { toast } from '@/hooks/use-toast';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CreditTransferDialogBody } from './CreditTransferDialogBody';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CreditTransferQuote, CreditTransferReceipt, creditTransfersApi, customerTransfersApi } from '@/lib/credit-transfers';

export function CustomerCreditTransfer({availableCredits,compact=false,disabled=false}:{availableCredits:number;compact?:boolean;disabled?:boolean}) {
  const {user}=useAuth();const client=useQueryClient();
  const history=useQuery({queryKey:['credit-transfer-history',user?.id,1],enabled:!!user,retry:false,queryFn:()=>creditTransfersApi.customerHistory()});
  const [open,setOpen]=useState(false),[email,setEmail]=useState(''),[credits,setCredits]=useState('');
  const [quote,setQuote]=useState<CreditTransferQuote|null>(null),[receipt,setReceipt]=useState<CreditTransferReceipt|null>(null);
  const [code,setCode]=useState(''),[confirmed,setConfirmed]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState('');
  const [uncertain,setUncertain]=useState(false),[codeRequested,setCodeRequested]=useState(false),[now,setNow]=useState(Date.now());
  const request=useRef(crypto.randomUUID());const storageKey='sms-credit-transfer:pending:customer:'+user?.id;
  const pending=()=>{try{return sessionStorage.getItem(storageKey);}catch{return null;}};
  const savePending=(id:string|null)=>{try{if(id){sessionStorage.setItem(storageKey,id);return sessionStorage.getItem(storageKey)===id;}sessionStorage.removeItem(storageKey);return true;}catch{return false;}};
  const complete=(result:CreditTransferReceipt)=>{
    setReceipt(result);setUncertain(false);savePending(null);setCode('');
    for(const key of ['wallet','wallet-stats','wallet-activity','credit-transfer-history','dashboard-credit-balance'])void client.invalidateQueries({queryKey:[key]});
    window.dispatchEvent(new Event('sms:notifications-refresh'));
    toast({title:'Credits transferred',description:`${result.credits.toLocaleString()} credits transferred. No fee charged.`});
  };
  const check=async(id:string)=>{
    setBusy(true);setError('');
    try{const result=await customerTransfersApi.status(id);
      if(result.status==='completed'&&result.transfer)complete(result.transfer);
      else if(result.quote){setQuote(result.quote);setEmail(result.quote.destination.email);setCredits(String(result.quote.credits));
        if(result.new_review_allowed){setUncertain(false);savePending(null);setError('This review expired without completing. Go back to review a new transfer.');}
        else{setUncertain(true);setError('No completed result is recorded yet. Check again or retry this same transfer only.');}}
    }catch(e){setUncertain(true);setError(e instanceof Error?e.message:'Could not check the result. Keep this reference and contact support.');}
    finally{setBusy(false);setCode('');}
  };
  const start=()=>{
    setOpen(true);setError('');setReceipt(null);setCode('');
    const id=pending();if(id){setUncertain(true);void check(id);return;}if(uncertain)return;
    setQuote(null);setEmail('');setCredits('');setConfirmed(false);setCodeRequested(false);request.current=crypto.randomUUID();
  };
  useEffect(()=>{if(!open||!quote)return;setNow(Date.now());const timer=setInterval(()=>setNow(Date.now()),1000);return()=>clearInterval(timer);},[open,quote]);
  const review=async()=>{
    setBusy(true);setError('');
    try{setQuote(await customerTransfersApi.quote({recipient_email:email.trim(),credits:Number(credits),authorization_confirmed:true,request_id:request.current}));setConfirmed(false);setNow(Date.now());}
    catch(e){setError(e instanceof Error?e.message:'Could not review the transfer.');}finally{setBusy(false);}
  };
  const sendCode=async()=>{
    if(!quote)return;setBusy(true);setError('');
    try{await customerTransfersApi.challenge(quote.id);setCodeRequested(true);toast({title:'Check your email',description:'Enter the code sent to your verified account email.'});}
    catch(e){setError(e instanceof Error?e.message:'Check your inbox; the verification email could not be confirmed.');}finally{setBusy(false);}
  };
  const commit=async()=>{
    if(!quote||!confirmed||!/^[0-9]{6}$/.test(code))return;
    if(!savePending(quote.id)){setError('Allow session storage before transferring so interrupted requests can be checked safely. No credits moved.');return;}
    setBusy(true);setError('');
    try{complete(await customerTransfersApi.commit(quote.id,code));}
    catch(e){const status=typeof e==='object'&&e!==null&&'status'in e?Number(e.status):0;
      setError(e instanceof Error?e.message:'The result is uncertain. Check this same transfer before any further action.');
      if(!uncertain&&status>=400&&status<500)savePending(null);else setUncertain(true);
    }finally{setBusy(false);setCode('');}
  };
  const expired=!!quote&&now>=quote.expires_at*1000;
  const valid=/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())&&/^[1-9][0-9]{0,6}$/.test(credits)&&Number(credits)<=1000000;
  return <section aria-label="Transfer your SMS credits" className={compact?'max-w-full':'mt-6 rounded-xl border bg-card p-5'}>
    <div className="flex flex-wrap items-center justify-between gap-3">{!compact&&<div><h2 className="text-lg font-semibold">Transfer SMS credits</h2><p className="mt-1 max-w-xl text-sm text-muted-foreground">Send your available credits to another verified IEOSUIA SMS account. No fee. Credits reserved for SMS cannot be transferred.</p></div>}<Button aria-label="Transfer credits" size={compact?'sm':'default'} variant={compact?'outline':'default'} onClick={start} disabled={disabled||!history.data?.enabled}><ArrowRightLeft className="mr-2 h-4 w-4"/>{compact?<span>Transfer<span className="hidden sm:inline"> credits</span></span>:'Transfer credits'}</Button></div>
    {history.isPending&&<p role="status" className="mt-2 text-sm">Checking transfer availability…</p>}{history.error&&<p role="alert" className="mt-2 text-sm">Transfers are unavailable. <Button variant="link" onClick={()=>void history.refetch()}>Retry</Button></p>}
    {history.data&&!history.data.enabled&&<p className="mt-2 text-sm text-muted-foreground">Transfers are not activated yet.</p>}
    <Dialog open={open} onOpenChange={next=>{if(!busy){setOpen(next);setCode('');}}}><DialogContent className="flex max-h-[90dvh] w-[calc(100%-2rem)] max-w-lg flex-col overflow-hidden">
      <DialogHeader><DialogTitle>{receipt?'Transfer completed':quote?'Review credit transfer':'Transfer your credits'}</DialogTitle><DialogDescription>Only your own available credits can be transferred. Check the recipient carefully; completed transfers cannot be undone here.</DialogDescription></DialogHeader>
      <CreditTransferDialogBody key={receipt?'receipt':quote?.id??'form'}>
        {error&&<p role="alert" className="rounded-lg border border-destructive/30 p-3 text-sm text-destructive">{error}</p>}
        {receipt?<><p className="text-xl font-semibold">{receipt.credits.toLocaleString()} credits transferred</p><p className="break-all">To: {receipt.destination_email}</p><p className="break-all text-xs">{receipt.reference}</p><p className="text-sm">Reserved credits were unchanged. Both wallet histories were updated.</p></>:quote?<>
          <div className="rounded-xl border p-4"><p className="text-xs text-muted-foreground">From your account</p><p className="break-all text-sm">{quote.source.email}</p><p className="mt-3 text-xs text-muted-foreground">Recipient</p><strong className="break-words">{quote.destination.name}</strong><p className="break-all text-sm">{quote.destination.email}</p><p className="mt-3 text-2xl font-bold">{quote.credits.toLocaleString()} SMS credits</p><p className="mt-1 text-sm">Your available credits after: {quote.source_available_after.toLocaleString()} (estimate)</p><p className="text-xs text-muted-foreground">No fee. Availability is checked again on confirmation.</p></div>
          <p className="break-all text-xs text-muted-foreground">TRF-{quote.id.toUpperCase()}</p><p className="text-xs">{expired?'Review expired. Check its status before starting again.':`Review expires in ${Math.max(0,Math.ceil((quote.expires_at*1000-now)/60000))} minute(s).`}</p>
          <label className="flex items-start gap-2 text-sm"><input type="checkbox" disabled={busy||expired} checked={confirmed} onChange={e=>setConfirmed(e.target.checked)}/><span>I checked the recipient and authorize this exact transfer.</span></label>
          <Button variant="outline" disabled={busy||expired||codeRequested} onClick={()=>void sendCode()}><Mail className="mr-2 h-4 w-4"/>{codeRequested?'Code requested — check your email':'Email me a verification code'}</Button>
          <label className="block space-y-2 text-sm">Verification code<Input aria-label="Verification code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} disabled={busy||expired} onChange={e=>setCode(e.target.value)} className="text-base sm:text-sm"/></label>
          <p className="flex items-start gap-2 text-xs text-muted-foreground"><ShieldCheck className="h-4 w-4 shrink-0"/>The code goes to your verified account email and applies only to this recipient and amount. Never share it.</p>
        </>:<>
          <p className="rounded-lg bg-muted p-3 text-sm"><span className="block break-all">From: {user?.email}</span><strong>{availableCredits.toLocaleString()} available credits</strong></p>
          <label className="block space-y-2 text-sm">Recipient account email<Input aria-label="Recipient account email" type="email" maxLength={254} value={email} disabled={busy} onChange={e=>{setEmail(e.target.value);request.current=crypto.randomUUID();}} placeholder="Their verified SMS Portal account email" className="text-base sm:text-sm"/></label>
          <p className="text-xs text-muted-foreground">Ask for their exact email. You can check their name at review; their balance and contacts stay private.</p>
          <label className="block space-y-2 text-sm">Credits to transfer<Input aria-label="Credits to transfer" inputMode="numeric" value={credits} disabled={busy} onChange={e=>{setCredits(e.target.value);request.current=crypto.randomUUID();}} placeholder="Whole SMS credits" className="text-base sm:text-sm"/></label>
        </>}
      </CreditTransferDialogBody>
      <DialogFooter className="shrink-0 flex-row flex-wrap items-center justify-between gap-2 border-t pt-3 sm:justify-between">
        {receipt?<Button className="ml-auto" onClick={()=>setOpen(false)}>Done</Button>:<><Button variant="outline" disabled={busy||uncertain} onClick={()=>{if(quote){setQuote(null);setConfirmed(false);setCodeRequested(false);setError('');request.current=crypto.randomUUID();}else setOpen(false);}}><ArrowLeft className="mr-2 h-4 w-4"/>{quote?'Back':'Cancel'}</Button><div className="ml-auto flex flex-wrap justify-end gap-2">{quote&&(uncertain||expired)&&<Button variant="outline" disabled={busy} onClick={()=>void check(quote.id)}><RefreshCw className="mr-2 h-4 w-4"/>Check status</Button>}{quote?<Button disabled={busy||expired||!confirmed||!/^[0-9]{6}$/.test(code)} onClick={()=>void commit()}>{busy?'Checking…':uncertain?'Retry same transfer':'Confirm transfer'}</Button>:<Button disabled={busy||!valid} onClick={()=>void review()}>{busy?'Checking…':'Review transfer'}<ArrowRight className="ml-2 h-4 w-4"/></Button>}</div></>}
      </DialogFooter>
    </DialogContent></Dialog>
  </section>;
}
