import { spawn } from 'node:child_process';
import { mkdtemp, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, dirname, basename } from 'node:path';
import assert from 'node:assert/strict';
import { setTimeout as delay } from 'node:timers/promises';

const dir = await mkdtemp(join(tmpdir(), 'sms-credit-purchase-'));
const port = 18764; const base = `http://127.0.0.1:${port}`;
const server = spawn('php', ['-d', 'upload_max_filesize=6M', '-d', 'post_max_size=7M', '-S', `127.0.0.1:${port}`, 'api/tests/fixtures/credit-purchases-router.php'], { env: { ...process.env, CREDIT_PURCHASE_TEST_DB: join(dir, 'fixture.sqlite') }, stdio: 'pipe', windowsHide: true });
let stderr = ''; server.stderr.on('data', d => { stderr += d; });
let checks = 0;
async function request(path, { role = 'customer', body, status = 200 } = {}) {
  const headers = { Authorization: `Bearer fixture-${role}` };
  if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
  const response = await fetch(base + path, { method: body ? 'POST' : 'GET', headers, body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined });
  assert.equal(response.status, status, `${path}: ${await (response.status !== status ? response.clone().text() : Promise.resolve(''))}`); checks++;
  return response;
}
try {
  let ready = false;
  for (let i = 0; i < 50; i++) { try { await fetch(base + '/wallet/purchase-options'); ready = true; break; } catch { await delay(100); } }
  assert.ok(ready, stderr);
  const rules = JSON.parse(await readFile('api/domain/credit-purchase-pricing.json', 'utf8'));
  for (const [credits, payment_method] of [[50001, 'payos'], [500001, 'eft'], [500001, 'payos']]) {
    await request('/wallet/buy', {body: {credits, payment_method}, status: 422});
  }
  const orderBody = { credits: 1000, payment_method: 'eft', pricing_version: rules.version, quoted_total_cents: 30690, idempotency_key: 'http-fixture-000001' };
  const order = (await (await request('/wallet/buy', { body: orderBody })).json()).order;
  assert.equal(order.total_cents, 30690); assert.equal(order.proof_email, 'sms@ieosuia.com'); checks += 2;
  const bad = new FormData(); bad.append('proof', new Blob(['<?php echo 1; ?>'], { type: 'application/pdf' }), 'fake.pdf');
  await request(`/wallet/purchase-orders/${order.id}/proof`, { body: bad, status: 422 });
  const big = new FormData(); big.append('proof', new Blob([new Uint8Array(5 * 1024 * 1024 + 1)], { type: 'image/png' }), 'large.png');
  await request(`/wallet/purchase-orders/${order.id}/proof`, { body: big, status: 422 });
  const bytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9V8AAAAASUVORK5CYII=', 'base64');
  const proof = new FormData(); proof.append('proof', new Blob([bytes], { type: 'image/png' }), 'receipt.png');
  await request(`/wallet/purchase-orders/${order.id}/proof`, { role: 'other', body: proof, status: 404 });
  const uploaded = (await (await request(`/wallet/purchase-orders/${order.id}/proof`, { body: proof })).json()).order;
  assert.equal(uploaded.status, 'awaiting_verification'); checks++;
  await request(`/wallet/purchase-orders/${order.id}/proof`, { body: proof });
  const snapshot = await (await request('/snapshot')).json();
  assert.equal(snapshot.balance, 0); assert.equal(snapshot.proofs, 1); assert.equal(snapshot.emails, 3); checks += 3;
  const proofId = uploaded.proofs[0].id;
  await request(`/wallet/payment-proofs/${proofId}`, { role: 'other', status: 404 });
  const ownerCopy = await request(`/wallet/payment-proofs/${proofId}`);
  assert.match(ownerCopy.headers.get('content-disposition'), /^attachment/); assert.equal(ownerCopy.headers.get('cache-control'), 'private, no-store');
  assert.deepEqual(Buffer.from(await ownerCopy.arrayBuffer()), bytes); checks += 3;
  await request(`/wallet/payment-proofs/${proofId}`, { role: 'admin' });
  await request('/wallet/purchase-orders?review=1', { status: 403 });
  const reviewBody = { decision: 'approve', amount_received: '306.90', funds_verified: true, bank_reference: 'HTTP-BANK-001', note: 'Cleared funds verified in fixture', master_password: 'fixture-master-approval-123' };
  await request(`/admin/operations/purchase-orders/${order.id}/review`, { role: 'admin', body: { ...reviewBody, master_password: '' }, status: 422 });
  await request(`/admin/operations/purchase-orders/${order.id}/review`, { role: 'admin', body: { ...reviewBody, master_password: 'incorrect-fixture-password' }, status: 422 });
  await request(`/admin/operations/purchase-orders/${order.id}/review`, { body: reviewBody, status: 403 });
  await request(`/admin/operations/purchase-orders/${order.id}/review`, { role: 'admin', body: reviewBody });
  await request(`/admin/operations/purchase-orders/${order.id}/review`, { role: 'admin', body: reviewBody });
  const final = await (await request('/snapshot')).json(); assert.equal(final.balance, 350); assert.equal(final.ledger_entries, 1); checks += 2;
  await request(`/wallet/purchase-orders/${order.id}/proof`, { body: proof, status: 409 });
  console.log(`PASS: ${checks} real HTTP checks for private proof upload/download, MIME/size validation, access control, email deduplication and approval replay (local SQLite fixture).`);
} finally {
  const stopped = new Promise(resolve => server.once('exit', resolve)); server.kill(); await stopped;
  assert.equal(dirname(resolve(dir)), resolve(tmpdir()));
  assert.ok(basename(dir).startsWith('sms-credit-purchase-'));
  await rm(dir, { recursive: true, force: true });
}
