import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { Logo, logoAssets } from './Logo';

afterEach(cleanup);

describe('approved SMS branding', () => {
  it.each(['standard', 'light', 'navy', 'dark'] as const)('uses the approved %s asset without changing its proportions', variant => {
    render(<Logo variant={variant} linkTo="" />);
    const logo = screen.getByRole('img', { name: 'IEOSUIA SMS Portal' });
    expect(logo).toHaveAttribute('src', logoAssets[variant]);
    expect(logo).toHaveAttribute('width', '2172');
    expect(logo).toHaveAttribute('height', '724');
  });
});
