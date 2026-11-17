import { api } from '@/lib/api';

export const SMS_API_SCOPES = [
  ['sms:quote', 'Calculate credits'], ['sms:send', 'Send messages'],
  ['sms:schedule', 'Schedule messages'], ['sms:read', 'Read own message status and usage'],
  ['sms:cancel', 'Cancel before sending'], ['wallet:read', 'Read available credit balance'],
] as const;
export const SMS_API_PRODUCTS = ['events', 'tenant-management', 'security-os', 'website', 'custom'] as const;
export interface IntegrationKey { id: string; created_at: number; expires_at: number; revoked_at: number | null; last_used_at: number | null }
export interface SmsIntegration {
  id: string; name: string; product: string; status: 'active' | 'revoked'; scopes: string[];
  daily_credit_limit: number; requests_per_minute: number; admitted_credits_today: number; keys: IntegrationKey[];
}
export interface IntegrationStatus { available: boolean; can_issue_keys?: boolean; connections: SmsIntegration[] }
export interface IntegrationCredential { id: string; token: string; expires_at: number; shown_once: true }
export interface IntegrationCreate { name: string; product: string; scopes: string[]; daily_credit_limit: number; requests_per_minute: number; expires_in_days: number }
export const getSmsIntegrations = async () => await api.get('/settings/api-integrations') as unknown as IntegrationStatus;
export const createSmsIntegration = async (input: IntegrationCreate) => await api.post('/settings/api-integrations', input) as unknown as {connection_id: string; credential: IntegrationCredential};
export const issueSmsIntegrationKey = async (id: string) => await api.post(`/settings/api-integrations/${encodeURIComponent(id)}/keys`, {}) as unknown as {credential: IntegrationCredential};
export const revokeSmsIntegration = (id: string, key?: string) => api.delete(`/settings/api-integrations/${encodeURIComponent(id)}${key ? `/keys/${encodeURIComponent(key)}` : ''}`);
