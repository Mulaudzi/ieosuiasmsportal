import { api } from '@/lib/api';

export interface WhatsAppNumber {
  waba_id: string;
  phone_number_id: string;
  business_name: string;
  display_phone_number: string;
  verified_name: string;
}
export interface WhatsAppConnection extends WhatsAppNumber {
  status: 'authorized' | 'disconnected' | 'expired';
  expires_at: number | null;
}
export interface WhatsAppConnectStatus {
  available: boolean;
  sending_enabled: false;
  connections: WhatsAppConnection[];
  pending: { attempt: string; options: WhatsAppNumber[]; expires_at: number } | null;
}
export async function getWhatsAppConnections(): Promise<WhatsAppConnectStatus> {
  return await api.get('/whatsapp-connect.php') as unknown as WhatsAppConnectStatus;
}
export async function startWhatsAppConnection(): Promise<void> {
  const result = await api.post('/whatsapp-connect.php?action=start', {});
  const url = new URL(String(result.authorize_url));
  if (url.origin !== 'https://business.facebook.com' || url.pathname !== '/messaging/whatsapp/onboard/') {
    throw new Error('WhatsApp returned an invalid signup link. Please retry.');
  }
  window.location.assign(url.toString());
}
export const confirmWhatsAppConnection = (attempt: string, phone_number_id: string) =>
  api.post('/whatsapp-connect.php?action=confirm', { attempt, phone_number_id });
export const cancelWhatsAppConnection = () => api.post('/whatsapp-connect.php?action=cancel', {});
export const disconnectWhatsAppConnection = (phone_number_id: string) =>
  api.post('/whatsapp-connect.php?action=disconnect', { phone_number_id });
