(() => {
  const el=id=>document.getElementById(id),status=el('status'),output=el('output'),apply=el('apply'),inspect=el('inspect'),backup=el('backup'),cash=el('cash');
  const storage='rand-credit-setup:'+location.pathname;
  let key=new URLSearchParams(location.hash.slice(1)).get('key')||'';
  if(key){try{sessionStorage.setItem(storage,key);}catch{}history.replaceState(null,'',location.pathname);}
  else{try{key=sessionStorage.getItem(storage)||'';}catch{}}
  if(!/^[A-Za-z0-9_-]{43}$/.test(key))return;
  let json='',running=false,ready=false,claimed=false,needsCash=false;
  function buttons(){backup.disabled=running||claimed||!ready;cash.disabled=running||claimed||!ready;apply.disabled=running||claimed||!ready||!backup.checked||(needsCash&&!cash.checked);}
  backup.addEventListener('change',buttons);cash.addEventListener('change',buttons);
  async function request(write){
    if(running)return;
    if(write&&(apply.disabled||!confirm('Apply rand-credit schema migration 025'+(needsCash?' and missing prerequisite 017':'')+'? Confirm your recoverable database backup. No balances or payments will change; email migration 019 and all other migrations are excluded.')))return;
    running=true;ready=false;inspect.disabled=true;if(write)claimed=true;buttons();
    status.textContent=write?'Applying the schema. Please wait; do not click again.':'Inspecting schema only. No changes are being made.';
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),65000);
    try{
      const response=await fetch(location.pathname,{method:write?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-Rand-Credit-Setup-Key':key,Accept:'application/json',...(write?{'Content-Type':'application/json'}:{})},...(write?{body:JSON.stringify({confirm:'025_cash_balance_adjustments',backup_confirmed:backup.checked,include_cash_prerequisite:needsCash&&cash.checked})}:{})});
      if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Hosting verification interrupted the request. Complete browser verification, then use Inspect / refresh; do not repeat Apply.');
      const data=await response.json();json=JSON.stringify(data,null,2);output.textContent=json;el('copy').disabled=false;el('download').disabled=false;
      needsCash=data.cash_prerequisite_permission_required===true;el('cash-row').hidden=!needsCash;
      if(data.ok&&data.code==='CASH_BALANCE_ADJUSTMENTS_READY'){claimed=true;status.textContent='Database setup verified. Copy this JSON back so the temporary tool can be deleted. The application is not deployed or activated by this tool.';}
      else if(!write&&!claimed&&response.ok&&data.ok&&data.ready_to_apply&&!data.partial_setup_requires_investigation&&!data.setup_attempted){ready=true;status.textContent='Preflight passed. Confirm your verified database backup'+(needsCash?' and permission for missing prerequisite 017':'')+', then select Apply rand-credit setup.';}
      else status.textContent=data.message||'The schema needs checking before setup. Copy this JSON back; do not apply again.';
    }catch(e){status.textContent=e.name==='AbortError'?'The request timed out. Use Inspect / refresh to check the result; do not repeat Apply.':e.message;}
    finally{clearTimeout(timer);running=false;inspect.disabled=false;buttons();}
  }
  inspect.disabled=false;inspect.addEventListener('click',()=>request(false));apply.addEventListener('click',()=>request(true));
  el('copy').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(json);status.textContent='JSON copied. Paste it into our conversation.';}catch{status.textContent='Use Download JSON or select and copy the displayed JSON.';}});
  el('download').addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([json],{type:'application/json'})),a=document.createElement('a');a.href=url;a.download='rand-credit-database-setup.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
  request(false);
})();
