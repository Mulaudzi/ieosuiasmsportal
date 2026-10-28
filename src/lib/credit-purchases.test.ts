import { describe, expect, it } from 'vitest';
import { creditPricing, creditQuote, rand } from './credit-purchases';

describe('credit purchase pricing', () => {
  it('displays every unit price in rand without changing cent-based arithmetic', () => {
    for (const tier of creditPricing.tiers) expect(rand(tier.cents)).toBe(`R${(tier.cents / 100).toFixed(2)}`);
    expect(rand(35)).toBe('R0.35');
    expect(rand(22)).toBe('R0.22');
  });
  for (const tier of creditPricing.tiers) {
    for (const qty of [Math.max(30, tier.min), tier.max]) it(`quotes ${qty} credits at ${tier.cents} cents`, () => {
      const online = creditQuote(qty); const bank = creditQuote(qty, 'eft')!;
      if (qty <= creditPricing.online_maximum) expect(online?.total_cents).toBe(qty * tier.cents);
      else expect(online).toBeNull();
      expect(bank.credits).toBe(qty);
      expect(bank.discount_cents).toBe(Math.floor(Math.max(200, Math.round(qty * tier.cents * .02)) / 2));
      expect(bank.total_cents + bank.discount_cents).toBe(qty * tier.cents);
    });
  }
  it('accepts exactly 30 whole credits and shows the minimum bank saving', () => {
    expect(creditQuote(30)?.total_cents).toBe(1050);
    expect(creditQuote(30, 'eft')?.total_cents).toBe(950);
    for (const bad of [0, 29, -1, 30.1, 500001, 1000000, Infinity, NaN]) {
      expect(creditQuote(bad)).toBeNull();
      expect(creditQuote(bad, 'eft')).toBeNull();
    }
  });
  it('quotes 1000 credits for R310 online or R306.90 by bank', () => {
    expect(creditQuote(1000)?.total_cents).toBe(31000);
    expect(creditQuote(1000, 'eft')?.discount_cents).toBe(310);
    expect(creditQuote(1000, 'eft')?.total_cents).toBe(30690);
  });
  it('uses R0.31 throughout 501 to 1000 without changing the adjacent tiers', () => {
    for (const qty of [501, 750, 1000]) expect(creditQuote(qty)?.unit_cents).toBe(31);
    expect(creditQuote(500)?.unit_cents).toBe(33);
    expect(creditQuote(1001)?.unit_cents).toBe(29);
    expect(creditPricing.version).toBe('2026-10-21-volume-3');
  });
  it('enforces exact online, direct EFT and sales boundaries', () => {
    expect(creditQuote(50000)?.payment_method).toBe('payos');
    expect(creditQuote(50001)).toBeNull();
    expect(creditQuote(50001, 'eft')?.unit_cents).toBe(24);
    expect(creditQuote(250000, 'eft')?.unit_cents).toBe(23);
    expect(creditQuote(250001, 'eft')?.unit_cents).toBe(22);
    expect(creditQuote(500000, 'eft')).toMatchObject({unit_cents: 22, subtotal_cents: 11000000, discount_cents: 110000, total_cents: 10890000});
    expect(creditQuote(500001, 'eft')).toBeNull();
    expect(creditPricing.sales_email).toBe('sms@ieosuia.com');
  });
});
