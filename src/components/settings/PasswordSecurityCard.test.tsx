import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { PasswordSecurityCard } from './PasswordSecurityCard';
afterEach(cleanup);
it('explains the shared password without suggesting that account data resets',()=>{
 render(<PasswordSecurityCard/>);
 expect(screen.getByRole('link',{name:'Change IEOSUIA password'})).toHaveAttribute('href','https://auth.ieosuia.com/forgot-password');
 expect(screen.getByText(/contacts, campaigns and credits are not deleted or reset/)).toBeVisible();
 expect(screen.getByText(/shared password changes/)).toBeVisible();
 expect(screen.getByRole('link',{name:'security@ieosuia.com'})).toHaveAttribute('href','mailto:security@ieosuia.com');
});
