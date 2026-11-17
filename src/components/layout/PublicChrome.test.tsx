import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { PublicChrome, SignedInPublicHeader } from './PublicChrome';
const auth=vi.hoisted(()=>({isAuthenticated:false,isLoading:false,user:null as null|{role:'user'|'admin'}}));
vi.mock('@/hooks/useAuth',()=>({useAuth:()=>auth}));
describe('Consistent public navigation',()=>{
  beforeEach(()=>{auth.isAuthenticated=false;auth.isLoading=false;auth.user=null;});
  const open=(path='/developers')=>render(<MemoryRouter initialEntries={[path]}><PublicChrome/><SignedInPublicHeader/></MemoryRouter>);
  it('uses the same logo, navigation and account actions on guest home and guide',()=>{
    const a=open('/');const home=screen.getByRole('navigation',{name:'Main navigation'}).textContent;a.unmount();open();
    expect(screen.getByRole('navigation',{name:'Main navigation'}).textContent).toBe(home);
    expect(screen.getByRole('link',{name:'Get Started'})).toHaveAttribute('href','/register');
    expect(screen.getByRole('link',{name:'Sign In'})).toHaveAttribute('href','/login');
    expect(screen.getByRole('img',{name:'IEOSUIA SMS Portal'})).toBeInTheDocument();
  });
  it.each(['/','/developers','/documentation','/pricing'])('keeps full navigation on signed-in %s',path=>{
    auth.isAuthenticated=true;auth.user={role:'user'};open(path);
    expect(screen.getAllByRole('banner')).toHaveLength(1);
    expect(screen.getByRole('navigation',{name:'Main navigation'})).toBeInTheDocument();
    expect(screen.getByRole('link',{name:'Go To Dashboard'})).toHaveAttribute('href','/dashboard');
    expect(screen.queryByRole('link',{name:'Get Started'})).not.toBeInTheDocument();
    expect(screen.queryByRole('link',{name:'Sign In'})).not.toBeInTheDocument();
    expect(screen.queryByText('Back to dashboard')).not.toBeInTheDocument();
  });
  it('routes administrators to their dashboard',()=>{
    auth.isAuthenticated=true;auth.user={role:'admin'};open();
    expect(screen.getByRole('link',{name:'Go To Dashboard'})).toHaveAttribute('href','/guymhan');
  });
  it('uses the authenticated account link in the mobile menu',()=>{
    auth.isAuthenticated=true;auth.user={role:'user'};open();
    fireEvent.click(screen.getByRole('button',{name:'Open navigation'}));
    expect(within(screen.getByRole('navigation',{name:'Mobile navigation'})).getByRole('link',{name:'Go To Dashboard'})).toHaveAttribute('href','/dashboard');
    expect(screen.queryByRole('link',{name:'Get Started'})).not.toBeInTheDocument();
    fireEvent.keyDown(window,{key:'Escape'});expect(screen.queryByRole('navigation',{name:'Mobile navigation'})).not.toBeInTheDocument();
  });
  it('does not flash registration actions before sign-in is checked',()=>{
    auth.isLoading=true;open();expect(screen.getByRole('status',{name:'Checking sign-in'})).toBeInTheDocument();
    expect(screen.queryByRole('link',{name:'Get Started'})).not.toBeInTheDocument();
  });
  it('leaves private dashboard chrome alone',()=>{
    auth.isAuthenticated=true;auth.user={role:'user'};open('/dashboard');
    expect(screen.queryByRole('banner')).not.toBeInTheDocument();
  });
});
