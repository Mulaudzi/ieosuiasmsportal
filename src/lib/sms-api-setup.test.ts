import { describe, expect, it } from 'vitest';
import { runInNewContext } from 'node:vm';
import { checkQuoteResponse, smsApiQuoteExamples } from './sms-api-setup';

const success = { success: true, quote: { binding: false, sendable: 1, credits_required: 1, sufficient_balance: true } };
describe('safe API onboarding', () => {
  it.each(smsApiQuoteExamples)('$label check only addresses the quote endpoint', example => {
    expect(example.code).toContain('/api/v1/sms/quotes'); expect(example.code).not.toContain('/sms/messages');
    expect(example.code).toContain('IEOSUIA_SMS_API_KEY'); expect(example.code).not.toContain('Idempotency-Key');
    expect(example.code).not.toContain('result.message'); expect(example.code).not.toContain("['message']");
  });
  it('runs the Node quote example against an intercepted transport, never sending', async () => {
    const calls: Array<{ url: string; options: RequestInit }> = [];
    await runInNewContext(`(async()=>{${smsApiQuoteExamples.find(example => example.id === 'javascript')!.code}})()`, {
      process: { env: { IEOSUIA_SMS_API_KEY: 'synthetic-test-key-only' } }, AbortSignal,
      fetch: async (url: string, options: RequestInit) => { calls.push({ url, options }); return { ok: true, json: async () => success }; }, console: { log: () => undefined },
    });
    expect(calls).toHaveLength(1); expect(calls[0].url).toBe('https://sms.ieosuia.com/api/v1/sms/quotes');
    expect(calls[0].options.method).toBe('POST'); expect(JSON.parse(String(calls[0].options.body)).recipients).toHaveLength(1);
  });
  it('interprets successful quotes without claiming authentication or delivery', () => {
    expect(checkQuoteResponse(JSON.stringify(success))).toEqual({ ok: true, sendable: 1, credits: 1, balanceSufficient: true });
    expect(checkQuoteResponse(JSON.stringify({ success: true, quote: { ...success.quote, sendable: 0, credits_required: 0, sufficient_balance: false } }))).toEqual({ ok: true, sendable: 0, credits: 0, balanceSufficient: false });
  });
  it.each(['{}', '[]', 'null', '<html>bot protection</html>', JSON.stringify({ success: true, message: { state: 'queued' } }), JSON.stringify({ ...success, quote: { ...success.quote, binding: true } }), JSON.stringify({ ...success, quote: { ...success.quote, sendable: '1' } }), JSON.stringify({ success: false, error: { code: 'INVALID_API_KEY' } })])('does not certify invalid or non-quote content: %s', input => {
    expect(checkQuoteResponse(input).ok).toBe(false);
  });
  it('refuses keys, headers and oversized logs', () => {
    expect(checkQuoteResponse('smsk_' + 'a'.repeat(32) + '.' + 'b'.repeat(64)).ok).toBe(false);
    expect(checkQuoteResponse('Authorization: Bearer secret').ok).toBe(false);
    expect(checkQuoteResponse('x'.repeat(16385)).ok).toBe(false);
  });
});
