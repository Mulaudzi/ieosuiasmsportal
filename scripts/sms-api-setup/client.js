(() => {
  const key = new URLSearchParams(location.hash.slice(1)).get('key');
  history.replaceState(null, '', location.pathname);
  const status = document.querySelector('#status'), output = document.querySelector('#output');
  const inspect = document.querySelector('#inspect'), apply = document.querySelector('#apply'), backup = document.querySelector('#backup'), copy = document.querySelector('#copy');
  let ready = false, attempted = false;
  const controls = () => { apply.disabled = !ready || !backup.checked || attempted; };
  const run = async (submit = false) => {
    inspect.disabled = true; apply.disabled = true;
    if (submit) attempted = true;
    status.textContent = submit ? 'Applying only migration 027. Do not close or retry.' : 'Inspecting schema…';
    try {
      const response = await fetch(location.pathname, { method: submit ? 'POST' : 'GET', headers: { 'X-SMS-API-Setup-Key': key, Accept: 'application/json', ...(submit ? {'Content-Type':'application/json'} : {}) }, ...(submit ? {body:JSON.stringify({confirm:'027_sms_integration_api',backup_confirmed:true})} : {}), cache:'no-store' });
      const data = await response.json(); output.textContent = JSON.stringify(data, null, 2);
      ready = response.ok && data.ready_to_apply === true && !data.migration_recorded;
      backup.disabled = !ready; copy.disabled = false;
      status.textContent = data.code === 'SMS_API_SCHEMA_READY' ? 'Database setup verified. Return this JSON so the temporary tool can be deleted.' : ready ? 'Check is ready. Verify your backup before Apply.' : 'Copy this inspection for review. No automatic retry will occur.';
    } catch { ready = false; status.textContent = 'The result is uncertain. Inspect / refresh; do not click Apply again.'; }
    finally { inspect.disabled = false; controls(); }
  };
  backup.addEventListener('change', controls); inspect.addEventListener('click', () => void run()); apply.addEventListener('click', () => void run(true));
  copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(output.textContent); status.textContent = 'JSON copied.'; } catch { status.textContent = 'Select and copy the JSON below.'; } });
  if (key) void run(); else status.textContent = 'Missing private key. Open the full private link from the conversation.';
})();
