(() => {
  const byId = id => document.getElementById(id);
  const status = byId('status'), output = byId('output'), apply = byId('apply'), inspect = byId('inspect');
  const storage = 'contact-tags-setup:' + location.pathname;
  let key = new URLSearchParams(location.hash.slice(1)).get('key') || '';
  if (key) { try { sessionStorage.setItem(storage, key); } catch {} history.replaceState(null, '', location.pathname); }
  else { try { key = sessionStorage.getItem(storage) || ''; } catch {} }
  if (!/^[A-Za-z0-9_-]{43}$/.test(key)) return;
  let json = '', running = false;
  async function request(write) {
    if (running) return;
    if (write && !confirm('Apply contact tags (020) and its saved-draft prerequisite (016), if missing? Email and all other migrations are excluded. No messages or payments will be sent.')) return;
    running = true; apply.disabled = true; inspect.disabled = true;
    status.textContent = write ? 'Applying contact-tag setup. Please wait…' : 'Inspecting database schema. No changes are being made…';
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 55000);
    try {
      const response = await fetch(location.pathname, {
        method: write ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: { 'X-Contact-Tags-Setup-Key': key, Accept: 'application/json', ...(write ? { 'Content-Type': 'application/json' } : {}) },
        ...(write ? { body: JSON.stringify({ confirm: '016_work_drafts+020_contact_tags' }) } : {})
      });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Hosting verification interrupted the request. Complete browser verification and use Inspect / refresh. Do not repeat Apply.');
      const data = await response.json();
      json = JSON.stringify(data, null, 2); output.textContent = json;
      byId('copy').disabled = false; byId('download').disabled = false;
      if (data.code === 'CONTACT_TAGS_READY' || data.code === 'ALREADY_APPLIED') status.textContent = 'Database setup verified. Copy this JSON back to our conversation so the temporary tool can be deleted.';
      else if (response.ok && data.ok && data.ready_to_apply) { status.textContent = 'Preflight passed. You can now click Apply contact-tags setup.'; apply.disabled = false; }
      else status.textContent = data.message || 'The database needs checking before setup. Copy this JSON back; do not apply again.';
    } catch (error) {
      status.textContent = error.name === 'AbortError' ? 'The request timed out. Use Inspect / refresh to check the outcome before any further action.' : error.message;
    } finally { clearTimeout(timer); running = false; inspect.disabled = false; }
  }
  inspect.disabled = false; inspect.addEventListener('click', () => request(false));
  apply.addEventListener('click', () => request(true));
  byId('copy').addEventListener('click', async () => { try { await navigator.clipboard.writeText(json); status.textContent = 'JSON copied. Paste it into our conversation.'; } catch { status.textContent = 'Use Download JSON or select the JSON and copy it.'; } });
  byId('download').addEventListener('click', () => { const url = URL.createObjectURL(new Blob([json], { type: 'application/json' })); const a = document.createElement('a'); a.href = url; a.download = 'contact-tags-database-setup.json'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); });
  request(false);
})();
