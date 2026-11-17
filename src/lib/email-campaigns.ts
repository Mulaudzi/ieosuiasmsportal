import { api } from './api';
export interface EmailDesign {layout:'letter'|'announcement'|'newsletter'|'showcase';primary:string;background:string;text:string;logo:string;hero:string;button_label:string;button_url:string;}
export const defaultEmailDesign:EmailDesign={layout:'letter',primary:'#1763D1',background:'#F3F6FB',text:'#152238',logo:'',hero:'',button_label:'',button_url:''};
export interface EmailRiskReport {version:string;blocked:boolean;review_required:boolean;findings:{code:string;severity:'block'|'review';message:string}[];links:string[];limitations:string;}
export interface EmailFile {id:string;owner:number;name:string;mime:string;purpose:'attachment'|'logo'|'hero';bytes:number;state:string;expires_at:number;download_url:string|null;inspection?:{format?:string;pages?:number;review_required?:boolean;text_extraction_complete?:boolean};}
export interface EmailFileLimits {enabled:boolean;attachment_count:number;attachment_bytes:number;attachment_total_bytes:number;logo_bytes:number;hero_bytes:number;campaign_delivery_bytes:number;daily_delivery_bytes:number;}
export interface SavedEmailTemplate {id:string;name:string;design:EmailDesign;}
export interface EmailSender { user_id: number; business_name: string; postal_address: string; reply_to: string; state: 'pending'|'approved'|'rejected'|'suspended'; daily_limit: number; version: number; account_email?: string; approval_evidence?: string; }
export interface EmailReplyStatus {account_email:string;addresses:{email:string;verification_id:string;verified_at:number}[];pending:null|{id:string;email:string;state:string;expires_at:number;seconds_remaining:number;resend_after_seconds:number;attempts_remaining:number};max_addresses:number;sender?:EmailSender|null;}
export interface EmailRecipient { id: string; email: string; state: string; delivery_state?: string; attempts: number; accepted_at: string|null; outcome_code: string|null; provider?:string|null; provider_message_id?:string|null; }
export interface EmailCampaign { id: string; user_id: number; name: string; subject: string; body?: string; state: string; recipient_count: number; reserved_cents: number; charged_cents: number; created_at: string; fingerprint?: string; consent_evidence?: string; sender?: EmailSender; recipients?: EmailRecipient[];design?:EmailDesign|null;files?:EmailFile[];moderation?:EmailRiskReport|null; }
export interface EmailLimits { enabled: boolean; emails_per_credit: number; readiness: {ready: boolean; reasons: string[]}; max_recipients: number; billing_basis: string; }
export interface EmailQuote { entered: number; invalid: number; duplicates: number; opted_out: number; sendable: number; credits_required: string; amount: string; available_balance: string; sufficient_balance: boolean; recipients: string[];moderation?:EmailRiskReport;estimated_delivery_bytes?:number; }
export interface EmailCampaignInput { name: string; subject: string; body: string; recipients: {manual: string[]; group_ids: string[]; contact_ids: string[]}; consent_evidence: string; consent_confirmed: boolean;design?:EmailDesign;attachment_ids?:string[]; }
const gateway = (action: string, id?: string) => `/email-aws.php?action=${encodeURIComponent(action)}${id ? `&id=${encodeURIComponent(id)}` : ''}`;
export const emailApi = {
  replyStatus:async()=>(await api.get(gateway('reply-to-status'))) as unknown as EmailReplyStatus,
  requestReply:async(email:string)=>(await api.post(gateway('request-reply-to'),{email})) as unknown as EmailReplyStatus,
  verifyReply:async(id:string,code:string)=>(await api.post(gateway('verify-reply-to'),{id,code})) as unknown as EmailReplyStatus,
  removeReply:async(email:string)=>(await api.post(gateway('remove-reply-to'),{email})) as unknown as EmailReplyStatus,
  templates:async()=>(await api.get(gateway('templates'))) as unknown as {catalog:{id:string;name:string;description:string}[];saved:SavedEmailTemplate[];file_limits:EmailFileLimits},
  saveTemplate:async(data:{name:string;design:EmailDesign;id?:string})=>(await api.post(gateway('save-template'),data)).template as SavedEmailTemplate,
  files:async()=>(await api.get(gateway('files'))) as unknown as {files:EmailFile[];file_limits:EmailFileLimits},
  uploadTicket:async(data:{name:string;mime:string;bytes:number;sha256:string;purpose:EmailFile['purpose']})=>(await api.post(gateway('upload-ticket'),data)) as unknown as {file:EmailFile;upload:{url:string;method:string;headers:Record<string,string>}},
  preview:async(data:{subject:string;body:string;design:EmailDesign;attachment_ids:string[]})=>(await api.post(gateway('preview'),data)) as unknown as {html:string;moderation:EmailRiskReport},
  limits: async () => (await api.get(gateway('limits'))) as unknown as EmailLimits,
  sender: async () => (await api.get(gateway('sender'))).sender as EmailSender|null,
  saveSender: async (data: {business_name: string; postal_address: string;reply_to?:string}) => (await api.post(gateway('save-sender'),data)).sender as EmailSender,
  campaigns: async () => (await api.get(gateway('campaigns'))).campaigns as EmailCampaign[],
  detail: async (id: string, admin=false) => (await api.get(gateway(admin?'admin-detail':'detail',id))).campaign as EmailCampaign,
  quote: async (data: EmailCampaignInput) => (await api.post(gateway('quote'),data)).quote as EmailQuote,
  create: async (data: EmailCampaignInput & {idempotency_key: string}) => (await api.post(gateway('create'),data)).campaign as EmailCampaign,
  queue: async (id: string) => (await api.post(gateway('queue',id),{})).campaign as EmailCampaign,
  cancel: async (id: string) => (await api.post(gateway('cancel',id),{})).campaign as EmailCampaign,
  overview: async () => (await api.get(gateway('overview'))) as unknown as {senders: EmailSender[]; campaigns: EmailCampaign[]},
  reviewSender: async (user: number, data: {decision: string; evidence: string; daily_limit: number; expected_version: number}) => (await api.post(gateway('review-sender'),{...data,user_id:user})).sender as EmailSender,
  reviewCampaign: async (id: string, data: {decision: string; evidence: string; fingerprint: string;risk_acknowledged?:boolean;files_reviewed?:boolean}) => (await api.post(gateway('review-campaign',id),data)).campaign as EmailCampaign,
};
