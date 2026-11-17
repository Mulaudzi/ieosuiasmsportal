import {useState} from 'react';
import {cleanup,fireEvent,render,screen} from '@testing-library/react';
import {afterEach,expect,it} from 'vitest';
import {ContactImportReview} from './ContactImportReview';
import type {ImportContact,ImportDecision} from '@/lib/contact-import-review';
afterEach(cleanup);
function Fixture({contacts}:{contacts:ImportContact[]}){
  const [decisions,setDecisions]=useState<Record<number,ImportDecision>>({});
  return <ContactImportReview contacts={contacts} rowNumbers={contacts.map((_,index)=>index+3)} decisions={decisions} onChange={setDecisions}/>;
}
it('does not overwhelm ready, safely repaired, email-only or empty rows with review cards',()=>{
  const {container}=render(<Fixture contacts={[{phone:'821234567'},{phone:'+27 (0) 82 123 4567'},{email:'a@example.com'},{name:'Footer'}]}/>);
  expect(container).toBeEmptyDOMElement();
});
it('limits bulk choices to the filtered selection and resets selection when searching',()=>{
  render(<Fixture contacts={[{name:'Ada',phone:'27'},{name:'Bob',phone:'27'},{name:'Cat',phone:'27'}]}/>);
  fireEvent.click(screen.getByRole('checkbox',{name:'Select all 3 matching rows'}));
  expect(screen.getByRole('button',{name:'Skip selected (3)'})).toBeEnabled();
  fireEvent.change(screen.getByLabelText('Find a contact or worksheet row'),{target:{value:'Bob'}});
  expect(screen.getByRole('button',{name:'Skip selected (0)'})).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox',{name:'Select all 1 matching rows'}));fireEvent.click(screen.getByRole('button',{name:'Skip selected (1)'}));
  expect(screen.getByRole('status')).toHaveTextContent('2 awaiting your choice');
  fireEvent.change(screen.getByLabelText('Review filter'),{target:{value:'rejected'}});
  expect(screen.getByText('Row 4 · Bob')).toBeInTheDocument();
});
it('bulk selection spans pages while pagination remains valid after resolving the whole filter',()=>{
  const {container}=render(<Fixture contacts={Array.from({length:30},(_,index)=>({name:'Person '+index,phone:'27'}))}/>);
  expect(container.querySelectorAll('[data-review-row]')).toHaveLength(25);
  fireEvent.click(screen.getByRole('button',{name:'Next rows'}));expect(container.querySelectorAll('[data-review-row]')).toHaveLength(5);
  fireEvent.click(screen.getByRole('checkbox',{name:'Select all 30 matching rows'}));fireEvent.click(screen.getByRole('button',{name:'Skip selected (30)'}));
  expect(screen.getByRole('status')).toHaveTextContent('0 awaiting your choice');
  expect(screen.getByText('All checks are resolved. You can import your ready contacts below.')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Review filter'),{target:{value:'rejected'}});expect(container.querySelectorAll('[data-review-row]')).toHaveLength(25);
});
it('keeps email only explicitly and shows it as resolved without manufacturing a number',()=>{
  render(<Fixture contacts={[{phone:'2771234567',email:'a@example.com'}]}/>);
  expect(screen.getByRole('button',{name:'Accept correction for row 3'})).toBeDisabled();
  fireEvent.click(screen.getByRole('button',{name:'Keep email only'}));
  expect(screen.getByRole('status')).toHaveTextContent('1 corrections accepted');
  fireEvent.change(screen.getByLabelText('Review filter'),{target:{value:'ready'}});
  expect(screen.getByText('Resolved: keep email only; no SMS will be sent to this contact.')).toBeInTheDocument();
});
it('invalid edits reopen the issue instead of retaining a stale approval',()=>{
  render(<Fixture contacts={[{phone:'2771234567'}]}/>);
  fireEvent.change(screen.getByLabelText('Proposed correction for row 3'),{target:{value:'0721234567'}});
  fireEvent.click(screen.getByRole('button',{name:'Accept correction for row 3'}));
  fireEvent.change(screen.getByLabelText('Review filter'),{target:{value:'ready'}});
  fireEvent.change(screen.getByLabelText('Proposed correction for row 3'),{target:{value:'27'}});
  expect(screen.getByRole('status')).toHaveTextContent('1 awaiting your choice');
  fireEvent.change(screen.getByLabelText('Review filter'),{target:{value:'pending'}});
  expect(screen.getByRole('button',{name:'Accept correction for row 3'})).toBeDisabled();
});
