import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ShieldCheck, Mail, KeyRound, Smartphone } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/hooks/useAuth';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface Status{available:boolean;enrollment_enabled:boolean;enabled:boolean;recovery_codes_remaining:number}
export function LoginSecurity(){
 const {user}=useAuth();const client=useQueryClient();
 const status=useQuery({queryKey:['login-security',user?.id],enabled:!!user,retry:false,queryFn:async()=>{const r=await api.get<Status>('/settings/login-security');if(!r.success)throw Error('Unavailable');return (r.data??r)as Status;}});
 const [challenge,setChallenge]=useState(''),[purpose,setPurpose]=useState<'enable'|'disable'>('enable'),[code,setCode]=useState(''),[error,setError]=useState(''),[busy,setBusy]=useState(false),[codes,setCodes]=useState<string[]>([]),[saved,setSaved]=useState(false),[completed,setCompleted]=useState(false);
 const request=async()=>{setBusy(true);setError('');const action=status.data?.enabled?'disable':'enable';setPurpose(action);
  try{const r=await api.post<{challenge:string}>('/settings/login-security/request',{purpose:action});const data=(r.data??r)as{challenge:string};if(!r.success||!data.challenge)throw Error('Could not request a verification code.');setChallenge(data.challenge);setCode('');}
  catch(e){setError(e instanceof Error?e.message:'Could not request the code.');}finally{setBusy(false);}
 };
 const confirm=async()=>{setBusy(true);setError('');try{
  const r=await api.post<{token:string;user:unknown;recovery_codes:string[]}>('/settings/login-security/confirm',{challenge,purpose,code});
  const data=(r.data??r)as{token:string;user:unknown;recovery_codes:string[]};if(!r.success||!data.token)throw Error('Verification could not finish.');
  localStorage.setItem('auth_token',data.token);localStorage.setItem('auth_token_issued',String(Date.now()));localStorage.setItem('auth_user',JSON.stringify(data.user));
  setCode('');setChallenge('');setCodes(data.recovery_codes??[]);setCompleted(true);void status.refetch();
  void client.invalidateQueries({queryKey:['login-security']});window.dispatchEvent(new Event('sms:notifications-refresh'));
 }catch(e){setError(e instanceof Error?e.message:'Could not confirm the code.');}finally{setBusy(false);}};
 return <section aria-labelledby="login-security-title" className="space-y-5 overflow-hidden rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
  <div className="flex flex-wrap items-start justify-between gap-3">
<div className="flex items-start gap-3">
<span className="rounded-xl bg-primary/10 p-3 text-primary">
<ShieldCheck className="h-5 w-5" aria-hidden="true"/>
</span>
<div>
<h3 id="login-security-title" className="text-lg font-semibold">Email sign-in verification</h3>
<p className="mt-1 text-sm text-muted-foreground">A six-digit email code, after your password.</p>
</div>
</div>
<span className="rounded-full border bg-muted/40 px-3 py-1 text-xs font-medium">SMS Portal only</span>
</div>
  <p className="text-sm leading-relaxed text-muted-foreground">This extra check is optional and off by default, including for API access. If you enable it, enter a six-digit code emailed to your verified account after Central Auth sign-in. This setting applies only to the SMS Portal, not your other IEOSUIA apps.</p>
  <details className="settings-security-explainer"><summary>What happens when I enable this?</summary><div className="mt-3 grid gap-3 sm:grid-cols-3">{[{icon:Smartphone,title:'1. Choose to enable',text:'You stay in control. It is optional.'},{icon:Mail,title:'2. Confirm your email',text:'Enter the one-time code we send you.'},{icon:KeyRound,title:'3. Save recovery codes',text:'Keep them safe in case you lose email access.'}].map(item=>
<div key={item.title} className="rounded-xl border bg-muted/20 p-3">
<item.icon className="mb-2 h-4 w-4 text-primary" aria-hidden="true"/>
<p className="text-sm font-medium">{item.title}</p>
<p className="mt-1 text-xs leading-relaxed text-muted-foreground">{item.text}</p>
</div>)}</div></details>
  <p className="text-xs leading-relaxed text-muted-foreground">Email verification is an extra sign-in step, not phishing-resistant two-factor authentication. Protect your email account too. Passwords remain managed by Central Auth.</p>
  {status.isPending?<p role="status">Checking security settings…</p>:status.isError?<p role="alert">Security settings unavailable. <Button variant="link" onClick={()=>void status.refetch()}>Retry security settings</Button>
</p>:<>
   <p className={`inline-flex rounded-full px-3 py-1 text-sm font-medium ${status.data?.enabled?'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400':'bg-muted text-muted-foreground'}`}>{status.data?.enabled?'Enabled for the SMS Portal':'Not enabled'}</p>
   {status.data?.enabled&&<p className="text-xs text-muted-foreground">{status.data.recovery_codes_remaining} unused recovery codes. If you lose email access, use one during sign-in. Support cannot reveal your codes.</p>}
   {!status.data?.available||(!status.data.enabled&&!status.data.enrollment_enabled)?<p className="text-sm text-muted-foreground">This optional feature is awaiting secure setup. Your existing sign-in is unchanged.</p>:!challenge&&!completed&&<Button variant={status.data.enabled?'outline':'default'} disabled={busy} onClick={()=>void request()}>{busy?'Requesting code…':status.data.enabled?'Disable extra sign-in check':'Enable extra sign-in check'}</Button>}
  </>}
  {error&&<p role="alert" className="text-sm text-destructive">{error}</p>}
  {challenge&&<form className="space-y-3" onSubmit={e=>{e.preventDefault();void confirm();}}>
<p className="text-sm">Check your verified account email. The code expires in 10 minutes. Confirm to {purpose} this setting; other SMS Portal sessions will be signed out.</p>
<label className="block text-sm">Six-digit verification code<Input aria-label="Security verification code" value={code} onChange={e=>setCode(e.target.value.replace(/\D/g,'').slice(0,6))} inputMode="numeric" autoComplete="one-time-code" maxLength={6} className="mt-2 max-w-xs text-base"/>
</label>
<div className="flex flex-wrap gap-2">
<Button type="button" variant="outline" disabled={busy} onClick={()=>{setChallenge('');setCode('');}}>Cancel</Button>
<Button disabled={busy||!/^\d{6}$/.test(code)}>{busy?'Checking…':`Confirm ${purpose}`}</Button>
</div>
</form>}
  {completed&&<div className="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-4">
<p className="font-medium">Security setting updated.</p>{codes.length>0&&<>
<p className="text-sm">Save these one-time recovery codes somewhere safe. They are shown only now. Do not share them or send them to support.</p>
<ul className="grid gap-2 font-mono text-sm sm:grid-cols-2">{codes.map(value=>
<li className="break-all" key={value}>{value}</li>)}</ul>
<label className="flex items-start gap-2 text-sm">
<input type="checkbox" checked={saved} onChange={e=>setSaved(e.target.checked)}/>I saved my recovery codes securely.</label>
</>}<Button disabled={codes.length>0&&!saved} onClick={()=>window.location.replace('/settings?tab=security')}>Done</Button>
</div>}
 </section>;
}
