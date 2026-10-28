import { toast } from "@/hooks/use-toast";
import { ApiError, hostingBlockMessage, isHostingBlock, serverFailureMessage } from "@/lib/api-error";
import { expireSession } from '@/lib/session-expiry';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'https://sms.ieosuia.com/api';

/**
 * ApiResponse represents the actual server response structure
 * The server returns different structures depending on the endpoint:
 * - Type A (merged): { success: true, ...data } e.g., { success: true, groups: [...] }
 * - Type B (paginated): { success: true, data: [...], meta: {...} }
 * - Type C (single resource): { success: true, ...data } e.g., { success: true, contact: {...} }
 * 
 * To handle this, we use index signature to allow any property at the top level
 */
interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: string;
  errors?: Record<string, string[]>;
  message?: string;
  meta?: {
    current_page?: number;
    per_page?: number;
    total?: number;
    last_page?: number;
    [key: string]: unknown;
  };
  [key: string]: unknown; // Allow any other properties (groups, contacts, etc.)
}

class ApiClient {
  private baseUrl: string;

  constructor(baseUrl: string) {
    this.baseUrl = baseUrl;
  }

  private getToken(): string | null {
    return localStorage.getItem('auth_token');
  }

  private getHeaders(includeAuth = true): HeadersInit {
    const headers: HeadersInit = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (includeAuth) {
      const token = this.getToken();
      if (token) headers['Authorization'] = `Bearer ${token}`;
    }
    return headers;
  }

  private async handleResponse<T>(response: Response): Promise<ApiResponse<T>> {
    let data;
    const contentType = response.headers.get('content-type');
    const text = await response.text();
    if ((!response.ok || !contentType?.includes('application/json')) && isHostingBlock(text)) throw new ApiError(hostingBlockMessage, response.status, 'hosting-blocked');
    
    try {
      // Only try to parse JSON if the response has content and is JSON
      if (contentType && contentType.includes('application/json')) {
        if (text && text.trim()) {
          data = JSON.parse(text);
        } else {
          data = {};
        }
      } else {
        // For non-JSON responses, try to get the text for error messages
        if (!response.ok) {
          throw new ApiError(response.status >= 500 ? serverFailureMessage(response.status) : response.status === 403 ? 'The server blocked this request before a valid API response was received. Contact support, then retry.' : `Request failed (${response.status}). Please try again.`, response.status);
        }
        if (text.trim()) throw new ApiError('The server did not return a valid API response. Please retry or contact support.', response.status, 'format');
        data = {};
      }
    } catch (e) {
      if (e instanceof SyntaxError) {
        throw new ApiError(response.status >= 500 ? serverFailureMessage(response.status) : `Server error (${response.status}): Invalid response format`, response.status, 'format');
      }
      throw e;
    }
    
    if (!response.ok) {
      if (response.status === 422 && data.errors) {
        const firstError = Object.values(data.errors)[0];
        throw new ApiError(Array.isArray(firstError) ? firstError[0] : String(firstError), 422);
      }
      if (response.status === 403) {
        throw new ApiError('You do not have permission to perform this action. If your administrator access changed, sign in again.', 403, 'permission');
      }
      if (response.status === 404) {
        throw new ApiError('The requested resource was not found.', 404);
      }
      if (response.status >= 500) {
        throw new ApiError(response.status === 503 || response.status === 507 ? serverFailureMessage(response.status) : data.message || data.error || serverFailureMessage(response.status), response.status);
      }
      throw new ApiError(data.error || data.message || 'An error occurred', response.status);
    }
    return data;
  }

  private async request(input: string, init: RequestInit): Promise<Response> {
    const requestToken = new Headers(init.headers).get('Authorization')?.replace(/^Bearer /, '') || null;
    const response = await fetch(input, init);
    // Authentication is determined by HTTP status, not by whether a proxy/server
    // happened to return valid JSON. Never turn a hosting 403 into a logout.
    if (response.status === 401) {
      expireSession(requestToken);
      throw new ApiError('Session expired. Please log in again.', 401);
    }
    return response;
  }

  async get<T>(endpoint: string, params?: Record<string, string>, options?: {signal?: AbortSignal}): Promise<ApiResponse<T>> {
    const url = new URL(`${this.baseUrl}${endpoint}`, window.location.origin);
    if (params) Object.entries(params).forEach(([k, v]) => v && url.searchParams.append(k, v));
    return this.handleResponse<T>(await this.request(url.toString(), { method: 'GET', headers: this.getHeaders(), signal: options?.signal }));
  }

  async post<T>(endpoint: string, body?: unknown, includeAuth = true): Promise<ApiResponse<T>> {
    return this.handleResponse<T>(await this.request(`${this.baseUrl}${endpoint}`, {
      method: 'POST', headers: this.getHeaders(includeAuth), body: body ? JSON.stringify(body) : undefined
    }));
  }

  async getBlob(endpoint: string, signal?: AbortSignal): Promise<Blob> {
    const response = await this.request(`${this.baseUrl}${endpoint}`, {
      headers: this.getHeaders(), cache: 'no-store', signal,
    });
    if (!response.ok) await this.handleResponse(response);
    return response.blob();
  }

  async put<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.handleResponse<T>(await this.request(`${this.baseUrl}${endpoint}`, {
      method: 'PUT', headers: this.getHeaders(), body: body ? JSON.stringify(body) : undefined
    }));
  }

  async delete<T>(endpoint: string, options?: { data?: unknown }): Promise<ApiResponse<T>> {
    return this.handleResponse<T>(await this.request(`${this.baseUrl}${endpoint}`, {
      method: 'DELETE', 
      headers: this.getHeaders(),
      body: options?.data ? JSON.stringify(options.data) : undefined
    }));
  }

  async upload<T>(endpoint: string, formData: FormData): Promise<ApiResponse<T>> {
    const token = this.getToken();
    const headers: HeadersInit = { 'Accept': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return this.handleResponse<T>(await this.request(`${this.baseUrl}${endpoint}`, { method: 'POST', headers, body: formData }));
  }
}

export const api = new ApiClient(API_BASE_URL);

export const downloadAuthenticated = async (endpoint: string, filename: string): Promise<{ success: true }> => {
  const requestToken = localStorage.getItem('auth_token');
  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    headers: { Authorization: `Bearer ${requestToken || ''}` },
  });
  if (response.status === 401) {
    expireSession(requestToken);
    throw new ApiError('Session expired. Please log in again.', 401);
  }
  if (!response.ok) throw new Error('Export failed');
  const url = URL.createObjectURL(await response.blob());
  const anchor = document.createElement('a');
  anchor.href = url;
  const disposition=response.headers?.get('Content-Disposition') || '';
  const suggested=/^\/(sms\/campaigns-v2\/\d+\/export|reports\/export)(?:\?|$)/.test(endpoint) ? disposition.match(/filename="([^"\r\n]+)"/i)?.[1] : undefined;
  anchor.download = suggested ? suggested.replace(/[\\/]/g,'-') : filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 60000);
  return { success: true };
};

export interface SmsRecipientSource { manual: string[]; contact_ids: string[]; group_ids: string[]; excluded: string[]; }
export interface SmsPreview { final_content:string; include_opt_out:boolean; entered_count:number; valid_count:number; invalid_count:number; duplicate_count:number; opted_out_count:number; excluded_count:number; sendable_count:number; recipients:string[]; encoding:'gsm7'|'ucs2'; character_count:number; unit_count:number; segment_count:number; total_segments:number; required_credits:number; price_per_segment:number; estimated_charge:number; wallet_balance:number; available_balance:number; available_credits:number; sufficient_balance:boolean; invalid:string[]; }
export interface SmsCampaignInput { include_opt_out?:boolean; name:string; content:string; sender_id?:string; scheduled_at?:string|null; send_now?:boolean; idempotency_key:string; recipients:SmsRecipientSource; }

// Auth
export const login = (email: string, password: string) => api.post<{ user: any; token: string }>('/auth/login', { email, password }, false);
export const register = (data: { name: string; email: string; password: string; account_type?: string }) => api.post<{ user: any; token: string }>('/auth/register', data, false);
export const logout = () => api.post('/auth/logout');
export const getCurrentUser = () => api.get<{ user: any; wallet: any }>('/auth/user');
export const getDashboardStats = (range = '30d', dates: {from?:string;to?:string} = {}) => api.get<any>('/dashboard/stats', { range, ...dates });
export const getSmsCampaign = (id: string) => api.get<any>(`/sms/campaigns-v2/${id}`);
export const getEmailCampaign = (id: string) => api.get<any>(`/email/campaigns/${id}`);
export const previewSmsCampaign = (data: Omit<SmsCampaignInput,'name'|'idempotency_key'|'scheduled_at'>) =>
  api.post<SmsPreview>('/sms/preview',data) as Promise<ApiResponse<SmsPreview> & {preview?: SmsPreview}>;
export const createSmsCampaign = (data: SmsCampaignInput) =>
  api.post<any>('/sms/campaigns-v2', data) as Promise<ApiResponse<any> & {campaign?: any}>;
export const queueSmsCampaign = (id:string) =>
  api.post<any>(`/sms/campaigns-v2/${id}/queue`) as Promise<ApiResponse<any> & {campaign?: any}>;
export const retrySmsCampaign = (id:string) =>
  api.post<any>(`/sms/campaigns-v2/${id}/retry`) as Promise<ApiResponse<any> & {campaign?: any;retried_messages?:number}>;
export const cancelSmsCampaign = (id:string) => api.post<any>(`/sms/campaigns-v2/${id}/cancel`);
export const createEmailCampaign = (data: any) => api.post<any>('/email/campaigns', data);
export const deleteCampaign = (id: string, type: 'sms' | 'email' = 'sms') => api.delete(`/${type}/campaigns/${id}`);
export const duplicateCampaign = (id: string, type: 'sms' | 'email' = 'sms') => api.post<any>(`/${type}/campaigns/${id}/duplicate`);
export const retryCampaign = (id: string) => api.post<any>(`/campaigns/${id}/retry`);
export const exportCampaignMessages = (id: string, type: 'sms' | 'email' = 'sms', filters: {state?:string;search?:string;format?:'csv'|'pdf'|'excel'} = {}) => {
  const endpoint = type === 'sms' ? `/sms/campaigns-v2/${id}/export` : `/email/campaigns/${id}/export`;
  const query=new URLSearchParams(Object.entries(filters).filter(([,value])=>Boolean(value)&&value!=='all'));
  return downloadAuthenticated(endpoint+(query.size?'?'+query.toString():''), `${type}-campaign-${id}.${filters.format==='excel'?'xls':filters.format||'csv'}`);
};
export const checkCampaignCredits = (recipientCount: number, type: 'sms' | 'email') => 
  api.post<any>('/campaigns/check-credits', { recipient_count: recipientCount, type });
export const importContacts = (formData: FormData) => api.upload<{ imported: number; failed: number; duplicates: number; message: string }>('/contacts/import', formData);
export const getContactGroups = () => api.get<{ groups: any[]; total_contacts: number }>('/contact-groups');
export const createContactGroup = (name: string, description?: string) => api.post<{ group: any }>('/contact-groups', { name, description });
export const updateContactGroup = (id: string, name: string, description?: string) => api.put<{ group: any }>(`/contact-groups/${id}`, { name, description });
export const deleteContactGroup = (id: string) => api.delete<void>(`/contact-groups/${id}`);
export const deleteContacts = (ids: string[]) => api.post<any>('/contacts/bulk-delete', { ids });
export const addContactsToGroup = (ids: string[], groupId: string) => api.post<any>('/contacts/bulk-add-to-group', { ids, group_id: groupId });
export interface ContactGroupTransfer {
  scope?: 'selected' | 'group';
  mode: 'copy' | 'move' | 'merge';
  target_group_id: string;
  source_group_id?: string;
  ids?: string[];
  delete_source?: boolean;
}
export interface ContactGroupTransferResult {
  matched: number; added: number; already_present: number; removed_from_source: number;
  source_deleted: boolean; source_group_id: number | null; target_group_id: number;
}
export const transferGroupContacts = (data: ContactGroupTransfer) => api.post<ContactGroupTransferResult>('/contacts/transfer-group', data);
export const deleteContact = (id: string) => api.delete<void>(`/contacts/${id}`);
export const exportContacts = (groupId?: string) => {
  const query = groupId ? `?group_id=${encodeURIComponent(groupId)}` : '';
  return downloadAuthenticated(`/contacts/export${query}`, `contacts-${new Date().toISOString().slice(0, 10)}.csv`);
};
export const buyCredits = (data: { credits: number; payment_method: 'payos'|'eft'; quoted_total_cents: number; pricing_version: string; idempotency_key: string }) => api.post<any>('/wallet/buy', data);
export const getPaymentStatus = (reference: string) => api.get<any>('/wallet/payments/status', { reference });
export const exportPaymentHistory = (status?: string, gateway?: string) => {
  const params=new URLSearchParams();
  if(status&&status!=='all')params.set('status',status);
  if(gateway&&gateway!=='all')params.set('gateway',gateway);
  const query=params.toString()?`?${params.toString()}`:'';
  return downloadAuthenticated(`/wallet/payments/export${query}`,`payment-history-${new Date().toISOString().slice(0,10)}.csv`);
};
export const downloadPaymentReceipt = (paymentId:number,reference:string) =>
  downloadAuthenticated(`/wallet/receipt?id=${encodeURIComponent(String(paymentId))}`,`receipt-${reference}.html`);
export const saveSettings = (section: string, data: any) => api.put<any>(`/settings/${section}`, data);
export const exportReport = (type: string,format:'csv'|'excel'|'pdf'='csv',range='90d') => {
  const extension=format==='excel'?'xls':format;
  return downloadAuthenticated(`/reports/export?type=${encodeURIComponent(type)}&format=${format}&range=${encodeURIComponent(range)}`, `${type}-report.${extension}`);
};
export const emailReport = (range:string) => api.post<{message:string}>('/reports/email',{range});

// Email limits
export const getEmailLimits = () => api.get<any>('/email/limits');

// Attachments
export const uploadAttachment = (formData: FormData, campaignId?: string) => {
  const url = campaignId ? `/attachments/upload?campaign_id=${campaignId}` : '/attachments/upload';
  return api.upload<any>(url, formData);
};
export const deleteAttachment = (id: string) => api.delete(`/attachments/${id}`);
// Templates
export const getTemplates = (type?: string) => api.get<any[]>('/templates', type ? { type } : undefined);
export const getTemplate = (id: string) => api.get<any>(`/templates/${id}`);
export const createTemplate = (data: { name: string; content: string; type: string }) => api.post<any>('/templates', data);
export const updateTemplate = (id: string, data: { name?: string; content?: string; subject?: string }) => api.put<any>(`/templates/${id}`, data);
export const deleteTemplate = (id: string) => api.delete(`/templates/${id}`);

// Profile
export const getProfile = () => api.get<any>('/settings/profile');
export const updateProfile = (data: { name?: string; email?: string; phone?: string; company_name?: string; address?: string; city?: string; province?: string; postal_code?: string; country?: string; vat_number?: string; website?: string; industry?: string }) => api.put<any>('/settings/profile', data);
export const uploadBranding = (formData: FormData) => api.upload<any>('/settings/branding', formData);

// Contacts
export const getContacts = (params?: { group_id?: string; search?: string; page?: number; per_page?: number; sort?: 'newest' | 'oldest' | 'name' | string }) => 
  api.get<{ contacts: any[]; total: number; page: number; per_page: number }>('/contacts', params as any);
export const getContact = (id: string) => api.get<any>(`/contacts/${id}`);
export const createContact = (data: { name: string; phone?: string; email?: string; group_id?: string }) => 
  api.post<any>('/contacts', data);
export const updateContact = (id: string, data: { name?: string; phone?: string; email?: string; group_id?: string; reuse_existing?: boolean }) => 
  api.put<any>(`/contacts/${id}`, data);

// Campaigns
export const getSmsCampaigns = (params?: { status?: string; search?: string; page?: number;range?:string;from?:string;to?:string;sort?:string }) =>
  api.get<{ campaigns: any[]; stats:any; meta: {total:number;page:number;per_page:number} }>('/sms/campaigns-v2', params as any);
export const getEmailCampaigns = (params?: { status?: string; search?: string; page?: number }) => 
  api.get<{ campaigns: any[]; total: number; stats: any }>('/email/campaigns', params as any);

// Wallet
export const getWalletStats = () => api.get<{ balance: number; used_this_month: number; total_spent: number }>('/wallet/stats');
export const getTransactions = (params?: { page?: number; limit?: number }) => 
  api.get<{ transactions: any[]; total: number }>('/wallet/transactions', params as any);

// Reports
export const getReportStats = (dateRange?: string) => 
  api.get<any>('/reports/stats', dateRange ? { range: dateRange } : undefined);
export const getReportChartData = (dateRange?: string) => 
  api.get<any>('/reports/chart', dateRange ? { range: dateRange } : undefined);
export const getDeliveryBreakdown = (dateRange?: string) => 
  api.get<any>('/reports/delivery', dateRange ? { range: dateRange } : undefined);

// Settings
export const getSettings = (section: string) => api.get<any>(`/settings/${section}`);

export const handleApiError = (error: unknown) => {
  toast({ title: "Error", description: error instanceof Error ? error.message : 'An error occurred', variant: "destructive" });
};

// Listen for session expired events
if (typeof window !== 'undefined') {
  window.addEventListener('auth:session-expired', () => {
    toast({ title: "Session Expired", description: "Please log in again to continue.", variant: "destructive" });
  });
}

// Legacy type exports for compatibility
export interface DashboardStats { balance: number; smsSent: number; emailsSent: number; queued: number; delivered: number; failed: number; deliveryRate: number; contacts: number; }
export interface Campaign { id: string; name: string; type: 'sms' | 'email'; status: string; recipients: number; delivered: number; failed: number; createdAt: string; }
export interface Contact { id: string; name: string; phone: string; email: string; group: string; status: string; createdAt: string; }
export interface Transaction { id: string; type: string; description: string; amount: string; date: string; status: string; }
