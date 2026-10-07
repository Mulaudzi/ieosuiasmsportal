import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const source = readFileSync(new URL('../src/lib/campaign-comparison.ts', import.meta.url), 'utf8');
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2020 } }).outputText;
const { sentenceCase, confirmationTime, costPerDelivery, comparisonWinners } = await import('data:text/javascript;base64,' + Buffer.from(js).toString('base64'));
assert.equal(sentenceCase('CAMPAIGN FOR OCTOBER'), 'Campaign for october');
for (const [seconds, expected] of [[null,'—'],[-1,'—'],[NaN,'—'],[0,'1 min'],[45,'1 min'],[120,'2 min'],[5340,'89 min'],[5400,'2 hours'],[172799,'48 hours'],[172800,'2 days'],[176400,'2 days'],[259200,'3 days']]) {
  assert.equal(confirmationTime(seconds), expected);
}
for (const [cost, expected] of [[null,'—'],[0,'—'],[-1,'—'],[NaN,'—'],[.001,'Less than R0.01'],[.35,'R0.35'],[1.055,'R1.05']]) assert.equal(costPerDelivery(cost), expected);
const campaign = (id, overrides = {}) => ({ id, name: id, type: 'sms', created_at: '2026-01-01', sent_count: 2, delivered_count: 2, failed_count: 0, pending_count: 0, delivery_rate: 100, actual_cost: .7, avg_delivery_time_seconds: 600, cost_per_delivery: .35, ...overrides });
const rows = [campaign('alpha'), campaign('beta'), campaign('pending', { sent_count: 3, delivered_count: 0, pending_count: 3, delivery_rate: 0, avg_delivery_time_seconds: null, cost_per_delivery: null }), campaign('empty', { sent_count: 0, delivered_count: 0, delivery_rate: null, avg_delivery_time_seconds: null, cost_per_delivery: null })];
const winners = comparisonWinners(rows);
for (const key of ['rate','time','cost']) assert.deepEqual(winners[key].map(c => c.id), ['alpha','beta']);
assert.deepEqual(comparisonWinners([]), { rate: [], time: [], cost: [] });
assert.deepEqual(comparisonWinners([rows[2], rows[3]]).rate.map(c => c.id), ['pending']);
assert.deepEqual(comparisonWinners([rows[2], rows[3]]).time, []);
assert.deepEqual(comparisonWinners([rows[2], rows[3]]).cost, []);
assert.deepEqual(comparisonWinners([campaign('immediate', { avg_delivery_time_seconds: 0 }), rows[0]]).time.map(c => c.id), ['immediate']);
assert.deepEqual(comparisonWinners([campaign('single', { sent_count: 1, delivered_count: 1, delivery_rate: 100 }), campaign('partial', { delivery_rate: 50 })]).rate.map(c => c.id), ['single']);
console.log('PASS: sentence case, time thresholds, currency/nulls, ties, eligibility and single-message winner.');
