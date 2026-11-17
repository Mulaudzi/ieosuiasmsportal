import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { GlobalSearch } from './GlobalSearch';
const mocks=vi.hoisted(()=>({get:vi.fn()}));
vi.mock('@/lib/api',()=>({api:{get:mocks.get}}));
vi.mock('@/hooks/useAuth',()=>({useAuth:()=>({user:{id:'fixture'}})}));
function Location(){const location=useLocation();return <p data-testid="location">{location.pathname+location.search}</p>;}
function mount(){render(<MemoryRouter><GlobalSearch/><Location/></MemoryRouter>);fireEvent.click(screen.getByRole('button',{name:'Search portal'}));return screen.getByRole('combobox');}
beforeEach(()=>{mocks.get.mockReset();mocks.get.mockResolvedValue({success:true,results:[]});});afterEach(cleanup);
it('opens shortcuts on mobile/desktop without enumerating records',()=>{
 mount();expect(screen.getByRole('dialog')).toBeVisible();expect(screen.getByText('Buy or transfer credits')).toBeVisible();expect(mocks.get).not.toHaveBeenCalled();
});
it('shows debounced suggestions and supports arrow keys and Enter',async()=>{
 mocks.get.mockResolvedValue({success:true,results:[{type:'campaign',id:'25',label:'Sunday',search:'Sunday'}]});
 const input=mount();fireEvent.change(input,{target:{value:'Sunday'}});
 expect(await screen.findByRole('option')).toHaveTextContent('Sunday');
 expect(mocks.get).toHaveBeenCalledWith('/search',{q:'Sunday'});
 fireEvent.keyDown(input,{key:'ArrowDown'});fireEvent.keyDown(input,{key:'Enter'});
 expect(screen.getByTestId('location')).toHaveTextContent('/sms-campaigns/25');
});
it('routes a contact suggestion to a URL-encoded filtered contacts page',async()=>{
 mocks.get.mockResolvedValue({success:true,results:[{type:'contact',id:'2',label:'Alice',search:'+27821111111'}]});
 fireEvent.change(mount(),{target:{value:'Alice'}});fireEvent.click(await screen.findByText('Alice'));
 expect(screen.getByTestId('location')).toHaveTextContent('/contacts?search=%2B27821111111');
});
it('ignores stale results when the user changes the query',async()=>{
 let resolve!:(value:unknown)=>void;mocks.get.mockReturnValueOnce(new Promise(done=>{resolve=done;})).mockResolvedValueOnce({success:true,results:[{type:'template',id:'3',label:'New reminder',search:'New reminder'}]});
 const input=mount();fireEvent.change(input,{target:{value:'Old'}});await waitFor(()=>expect(mocks.get).toHaveBeenCalledTimes(1));
 fireEvent.change(input,{target:{value:'New'}});await screen.findByText('New reminder');
 await act(async()=>resolve({success:true,results:[{type:'campaign',id:'1',label:'Old data',search:'Old data'}]}));
 expect(screen.queryByText('Old data')).toBeNull();
});
it('shows errors with retry instead of treating failure as no matches',async()=>{
 mocks.get.mockRejectedValueOnce(Error('Offline'));fireEvent.change(mount(),{target:{value:'Sunday'}});
 expect(await screen.findByRole('alert')).toHaveTextContent('Search is unavailable');fireEvent.click(screen.getByRole('button',{name:'Retry search'}));
 expect(await screen.findByText('No matches. Try a different name or number.')).toBeVisible();
});
it('supports Ctrl K and rejects unknown kinds/unsafe record IDs',async()=>{
 render(<MemoryRouter><GlobalSearch/></MemoryRouter>);fireEvent.keyDown(window,{key:'k',ctrlKey:true});
 mocks.get.mockResolvedValue({success:true,results:[{type:'contact',id:'//evil',label:'Unsafe',search:'Unsafe'},{type:'admin',id:'1',label:'Other account',search:'Bad'}]});
 fireEvent.change(screen.getByRole('combobox'),{target:{value:'Bad'}});await screen.findByText('No matches. Try a different name or number.');
 expect(screen.queryByText('Unsafe')).toBeNull();
});
