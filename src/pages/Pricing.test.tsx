import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import Pricing from './Pricing';

vi.mock('@/components/layout/PublicChrome', () => ({SignedInPublicHeader: () => null}));
vi.mock('@/components/layout/Footer', () => ({Footer: () => null}));
afterEach(cleanup);

it('publishes the EFT-only and contact-sales limits alongside the price list', () => {
  render(<MemoryRouter><Pricing/></MemoryRouter>);
  expect(screen.getByRole('link', {name: 'sms@ieosuia.com'})).toHaveAttribute('href', 'mailto:sms@ieosuia.com');
  expect(screen.getByText(/Purchases above 50,000 credits are payable by direct bank-transfer EFT only/)).toBeVisible();
  const quantity = screen.getByRole('spinbutton');
  fireEvent.change(quantity, {target: {value: '50000'}});
  expect(screen.getByRole('status')).toHaveTextContent('50,000 credits: R12,500.00 at R0.25 each');
  fireEvent.change(quantity, {target: {value: '50001'}});
  expect(screen.getByRole('status')).toHaveTextContent('Direct EFT only');
  fireEvent.change(quantity, {target: {value: '250001'}});
  expect(screen.getByRole('status')).toHaveTextContent('at R0.22 each');
  fireEvent.change(quantity, {target: {value: '500000'}});
  expect(screen.getByRole('status')).toHaveTextContent('500,000 credits: R110,000.00 at R0.22 each');
  fireEvent.change(quantity, {target: {value: '500001'}});
  expect(screen.getByRole('status')).toHaveTextContent('contact sales at sms@ieosuia.com');
  expect(screen.getByRole('status')).not.toHaveTextContent(/R\d/);
  expect(screen.getByRole('link', {name: 'Contact sales: sms@ieosuia.com'})).toHaveAttribute('href', 'mailto:sms@ieosuia.com');
});
