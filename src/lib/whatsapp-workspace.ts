import { api } from '@/lib/api';
export interface WhatsAppTemplate {
  id: string; name: string; language: string; status: string; category: string;
  components: { type: string; text?: string }[]; supported: boolean; parameter_count: number;
}
export interface WhatsAppOperation {
  key: string; kind: 'send' | 'template' | 'subscribe'; phone_number_id: string;
  state: string; provider_id: string | null; created_at: number; recipient: string | null; template: string | null;
  events: { status: string; provider_timestamp: string }[];
}
export interface WhatsAppWorkspaceStatus { available: boolean; sending_enabled: boolean; operations: WhatsAppOperation[] }
export interface WhatsAppOperationResult { operation_key: string; outcome: 'accepted' | 'rejected' | 'uncertain'; provider_id?: string | null; replayed: boolean }
export const getWhatsAppWorkspace = async () => await api.get('/whatsapp-workspace.php') as unknown as WhatsAppWorkspaceStatus;
export const getWhatsAppTemplates = async (phone: string) => await api.get(`/whatsapp-workspace.php?action=templates&phone_number_id=${encodeURIComponent(phone)}`) as unknown as { templates: WhatsAppTemplate[] };
export const submitWhatsAppOperation = async (action: 'send' | 'create-template' | 'subscribe', body: Record<string, unknown>) =>
  await api.post(`/whatsapp-workspace.php?action=${action}`, body) as unknown as WhatsAppOperationResult;
