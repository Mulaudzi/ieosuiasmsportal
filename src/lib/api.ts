import { toast } from "@/hooks/use-toast";

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'https://sms.ieosuia.com/api';

interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: string;
  errors?: Record<string, string[]>;
  message?: string;
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
    
    try {
      // Only try to parse JSON if the response has content and is JSON
      if (contentType && contentType.includes('application/json')) {
        const text = await response.text();
        if (text && text.trim()) {
          data = JSON.parse(text);
        } else {
          data = {};
        }
      } else {
        // For non-JSON responses, try to get the text for error messages
        const text = await response.text();
        if (!response.ok) {
          throw new Error(text || `Server error (${response.status})`);
        }
        data = {};
      }
    } catch (e) {
      if (e instanceof SyntaxError) {
        throw new Error(`Server error (${response.status}): Invalid response format`);
      }
      throw e;
    }
    
    if (!response.ok) {
      if (response.status === 401) {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user');
        // Show toast before redirect
        const event = new CustomEvent('auth:session-expired');
        window.dispatchEvent(event);
        // Delay redirect to allow toast to show
        setTimeout(() => {
          window.location.href = '/login';
        }, 100);
        throw new Error('Session expired. Please log in again.');
      }
      if (response.status === 422 && data.errors) {
        const firstError = Object.values(data.errors)[0];
        throw new Error(Array.isArray(firstError) ? firstError[0] : String(firstError));
      }
      if (response.status === 403) {
        throw new Error('You do not have permission to perform this action.');
      }
      if (response.status === 404) {
        throw new Error('The requested resource was not found.');
      }
      if (response.status >= 500) {
        throw new Error('Server error. Please try again later.');
      }
      throw new Error(data.error || data.message || 'An error occurred');
    }
    return data;
  }

  async get<T>(endpoint: string, params?: Record<string, string>): Promise<ApiResponse<T>> {
    const url = new URL(`${this.baseUrl}${endpoint}`, window.location.origin);
    if (params) Object.entries(params).forEach(([k, v]) => v && url.searchParams.append(k, v));
    return this.handleResponse<T>(await fetch(url.toString(), { method: 'GET', headers: this.getHeaders() }));
  }

  async post<T>(endpoint: string, body?: unknown, includeAuth = true): Promise<ApiResponse<T>> {
    return this.handleResponse<T>(await fetch(`${this.baseUrl}${endpoint}`, {
      method: 'POST', headers: this.getHeaders(includeAuth), body: body ? JSON.stringify(body) : undefined
    }));
  }

  async put<T>(endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
    return this.handleResponse<T>(await fetch(`${this.baseUrl}${endpoint}`, {
      method: 'PUT', headers: this.getHeaders(), body: body ? JSON.stringify(body) : undefined
    }));
  }

  async delete<T>(endpoint: string): Promise<ApiResponse<T>> {
    return this.handleResponse<T>(await fetch(`${this.baseUrl}${endpoint}`, { method: 'DELETE', headers: this.getHeaders() }));
  }

  async upload<T>(endpoint: string, formData: FormData): Promise<ApiResponse<T>> {
    const token = this.getToken();
    const headers: HeadersInit = { 'Accept': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return this.handleResponse<T>(await fetch(`${this.baseUrl}${endpoint}`, { method: 'POST', headers, body: formData }));
  }
}

export const api = new ApiClient(API_BASE_URL);

// Auth
export const login = (email: string, password: string) => api.post<{ user: any; token: string }>('/auth/login', { email, password }, false);
export const register = (data: { name: string; email: string; password: string; account_type?: string }) => api.post<{ user: any; token: string }>('/auth/register', data, false);
export const logout = () => api.post('/auth/logout');
export const getDashboardStats = () => api.get<any>('/dashboard/stats');
export const getCampaign = (id: string) => api.get<any>(`/campaigns/${id}`);
export const getSmsCampaign = (id: string) => api.get<any>(`/sms/campaigns/${id}`);
export const getEmailCampaign = (id: string) => api.get<any>(`/email/campaigns/${id}`);
export const createSmsCampaign = (data: any) => api.post<any>('/sms/campaigns', data);
export const createEmailCampaign = (data: any) => api.post<any>('/email/campaigns', data);
export const deleteCampaign = (id: string, type: 'sms' | 'email' = 'sms') => api.delete(`/${type}/campaigns/${id}`);
export const duplicateCampaign = (id: string, type: 'sms' | 'email' = 'sms') => api.post<any>(`/${type}/campaigns/${id}/duplicate`);
export const retryCampaign = (id: string) => api.post<any>(`/campaigns/${id}/retry`);
export const exportCampaignMessages = (id: string, type: 'sms' | 'email' = 'sms') => {
  // For CSV downloads, we need to redirect
  const token = localStorage.getItem('auth_token');
  const url = `${API_BASE_URL}/${type}/campaigns/${id}/export`;
  window.open(`${url}?token=${token}`, '_blank');
};
export const checkCampaignCredits = (recipientCount: number, type: 'sms' | 'email') => 
  api.post<any>('/campaigns/check-credits', { recipient_count: recipientCount, type });
export const importContacts = (formData: FormData) => api.upload<any>('/contacts/import', formData);
export const getContactGroups = () => api.get<any[]>('/contact-groups');
export const createContactGroup = (name: string) => api.post<any>('/contact-groups', { name });
export const deleteContacts = (ids: string[]) => api.post<any>('/contacts/bulk-delete', { ids });
export const exportContacts = (groupId?: string) => {
  const token = localStorage.getItem('auth_token');
  const url = groupId 
    ? `${API_BASE_URL}/contacts/export?group_id=${groupId}&token=${token}`
    : `${API_BASE_URL}/contacts/export?token=${token}`;
  window.open(url, '_blank');
};
export const buyCredits = (data: { amount: number; payment_method: string }) => api.post<any>('/wallet/buy', data);
export const getWalletHistory = () => api.get<any[]>('/wallet/history');
export const saveSettings = (section: string, data: any) => api.put<any>(`/settings/${section}`, data);
export const exportReport = (type: string) => api.get<any>(`/reports/export/${type}`);

// Sender IDs
export const getSenderIds = (type?: 'sms' | 'email') => api.get<any>('/sender-ids', type ? { type } : undefined);
export const createSenderId = (data: { type: string; sender_id?: string; sender_email?: string; sender_name?: string }) => 
  api.post<any>('/sender-ids', data);
export const deleteSenderId = (id: string) => api.delete(`/sender-ids/${id}`);
export const setDefaultSenderId = (id: string) => api.post<any>(`/sender-ids/${id}/default`);

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
export const updateTemplate = (id: string, data: { name?: string; content?: string; type?: string }) => api.put<any>(`/templates/${id}`, data);
export const deleteTemplate = (id: string) => api.delete(`/templates/${id}`);

// Profile
export const getProfile = () => api.get<any>('/settings/profile');
export const updateProfile = (data: { name?: string; email?: string; phone?: string }) => api.put<any>('/settings/profile', data);
export const uploadBranding = (formData: FormData) => api.upload<any>('/settings/branding', formData);

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
export interface CreditPackage { credits: number; price: number; }
