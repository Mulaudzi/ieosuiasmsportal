import { cleanup,fireEvent,render,screen } from '@testing-library/react';
import { afterEach,beforeEach,expect,it,vi } from 'vitest';
import { usePortalHints } from './usePortalHints';
import { PortalHint } from '@/components/ui/portal-hint';
const account=vi.hoisted(()=>({id:'one'}));
vi.mock('@/hooks/useAuth',()=>({useAuth:()=>({user:{id:account.id}})}));
function Tips(){const p=usePortalHints();return <PortalHint visible={p.visible} onChange={p.setVisible}>Helpful guidance</PortalHint>;}
beforeEach(()=>{localStorage.clear();account.id='one';});afterEach(cleanup);
it('defaults to visible, remembers dismissal for that account, and lets the customer restore tips',()=>{const r=render(<Tips/>);fireEvent.click(screen.getByRole('button',{name:'Hide tips'}));expect(screen.queryByText('Helpful guidance')).not.toBeInTheDocument();r.unmount();render(<Tips/>);expect(screen.getByRole('button',{name:'Show tips'})).toBeInTheDocument();fireEvent.click(screen.getByRole('button',{name:'Show tips'}));expect(screen.getByText('Helpful guidance')).toBeInTheDocument();});
it('does not apply one customers hint preference to another account',()=>{const r=render(<Tips/>);fireEvent.click(screen.getByRole('button',{name:'Hide tips'}));account.id='two';r.rerender(<Tips/>);expect(screen.getByText('Helpful guidance')).toBeInTheDocument();});
