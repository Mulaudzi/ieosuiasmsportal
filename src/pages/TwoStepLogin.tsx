import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { safeAuthDestination } from '@/lib/central-auth';
import { Logo } from '@/components/layout/Logo';

export default function TwoStepLogin(){
 const restart=new URLSearchParams(window.location.search).get('account_type')==='admin'?'/guymhan/login':'/login';
 const [challenge]=useState(()=>new URLSearchParams(window.location.hash.slice(1)).get('challenge')??'');
 const [code,setCode]=useState(''),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 useEffect(()=>{window.history.replaceState({},document.title,window.location.pathname);},[]);
 const verify=async()=>{setBusy(true);setError('');try{
  const r=await fetch('/api/auth/two-step/verify',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({challenge,code:code.trim()})});
  const result=await r.json();const data=result.data??result;
  if(!r.ok||!result.success||typeof data.token!=='string'||!data.user)throw Error(result.message||'Verification could not finish. Try again or start sign-in again.');
  if(localStorage.getItem('ieosuia_explicit_logout'))throw Error('You signed out. Start secure sign-in again.');
  localStorage.setItem('auth_token',data.token);localStorage.setItem('auth_user',JSON.stringify(data.user));localStorage.setItem('auth_token_issued',String(Date.now()));
  const next=sessionStorage.getItem('redirectAfterLogin');sessionStorage.removeItem('redirectAfterLogin');
  window.location.replace(data.user.role==='admin'?'/guymhan':safeAuthDestination(next));
 }catch(e){setCode('');setError(e instanceof Error?e.message:'Verification is temporarily unavailable.');}finally{setBusy(false);}};
 const valid=/^[0-9]{6}$/.test(code.trim())||/^[a-f0-9]{20}$/i.test(code.trim().replace(/-/g,''));
 return <main className="grid min-h-[100dvh] place-items-center bg-slate-950 p-4 text-white"><section className="w-full max-w-md space-y-5 rounded-2xl border border-slate-700 bg-slate-900 p-5 sm:p-8"><div className="mb-6 flex justify-center"><Logo variant="navy" size="lg" linkTo="" /></div><h1 className="text-xl font-semibold">Finish SMS Portal sign-in</h1><p className="text-sm text-slate-300">Enter the six-digit code sent to your verified email. It expires in 10 minutes. You can use a saved recovery code instead.</p>{!challenge||!/^[a-f0-9]{64}$/.test(challenge)?<p role="alert">This sign-in check expired or was refreshed. Start secure sign-in again.</p>:<form className="space-y-4" onSubmit={event=>{event.preventDefault();void verify();}}><label className="block text-sm">Verification or recovery code<Input className="mt-2 bg-slate-800 text-base text-white" aria-label="Sign-in verification code" value={code} onChange={event=>setCode(event.target.value)} inputMode="text" autoComplete="one-time-code" maxLength={24} autoFocus/></label><Button disabled={busy||!valid} className="w-full">{busy?'Checking…':'Verify and sign in'}</Button></form>}{error&&<p role="alert" className="text-sm text-red-300">{error}</p>}<p className="text-xs text-slate-400">No email? Check spam. Wait at least one minute before starting sign-in again for a new code. This check applies only to the SMS Portal.</p><a href={restart} className="block text-sm text-blue-300 underline">Start secure sign-in again</a></section></main>;
}
