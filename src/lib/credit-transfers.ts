import { api } from '@/lib/api';

export interface TransferAccount {id:string;name:string;email:string;available_credits:number|null;eligible:boolean;}
export interface CreditTransferQuote {
  id:string;source:Pick<TransferAccount,'id'|'name'|'email'>;destination:Pick<TransferAccount,'id'|'name'|'email'>;
  credits:number;amount:string;unit_amount:string;currency:string;reason:string;
  source_available_credits:number;source_available_after:number;destination_available_after?:number;expires_at:number;
}
export interface CreditTransferReceipt {
  id:string;reference:string;credits:number;status:'completed';amount:string;currency:string;reason:string;
  source_name:string;source_email:string;destination_name:string;destination_email:string;
  source_available_after:number;destination_available_after?:number;created_at:string;
}
export interface CustomerTransfer {id:string;reference:string;credits:number;direction:'sent'|'received';status:'completed';created_at:string;}
export interface TransferHistory<T> {enabled:boolean;transfers:T[];total:number;page:number;last_page:number;security?:{configured:boolean};}
export interface TransferDraft {source_user_id:string;destination_user_id:string;credits:number;reason:string;authorization_confirmed:boolean;request_id:string;}
const base='/admin/operations/credit-transfers';
export interface TransferStatus {status:'completed'|'not_completed';transfer?:CreditTransferReceipt;quote?:CreditTransferQuote;new_review_allowed?:boolean;}
export const creditTransfersApi={
  history:async(page=1)=>{const r=await api.get<TransferHistory<CreditTransferReceipt>>(base,{page:String(page)});return (r.data??r) as TransferHistory<CreditTransferReceipt>;},
  accounts:async(search:string)=>{const r=await api.get<{accounts:TransferAccount[]}>(base+'/accounts',{search});return ((r.data??r) as {accounts:TransferAccount[]}).accounts;},
  quote:async(input:TransferDraft)=>{const r=await api.post<{quote:CreditTransferQuote}>(base+'/quote',input);return ((r.data??r) as {quote:CreditTransferQuote}).quote;},
  commit:async(quote_id:string,master_password:string)=>{const r=await api.post<{transfer:CreditTransferReceipt}>(base+'/commit',{quote_id,master_password,confirmed:true});return ((r.data??r) as {transfer:CreditTransferReceipt}).transfer;},
  status:async(id:string)=>{const r=await api.get<{status:'completed'|'not_completed';transfer?:CreditTransferReceipt;quote?:CreditTransferQuote;new_review_allowed?:boolean}>(base+'/'+id);return (r.data??r) as {status:'completed'|'not_completed';transfer?:CreditTransferReceipt;quote?:CreditTransferQuote;new_review_allowed?:boolean};},
  customerHistory:async(page=1)=>{const r=await api.get<TransferHistory<CustomerTransfer>>('/wallet/credit-transfers',{page:String(page)});return (r.data??r) as TransferHistory<CustomerTransfer>;},
};
const wallet='/wallet/credit-transfers';
export const customerTransfersApi={
  quote:async(input:{recipient_email:string;credits:number;authorization_confirmed:boolean;request_id:string})=>{const r=await api.post<{quote:CreditTransferQuote}>(wallet+'/quote',input);return ((r.data??r) as {quote:CreditTransferQuote}).quote;},
  challenge:async(quote_id:string)=>{await api.post(wallet+'/challenge',{quote_id});},
  commit:async(quote_id:string,verification_code:string)=>{const r=await api.post<{transfer:CreditTransferReceipt}>(wallet+'/commit',{quote_id,verification_code,confirmed:true});return ((r.data??r) as {transfer:CreditTransferReceipt}).transfer;},
  status:async(id:string)=>{const r=await api.get<TransferStatus>(wallet+'/'+id);return (r.data??r) as TransferStatus;},
};
