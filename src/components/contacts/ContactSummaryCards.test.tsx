import {cleanup,render,screen,within} from '@testing-library/react';
import {afterEach,expect,it} from 'vitest';
import {ContactSummaryCards} from './ContactSummaryCards';
afterEach(cleanup);
it('shows four exact real counts with a defined recent period',()=>{render(<ContactSummaryCards total={1250} groups={7} optedOut={32} recentlyAdded={18}/>);const region=screen.getByRole('region',{name:'Contact summary'});expect(within(region).getAllByRole('heading')).toHaveLength(4);for(const value of ['1,250','7','32','18'])expect(within(region).getByText(value,{exact:true})).toBeInTheDocument();expect(screen.getByText('In the last 7 days')).toBeInTheDocument();});
it('does not invent zero counts while metadata is unavailable',()=>{render(<ContactSummaryCards total={null} groups={null} optedOut={null} recentlyAdded={null}/>);expect(screen.getAllByText('—')).toHaveLength(4);expect(screen.queryByText('0')).not.toBeInTheDocument();});
