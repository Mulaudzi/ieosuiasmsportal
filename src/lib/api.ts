/**
 * Mock API Service Layer
 * This is a mock; replace with real backend API calls later.
 * When connecting to PHP backend, change API_BASE_URL to your backend domain.
 */

const API_BASE_URL = '/api';

// Simulate network delay
const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

// Types
export interface ApiResponse<T> {
  success: boolean;
  data?: T;
  error?: string;
}

export interface DashboardStats {
  balance: number;
  smsSent: number;
  emailsSent: number;
  queued: number;
  delivered: number;
  failed: number;
  deliveryRate: number;
  contacts: number;
}

export interface Campaign {
  id: string;
  name: string;
  type: 'sms' | 'email';
  status: 'Queued' | 'Sending' | 'Delivered' | 'Failed' | 'Scheduled';
  recipients: number;
  delivered: number;
  failed: number;
  createdAt: string;
  scheduledFor?: string;
}

export interface CreditPackage {
  credits: number;
  price: number;
}

export interface Transaction {
  id: string;
  type: 'purchase' | 'usage' | 'refund';
  description: string;
  amount: string;
  date: string;
  status: 'completed' | 'pending' | 'failed';
}

export interface Contact {
  id: string;
  name: string;
  phone: string;
  email: string;
  group: string;
  status: 'active' | 'optedOut';
  createdAt: string;
}

// Mock API functions

export async function login(email: string, password: string): Promise<ApiResponse<{ userId: string; token: string }>> {
  await delay(800);
  // Mock validation
  if (!email || !password) {
    return { success: false, error: 'Email and password are required' };
  }
  return { 
    success: true, 
    data: { userId: 'mock-user-123', token: 'fake-jwt-token-xyz' } 
  };
}

export async function register(data: { name: string; email: string; password: string; accountType: string }): Promise<ApiResponse<{ userId: string; token: string }>> {
  await delay(1000);
  if (!data.email || !data.password || !data.name) {
    return { success: false, error: 'All fields are required' };
  }
  return { 
    success: true, 
    data: { userId: 'mock-user-123', token: 'fake-jwt-token-xyz' } 
  };
}

export async function getDashboardStats(): Promise<ApiResponse<DashboardStats>> {
  await delay(500);
  return {
    success: true,
    data: {
      balance: 12450,
      smsSent: 284650,
      emailsSent: 156890,
      queued: 1250,
      delivered: 270000,
      failed: 14650,
      deliveryRate: 94.8,
      contacts: 45280
    }
  };
}

export async function createSmsCampaign(data: {
  name: string;
  description?: string;
  recipientMethod: string;
  contactGroup?: string;
  message: string;
  senderId: string;
  scheduleType: string;
  scheduleDate?: string;
  scheduleTime?: string;
}): Promise<ApiResponse<{ campaignId: string; estimatedCost: number; status: string }>> {
  await delay(1500);
  if (!data.name || !data.message) {
    return { success: false, error: 'Campaign name and message are required' };
  }
  const messageLength = data.message.length;
  const smsCount = Math.ceil(messageLength / 160) || 1;
  const estimatedCost = smsCount * 1250; // Mock recipient count
  
  return {
    success: true,
    data: {
      campaignId: `campaign-${Date.now()}`,
      estimatedCost,
      status: data.scheduleType === 'now' ? 'Queued' : 'Scheduled'
    }
  };
}

export async function createEmailCampaign(data: {
  name: string;
  subject: string;
  from: string;
  content: string;
  recipients: string[];
  schedule?: string;
}): Promise<ApiResponse<{ campaignId: string; status: string }>> {
  await delay(1500);
  return {
    success: true,
    data: {
      campaignId: `email-campaign-${Date.now()}`,
      status: 'Queued'
    }
  };
}

export async function getCampaign(id: string): Promise<ApiResponse<Campaign>> {
  await delay(500);
  return {
    success: true,
    data: {
      id,
      name: 'Summer Sale Announcement',
      type: 'sms',
      status: 'Delivered',
      recipients: 1250,
      delivered: 1180,
      failed: 70,
      createdAt: new Date().toISOString()
    }
  };
}

export async function deleteCampaign(id: string): Promise<ApiResponse<null>> {
  await delay(800);
  return { success: true };
}

export async function duplicateCampaign(id: string): Promise<ApiResponse<{ campaignId: string }>> {
  await delay(800);
  return { 
    success: true, 
    data: { campaignId: `campaign-copy-${Date.now()}` } 
  };
}

export async function importContacts(contacts: any[]): Promise<ApiResponse<{ imported: number; duplicatesRemoved: number }>> {
  await delay(2000);
  return {
    success: true,
    data: {
      imported: contacts.length || 100,
      duplicatesRemoved: 5
    }
  };
}

export async function getContactGroups(): Promise<ApiResponse<{ name: string; count: number }[]>> {
  await delay(300);
  return {
    success: true,
    data: [
      { name: 'All Contacts', count: 12450 },
      { name: 'Customers', count: 8200 },
      { name: 'Leads', count: 3500 },
      { name: 'VIP', count: 750 },
      { name: 'Opted Out', count: 320 }
    ]
  };
}

export async function createContactGroup(name: string): Promise<ApiResponse<{ groupId: string }>> {
  await delay(500);
  return {
    success: true,
    data: { groupId: `group-${Date.now()}` }
  };
}

export async function deleteContacts(ids: string[]): Promise<ApiResponse<{ deleted: number }>> {
  await delay(800);
  return {
    success: true,
    data: { deleted: ids.length }
  };
}

export async function addContactsToGroup(contactIds: string[], groupName: string): Promise<ApiResponse<null>> {
  await delay(500);
  return { success: true };
}

export async function exportContacts(ids?: string[]): Promise<ApiResponse<{ downloadUrl: string }>> {
  await delay(1000);
  return {
    success: true,
    data: { downloadUrl: '/mock-export.csv' }
  };
}

export async function buyCredits(packageInfo: CreditPackage): Promise<ApiResponse<{ newBalance: number; transactionId: string }>> {
  await delay(1500);
  return {
    success: true,
    data: {
      newBalance: 12450 + packageInfo.credits,
      transactionId: `txn-${Date.now()}`
    }
  };
}

export async function getWalletHistory(): Promise<ApiResponse<Transaction[]>> {
  await delay(500);
  return {
    success: true,
    data: [
      { id: '1', type: 'purchase', description: 'Credit Purchase - 5,000 credits', amount: '+5,000', date: 'Jan 7, 2026', status: 'completed' },
      { id: '2', type: 'usage', description: 'SMS Campaign - Summer Sale', amount: '-1,250', date: 'Jan 7, 2026', status: 'completed' },
      { id: '3', type: 'usage', description: 'SMS Campaign - Flash Sale', amount: '-3,200', date: 'Jan 6, 2026', status: 'completed' },
    ]
  };
}

export async function saveSettings(section: string, data: any): Promise<ApiResponse<null>> {
  await delay(800);
  console.log(`Saving ${section} settings:`, data);
  return { success: true };
}

export async function exportReport(type: string, dateRange: string): Promise<ApiResponse<{ downloadUrl: string }>> {
  await delay(1500);
  return {
    success: true,
    data: { downloadUrl: `/mock-report-${type}-${dateRange}.pdf` }
  };
}

export async function logout(): Promise<ApiResponse<null>> {
  await delay(300);
  return { success: true };
}

// DLR Webhook simulation (for testing)
export async function simulateDlrCallback(messageId: string, status: 'Delivered' | 'Failed'): Promise<ApiResponse<null>> {
  await delay(200);
  console.log(`DLR Callback: Message ${messageId} - ${status}`);
  return { success: true };
}
