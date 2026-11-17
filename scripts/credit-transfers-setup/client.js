(() => {
  const byId=id=>document.getElementById(id),status=byId('status'),output=byId('output'),apply=byId('apply'),inspect=byId('inspect'),backup=byId('backup'),security=byId('security');
  const storage='credit-transfers-setup:'+location.pathname;
  let key=new URLSearchParams(location.hash.slice(1)).get('key')||'';
  if(key){try{sessionStorage.setItem(storage,key);}catch{}history.replaceState(null,'',location.pathname);}
  else{try{key=sessionStorage.getItem(storage)||'';}catch{}}
  if(!/^[A-Za-z0-9_-]{43}$/.test(key))return;
  let json='',running=false,ready=false,needsSecurity=false,claimed=false;
  function buttons(){backup.disabled=running||claimed||!ready;security.disabled=running||claimed||!ready;apply.disabled=running||claimed||!ready||!backup.checked||(needsSecurity&&!security.checked);}
  backup.addEventListener('change',buttons);security.addEventListener('change',buttons);
  async function request(write){
    if(running)return;
    if(write&&(apply.disabled||!confirm('Apply credit-transfer schema 021'+(needsSecurity?' and its missing prerequisite 015':'')+'? Your database backup must be verified. Email migration 019 is excluded. No credits or payments will move.')))return;
    running=true;ready=false;inspect.disabled=true;if(write)claimed=true;buttons();
    status.textContent=write?'Applying the schema. Please wait; do not click again…':'Inspecting the database schema. No changes are being made…';
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),65000);
    try{
      const response=await fetch(location.pathname,{method:write?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-Credit-Transfer-Setup-Key':key,Accept:'application/json',...(write?{'Content-Type':'application/json'}:{})},...(write?{body:JSON.stringify({confirm:'021_credit_transfers',backup_confirmed:backup.checked,include_security_prerequisite:needsSecurity&&security.checked})}:{})});
      if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Hosting verification interrupted the request. Complete browser verification, then use Inspect / refresh. Do not repeat Apply.');
      const data=await response.json();json=JSON.stringify(data,null,2);output.textContent=json;byId('copy').disabled=false;byId('download').disabled=false;
      needsSecurity=data.security_prerequisite_permission_required===true;byId('security-row').hidden=!needsSecurity;
      if(data.code==='CREDIT_TRANSFERS_READY'||data.code==='ALREADY_APPLIED'){claimed=true;status.textContent='Database setup verified. Copy the JSON back so this temporary tool can be deleted. Transfers are not enabled by this tool.';}
      else if(!write&&!claimed&&response.ok&&data.ok&&data.ready_to_apply&&!data.partial_setup_requires_explicit_resume&&!data.setup_attempted){ready=true;status.textContent='Preflight passed. Confirm your verified backup'+(needsSecurity?' and permit prerequisite 015':'')+', then click Apply credit-transfer setup.';}
      else status.textContent=data.message||'The schema needs checking before setup. Copy this JSON back; do not apply again.';
    }catch(e){status.textContent=e.name==='AbortError'?'The request timed out. Use Inspect / refresh to check the result; do not repeat Apply.':e.message;}
    finally{clearTimeout(timer);running=false;inspect.disabled=false;buttons();}
  }
  inspect.disabled=false;inspect.addEventListener('click',()=>request(false));apply.addEventListener('click',()=>request(true));
  byId('copy').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(json);status.textContent='JSON copied. Paste it into our conversation.';}catch{status.textContent='Use Download JSON, or select and copy the displayed JSON.';}});
  byId('download').addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([json],{type:'application/json'})),a=document.createElement('a');a.href=url;a.download='credit-transfer-database-setup.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
  request(false);
})();
