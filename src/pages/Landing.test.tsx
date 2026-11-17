import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import Landing from './Landing';
import { deliveryDemo } from '@/components/marketing/PlatformPreviews';
import { creditPricing, creditQuote, rand } from '@/lib/credit-purchases';

vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ isAuthenticated: false, isLoading: false }) }));
afterEach(cleanup);

it('presents the communications workspace with existing registration destinations', () => {
  render(<MemoryRouter><Landing /></MemoryRouter>);
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Build audiences.Send campaigns.Track delivery.');
  for (const link of screen.getAllByRole('link', { name: 'Start Messaging' })) expect(link).toHaveAttribute('href', '/register');
  expect(screen.getByRole('link', { name: 'Explore the Platform' })).toHaveAttribute('href', '#platform');
  for (const id of ['platform', 'features', 'workflow', 'audiences', 'reporting', 'pricing', 'use-cases', 'faq']) expect(document.getElementById(id)).not.toBeNull();
});

it('shows the minimum purchase in the hero and every current volume price', () => {
  render(<MemoryRouter><Landing /></MemoryRouter>);
  const minimum = creditQuote(creditPricing.minimum)!;
  const transfer = creditQuote(creditPricing.minimum, 'eft')!;
  expect(screen.getByRole('link', { name: `Start with ${creditPricing.minimum} credits for ${rand(minimum.total_cents)}` })).toHaveAttribute('href', '#pricing');
  expect(screen.getByText(`Or ${rand(transfer.total_cents)} by direct FNB transfer. No VAT charged.`)).toBeVisible();
  const table = screen.getByRole('table', { name: 'SMS credit prices by purchase quantity' });
  expect(table).toBeVisible();
  expect(screen.getAllByRole('row')).toHaveLength(creditPricing.tiers.length + 1);
  const ranges = within(table).getAllByRole('rowheader');
  for (const [index, tier] of creditPricing.tiers.entries()) {
    const range = `${Math.max(creditPricing.minimum, tier.min).toLocaleString('en-ZA')} to ${tier.max.toLocaleString('en-ZA')}`;
    expect(ranges[index].textContent?.replace(/\s/g, ' ')).toBe(range.replace(/\s/g, ' '));
    expect(ranges[index].closest('tr')?.querySelector('td')).toHaveTextContent(rand(tier.cents));
  }
  expect(screen.getByRole('link', { name: 'Calculate your purchase' })).toHaveAttribute('href', '/pricing');
});

it('labels sample metrics and keeps email unavailable without inventing analytics', () => {
  render(<MemoryRouter><Landing /></MemoryRouter>);
  expect(screen.getAllByRole('figure')).toHaveLength(3);
  for (const preview of screen.getAllByRole('figure')) expect(preview).toHaveAccessibleName(/demo/i);
  expect(deliveryDemo.delivered + deliveryDemo.pending + deliveryDemo.failed).toBe(deliveryDemo.total);
  expect(screen.getByText('Coming Soon')).toBeVisible();
  expect(screen.getByText('Not available for sending in this release.')).toBeVisible();
  expect(screen.queryByText(/Free Forever|geographic analytics|device analytics|click tracking/i)).not.toBeInTheDocument();
  expect(document.body.textContent).not.toMatch(/[\u2013\u2014]/);
});

it('preserves approved logo, real prepaid pricing and support phone', () => {
  render(<MemoryRouter><Landing /></MemoryRouter>);
  for (const logo of screen.getAllByRole('img', { name: 'IEOSUIA SMS Portal' })) expect(logo).toHaveAttribute('src', expect.stringContaining('ieosuia-sms-portal-navy.png'));
  expect(screen.getByText('R0.22 to R0.35')).toBeVisible();
  expect(screen.getByText('30 credits from R10.50')).toBeVisible();
  expect(screen.getByRole('link', { name: '+27 63 808 2493' })).toHaveAttribute('href', 'tel:+27638082493');
});
