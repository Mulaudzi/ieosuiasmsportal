import { useState, useEffect, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { format, formatDistanceToNow, differenceInSeconds, differenceInMinutes, differenceInHours, differenceInDays } from "date-fns";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Calendar } from "@/components/ui/calendar";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Users,
  Key,
  Shield,
  Search,
  Check,
  X,
  Loader2,
  RefreshCw,
  Ban,
  CheckCircle,
  Clock,
  XCircle,
  MoreHorizontal,
  Mail,
  MessageSquare,
  Wallet,
  BarChart3,
  ShieldAlert,
  Activity,
  UserCheck,
  UserX,
  KeyRound,
  Download,
  FileText,
  FileSpreadsheet,
  Calendar as CalendarIcon,
  Play,
  Timer,
  Settings,
  Send,
  Server,
  Eye,
  EyeOff,
  AlertCircle,
  Bell,
  BellOff,
  Database,
  HardDrive,
  Wifi,
  WifiOff,
  Thermometer,
  TrendingUp,
  Inbox,
  Reply,
  MessageCircle as MessageIcon,
  HelpCircle,
} from "lucide-react";
import { useAuth } from "@/hooks/useAuth";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { api, handleApiError } from "@/lib/api";

interface User {
  id: string;
  name: string;
  email: string;
  role: "admin" | "user" | "moderator";
  is_active: boolean;
  email_verified_at: string | null;
  created_at: string;
  last_login_at: string | null;
}

interface SenderId {
  id: string;
  user_id: string;
  user_name?: string;
  user_email?: string;
  type: "sms" | "email";
  sender_id: string | null;
  sender_email: string | null;
  sender_name: string | null;
  status: "pending" | "approved" | "rejected";
  created_at: string;
}

interface Stats {
  total_users: number;
  active_users: number;
  pending_sender_ids: number;
  total_campaigns: number;
  total_messages: number;
  total_revenue: number;
}

interface AuditLog {
  id: string;
  user_id: string;
  user_name: string | null;
  user_email: string | null;
  action: string;
  entity_type: string;
  entity_id: string | null;
  old_values: string | null;
  new_values: string | null;
  ip_address: string | null;
  created_at: string;
}

interface ScheduledCampaign {
  id: string;
  name: string;
  type: "sms" | "email";
  status: string;
  scheduled_at: string;
  total_recipients: number;
  user?: {
    id: string;
    name: string;
    email: string;
  };
}

interface CronJob {
  id: string;
  job_name: string;
  last_run_at: string | null;
  status: string;
  run_count: number;
  error_count: number;
}

interface SmtpSetting {
  id: string;
  setting_type: "system" | "campaign";
  host: string;
  port: number;
  encryption: "none" | "ssl" | "tls";
  username: string;
  password: string;
  from_email: string;
  from_name: string;
  is_active: boolean;
  last_tested_at: string | null;
  last_test_result: "success" | "failed" | null;
  last_test_error: string | null;
  has_password?: boolean;
}

interface NotificationSetting {
  id: string;
  event_type: string;
  event_label: string;
  event_description: string | null;
  is_enabled: boolean;
  notify_email: boolean;
  notify_inapp: boolean;
}

interface HealthStatus {
  status: 'healthy' | 'warning' | 'error';
  response_time_ms?: number | null;
  message: string;
  error?: string;
  details?: Record<string, any>;
  last_tested?: string | null;
  host?: string | null;
}

interface SystemHealth {
  database: HealthStatus;
  smtp: HealthStatus;
  api: HealthStatus;
  storage: HealthStatus;
  overall: 'healthy' | 'warning' | 'error';
}

interface HeatmapDay {
  day: string;
  hours: number[];
}

interface HeatmapData {
  registrations: HeatmapDay[];
  campaigns: HeatmapDay[];
  messages: HeatmapDay[];
  delivered: HeatmapDay[];
  failed: HeatmapDay[];
  delivery_rates: DeliveryRateDay[];
}

interface DeliveryRateDay {
  day: string;
  hours: (number | null)[];
}

interface ContactEmail {
  id: string;
  sender_name: string;
  sender_email: string;
  recipient_email: string;
  purpose: "general" | "support" | "sales";
  subject: string;
  message: string;
  status: "sent" | "failed" | "bounced";
  error_message: string | null;
  origin_url: string | null;
  ip_address: string | null;
  confirmation_sent: boolean;
  read_by_admin: boolean;
  read_at: string | null;
  replied: boolean;
  replied_at: string | null;
  notes: string | null;
  created_at: string;
}

interface ContactEmailStats {
  total: number;
  today: number;
  this_week: number;
  status: {
    sent: number;
    failed: number;
    bounced: number;
  };
  read_status: {
    read: number;
    unread: number;
  };
  reply_status: {
    replied: number;
    pending: number;
    response_rate: number;
    avg_response_time: string | null;
  };
  purpose: {
    general: number;
    support: number;
    sales: number;
  };
}

const statusConfig = {
  pending: { label: "Pending", class: "status-pending", icon: Clock },
  approved: { label: "Approved", class: "status-delivered", icon: CheckCircle },
  rejected: { label: "Rejected", class: "status-failed", icon: XCircle },
};

const actionConfig: Record<string, { label: string; icon: React.ElementType; color: string }> = {
  activate_user: { label: "Activated User", icon: UserCheck, color: "text-success" },
  deactivate_user: { label: "Deactivated User", icon: UserX, color: "text-destructive" },
  change_role: { label: "Changed Role", icon: Shield, color: "text-primary" },
  approve_sender_id: { label: "Approved Sender ID", icon: CheckCircle, color: "text-success" },
  reject_sender_id: { label: "Rejected Sender ID", icon: XCircle, color: "text-destructive" },
  campaign_created: { label: "Campaign Created", icon: MessageSquare, color: "text-primary" },
  campaign_sent: { label: "Campaign Sent", icon: CheckCircle, color: "text-success" },
  campaign_scheduled_sent: { label: "Scheduled Campaign Sent", icon: Timer, color: "text-success" },
  campaign_deleted: { label: "Campaign Deleted", icon: XCircle, color: "text-destructive" },
  user_registered: { label: "User Registered", icon: UserCheck, color: "text-primary" },
  cron_executed: { label: "Cron Executed", icon: Timer, color: "text-muted-foreground" },
  smtp_settings_updated: { label: "SMTP Settings Updated", icon: Settings, color: "text-primary" },
  notification_settings_updated: { label: "Notification Settings Updated", icon: Bell, color: "text-primary" },
};

// Scheduled Campaign Card with Countdown Timer
function ScheduledCampaignCard({ campaign, isPast }: { campaign: ScheduledCampaign; isPast: boolean }) {
  const [countdown, setCountdown] = useState<string>("");

  useEffect(() => {
    const updateCountdown = () => {
      const now = new Date();
      const scheduledDate = new Date(campaign.scheduled_at);
      
      if (scheduledDate <= now) {
        setCountdown("Overdue");
        return;
      }

      const diffSecs = differenceInSeconds(scheduledDate, now);
      const diffMins = differenceInMinutes(scheduledDate, now);
      const diffHours = differenceInHours(scheduledDate, now);
      const diffDays = differenceInDays(scheduledDate, now);

      if (diffDays > 0) {
        const hours = diffHours % 24;
        setCountdown(`${diffDays}d ${hours}h`);
      } else if (diffHours > 0) {
        const mins = diffMins % 60;
        setCountdown(`${diffHours}h ${mins}m`);
      } else if (diffMins > 0) {
        const secs = diffSecs % 60;
        setCountdown(`${diffMins}m ${secs}s`);
      } else {
        setCountdown(`${diffSecs}s`);
      }
    };

    updateCountdown();
    const interval = setInterval(updateCountdown, 1000);
    return () => clearInterval(interval);
  }, [campaign.scheduled_at]);

  return (
    <div className={cn(
      "rounded-lg border p-4 transition-colors",
      isPast ? "border-warning bg-warning/5" : "border-border bg-muted/30"
    )}>
      <div className="flex items-start justify-between mb-3">
        <div className={cn(
          "flex h-8 w-8 items-center justify-center rounded-lg",
          campaign.type === "sms" ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
        )}>
          {campaign.type === "sms" ? <MessageSquare className="h-4 w-4" /> : <Mail className="h-4 w-4" />}
        </div>
        <div className={cn(
          "text-right",
          isPast ? "text-warning" : "text-primary"
        )}>
          <div className="text-lg font-bold font-mono">{countdown}</div>
          <div className="text-xs text-muted-foreground">
            {isPast ? "overdue" : "remaining"}
          </div>
        </div>
      </div>
      <h4 className="font-medium text-foreground truncate mb-1">{campaign.name}</h4>
      <div className="flex items-center justify-between text-xs text-muted-foreground">
        <span>{campaign.user?.name || "Unknown"}</span>
        <span className="flex items-center gap-1">
          <Users className="h-3 w-3" />
          {campaign.total_recipients}
        </span>
      </div>
      <div className="mt-2 text-xs text-muted-foreground">
        {format(new Date(campaign.scheduled_at), "MMM d, yyyy 'at' h:mm a")}
      </div>
    </div>
  );
}

export default function AdminDashboard() {
  const navigate = useNavigate();
  const { user, isLoading: authLoading } = useAuth();
  const [users, setUsers] = useState<User[]>([]);
  const [senderIds, setSenderIds] = useState<SenderId[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [auditLogs, setAuditLogs] = useState<AuditLog[]>([]);
  const [scheduledCampaigns, setScheduledCampaigns] = useState<ScheduledCampaign[]>([]);
  const [cronJobs, setCronJobs] = useState<CronJob[]>([]);
  const [smtpSettings, setSmtpSettings] = useState<SmtpSetting[]>([]);
  const [notificationSettings, setNotificationSettings] = useState<NotificationSetting[]>([]);
  const [loading, setLoading] = useState(true);
  const [logsLoading, setLogsLoading] = useState(false);
  const [runningCron, setRunningCron] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [actionFilter, setActionFilter] = useState("all");
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [confirmAction, setConfirmAction] = useState<{ type: string; id: string; data?: any } | null>(null);
  const [accessDenied, setAccessDenied] = useState(false);
  
  // Date range filter for audit logs
  const [dateFrom, setDateFrom] = useState<Date | undefined>(undefined);
  const [dateTo, setDateTo] = useState<Date | undefined>(undefined);
  
  // SMTP Settings state
  const [editingSmtp, setEditingSmtp] = useState<"system" | "campaign" | null>(null);
  const [smtpForm, setSmtpForm] = useState<Partial<SmtpSetting>>({});
  const [smtpSaving, setSmtpSaving] = useState(false);
  const [smtpTesting, setSmtpTesting] = useState<string | null>(null);
  const [testEmail, setTestEmail] = useState("");
  const [showPasswords, setShowPasswords] = useState<Record<string, boolean>>({});
  
  // Notification settings state
  const [notificationSaving, setNotificationSaving] = useState<string | null>(null);
  
  // System health and heatmap state
  const [systemHealth, setSystemHealth] = useState<SystemHealth | null>(null);
  const [heatmapData, setHeatmapData] = useState<HeatmapData | null>(null);
  const [healthLoading, setHealthLoading] = useState(false);
  
  // Contact emails state
  const [contactEmails, setContactEmails] = useState<ContactEmail[]>([]);
  const [contactEmailsLoading, setContactEmailsLoading] = useState(false);
  const [unreadContactCount, setUnreadContactCount] = useState(0);
  const [selectedEmail, setSelectedEmail] = useState<ContactEmail | null>(null);
  const [emailNotes, setEmailNotes] = useState("");
  const [contactEmailStats, setContactEmailStats] = useState<ContactEmailStats | null>(null);

  // Admin access verification
  useEffect(() => {
    if (authLoading) return;

    // Check for admin session token
    const adminSession = sessionStorage.getItem("admin_session");
    const isAdminUser = user?.email === "admin@ieosuia.com";

    if (!adminSession || !isAdminUser) {
      setAccessDenied(true);
      return;
    }

    // Verify session token format (basic validation)
    try {
      const decoded = atob(adminSession);
      if (!decoded.includes("-admin")) {
        setAccessDenied(true);
        return;
      }
    } catch {
      setAccessDenied(true);
      return;
    }

    setAccessDenied(false);
    loadData();
  }, [authLoading, user]);

  // Show access denied screen
  if (accessDenied) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <div className="text-center max-w-md mx-auto p-8">
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-destructive/10 mx-auto mb-6">
            <ShieldAlert className="h-10 w-10 text-destructive" />
          </div>
          <h1 className="text-2xl font-bold text-foreground mb-2">Access Denied</h1>
          <p className="text-muted-foreground mb-6">
            You don't have permission to access the admin dashboard. Please login with admin credentials.
          </p>
          <Button onClick={() => navigate("/login")} className="gap-2">
            <Shield className="h-4 w-4" />
            Go to Login
          </Button>
        </div>
      </div>
    );
  }

  const loadData = async () => {
    setLoading(true);
    try {
      const [usersRes, senderIdsRes, statsRes, logsRes, cronRes, scheduledRes, smtpRes, notifRes, healthRes, heatmapRes, contactRes, contactStatsRes] = await Promise.all([
        api.get<{ users: User[] }>("/admin/users"),
        api.get<{ sender_ids: SenderId[] }>("/admin/sender-ids"),
        api.get<Stats>("/admin/stats"),
        api.get<{ logs: AuditLog[] }>("/admin/audit-logs?per_page=100"),
        api.get<{ jobs: CronJob[] }>("/admin/cron/status"),
        api.get<{ campaigns: ScheduledCampaign[] }>("/admin/cron/pending-campaigns"),
        api.get<{ settings: SmtpSetting[] }>("/admin/smtp-settings"),
        api.get<{ settings: NotificationSetting[] }>("/admin/notification-settings"),
        api.get<{ health: SystemHealth }>("/admin/system-health"),
        api.get<{ heatmap: HeatmapData }>("/admin/activity-heatmap"),
        api.get<{ emails: ContactEmail[]; unread_count: number }>("/admin/contact-emails"),
        api.get<{ stats: ContactEmailStats }>("/admin/contact-emails/stats"),
      ]);

      if (usersRes.success) setUsers(usersRes.data?.users || []);
      if (senderIdsRes.success) setSenderIds(senderIdsRes.data?.sender_ids || []);
      if (statsRes.success) setStats(statsRes.data || null);
      if (logsRes.success) setAuditLogs(logsRes.data?.logs || []);
      if (cronRes.success) setCronJobs(cronRes.data?.jobs || []);
      if (scheduledRes.success) setScheduledCampaigns(scheduledRes.data?.campaigns || []);
      if (smtpRes.success) setSmtpSettings(smtpRes.data?.settings || []);
      if (notifRes.success) setNotificationSettings(notifRes.data?.settings || []);
      if (healthRes.success) setSystemHealth(healthRes.data?.health || null);
      if (heatmapRes.success) setHeatmapData(heatmapRes.data?.heatmap || null);
      if (contactRes.success) {
        setContactEmails(contactRes.data?.emails || []);
        setUnreadContactCount(contactRes.data?.unread_count || 0);
      }
      if (contactStatsRes.success) setContactEmailStats(contactStatsRes.data?.stats || null);
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  };
  
  const refreshHealth = async () => {
    setHealthLoading(true);
    try {
      const res = await api.get<{ health: SystemHealth }>("/admin/system-health");
      if (res.success) setSystemHealth(res.data?.health || null);
    } catch (error) {
      handleApiError(error);
    } finally {
      setHealthLoading(false);
    }
  };

  const loadLogsWithFilters = async () => {
    setLogsLoading(true);
    try {
      const params = new URLSearchParams({ per_page: "100" });
      if (actionFilter !== "all") params.set("action", actionFilter);
      if (dateFrom) params.set("from_date", format(dateFrom, "yyyy-MM-dd"));
      if (dateTo) params.set("to_date", format(dateTo, "yyyy-MM-dd"));
      
      const res = await api.get<{ logs: AuditLog[] }>(`/admin/audit-logs?${params.toString()}`);
      if (res.success) setAuditLogs(res.data?.logs || []);
    } catch (error) {
      handleApiError(error);
    } finally {
      setLogsLoading(false);
    }
  };

  // Reload logs when filters change
  useEffect(() => {
    if (!loading && !accessDenied) {
      loadLogsWithFilters();
    }
  }, [actionFilter, dateFrom, dateTo]);

  const handleRunScheduledCampaigns = async () => {
    setRunningCron(true);
    try {
      const res = await api.post<{ message: string; results: any }>("/admin/cron/run-scheduled");
      if (res.success) {
        toast({
          title: "Scheduled Campaigns Processed",
          description: res.data?.message || "Campaigns have been processed",
        });
        loadData();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setRunningCron(false);
    }
  };

  const loadMoreLogs = async () => {
    setLogsLoading(true);
    try {
      const page = Math.floor(auditLogs.length / 100) + 1;
      const params = new URLSearchParams({ page: String(page), per_page: "100" });
      if (actionFilter !== "all") params.set("action", actionFilter);
      if (dateFrom) params.set("from_date", format(dateFrom, "yyyy-MM-dd"));
      if (dateTo) params.set("to_date", format(dateTo, "yyyy-MM-dd"));
      
      const res = await api.get<{ logs: AuditLog[] }>(`/admin/audit-logs?${params.toString()}`);
      if (res.success && res.data?.logs) {
        setAuditLogs(prev => [...prev, ...res.data!.logs]);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLogsLoading(false);
    }
  };

  const handleApproveSenderId = async (id: string) => {
    setActionLoading(`approve-${id}`);
    try {
      await api.post(`/admin/sender-ids/${id}/approve`);
      setSenderIds(prev => prev.map(s => s.id === id ? { ...s, status: "approved" } : s));
      toast({ title: "Sender ID approved" });
    } catch (error) {
      handleApiError(error);
    } finally {
      setActionLoading(null);
    }
  };

  const handleRejectSenderId = async (id: string) => {
    setActionLoading(`reject-${id}`);
    try {
      await api.post(`/admin/sender-ids/${id}/reject`);
      setSenderIds(prev => prev.map(s => s.id === id ? { ...s, status: "rejected" } : s));
      toast({ title: "Sender ID rejected" });
    } catch (error) {
      handleApiError(error);
    } finally {
      setActionLoading(null);
    }
  };

  const handleToggleUserStatus = async (userId: string, activate: boolean) => {
    setActionLoading(`user-${userId}`);
    try {
      await api.post(`/admin/users/${userId}/${activate ? "activate" : "deactivate"}`);
      setUsers(prev => prev.map(u => u.id === userId ? { ...u, is_active: activate } : u));
      toast({ title: `User ${activate ? "activated" : "deactivated"}` });
    } catch (error) {
      handleApiError(error);
    } finally {
      setActionLoading(null);
      setConfirmAction(null);
    }
  };

  const handleChangeUserRole = async (userId: string, role: string) => {
    setActionLoading(`role-${userId}`);
    try {
      await api.put(`/admin/users/${userId}/role`, { role });
      setUsers(prev => prev.map(u => u.id === userId ? { ...u, role: role as User["role"] } : u));
      toast({ title: "User role updated" });
    } catch (error) {
      handleApiError(error);
    } finally {
      setActionLoading(null);
    }
  };

  // SMTP Settings handlers
  const handleEditSmtp = (type: "system" | "campaign") => {
    const setting = smtpSettings.find(s => s.setting_type === type);
    setSmtpForm(setting || {
      setting_type: type,
      host: "sms.ieosuia.com",
      port: 465,
      encryption: "ssl",
      username: type === "system" ? "noreply@sms.ieosuia.com" : "email@sms.ieosuia.com",
      password: "",
      from_email: type === "system" ? "noreply@sms.ieosuia.com" : "email@sms.ieosuia.com",
      from_name: "IEOSUIA Portal",
    });
    setEditingSmtp(type);
  };

  const handleSaveSmtp = async () => {
    if (!editingSmtp || !smtpForm.host || !smtpForm.username || !smtpForm.from_email) {
      toast({ title: "Please fill in all required fields", variant: "destructive" });
      return;
    }
    
    setSmtpSaving(true);
    try {
      const res = await api.put(`/admin/smtp-settings/${editingSmtp}`, smtpForm);
      if (res.success) {
        toast({ title: "SMTP settings saved successfully" });
        setEditingSmtp(null);
        loadData();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSmtpSaving(false);
    }
  };

  const handleTestSmtp = async (type: "system" | "campaign") => {
    const email = testEmail || user?.email;
    if (!email) {
      toast({ title: "Please enter a test email address", variant: "destructive" });
      return;
    }
    
    setSmtpTesting(type);
    try {
      const setting = smtpSettings.find(s => s.setting_type === type);
      const res = await api.post<{ message: string }>(`/admin/smtp-settings/${type}/test`, {
        ...setting,
        test_email: email,
      });
      if (res.success) {
        toast({ 
          title: "Test email sent!", 
          description: res.data?.message || `Check ${email} for the test email.` 
        });
        loadData();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSmtpTesting(null);
    }
  };

  // Notification settings handlers
  const handleToggleNotification = async (eventType: string, field: "is_enabled" | "notify_email" | "notify_inapp", value: boolean) => {
    setNotificationSaving(eventType);
    try {
      const res = await api.put(`/admin/notification-settings/${eventType}`, { [field]: value });
      if (res.success) {
        setNotificationSettings(prev => 
          prev.map(s => s.event_type === eventType ? { ...s, [field]: value } : s)
        );
        toast({ title: "Notification setting updated" });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setNotificationSaving(null);
    }
  };

  const filteredSenderIds = senderIds.filter(s => {
    const matchesStatus = statusFilter === "all" || s.status === statusFilter;
    const matchesSearch = 
      (s.sender_id?.toLowerCase().includes(searchQuery.toLowerCase()) || 
       s.sender_email?.toLowerCase().includes(searchQuery.toLowerCase()) ||
       s.user_email?.toLowerCase().includes(searchQuery.toLowerCase()));
    return matchesStatus && matchesSearch;
  });

  const filteredUsers = users.filter(u =>
    u.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    u.email.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const pendingCount = senderIds.filter(s => s.status === "pending").length;

  const clearDateFilters = () => {
    setDateFrom(undefined);
    setDateTo(undefined);
  };

  return (
    <DashboardLayout
      title="Admin Dashboard"
      subtitle="Manage users, sender IDs, and system settings"
      actions={
        <Button variant="outline" onClick={loadData} disabled={loading} className="gap-2">
          <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
          Refresh
        </Button>
      }
    >
      {/* Stats */}
      <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-xl border border-border bg-card p-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
              <Users className="h-5 w-5 text-primary" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Total Users</p>
              <p className="text-2xl font-bold text-foreground">{stats?.total_users || 0}</p>
            </div>
          </div>
        </div>
        <div className="rounded-xl border border-border bg-card p-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-warning/10">
              <Key className="h-5 w-5 text-warning" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Pending Approvals</p>
              <p className="text-2xl font-bold text-foreground">{pendingCount}</p>
            </div>
          </div>
        </div>
        <div className="rounded-xl border border-border bg-card p-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-success/10">
              <BarChart3 className="h-5 w-5 text-success" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Total Campaigns</p>
              <p className="text-2xl font-bold text-foreground">{stats?.total_campaigns || 0}</p>
            </div>
          </div>
        </div>
        <div className="rounded-xl border border-border bg-card p-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10">
              <Wallet className="h-5 w-5 text-accent" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Total Revenue</p>
              <p className="text-2xl font-bold text-foreground">R{(stats?.total_revenue || 0).toFixed(2)}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Quick Actions & Recent Activity Widgets */}
      <div className="mb-8 grid gap-6 lg:grid-cols-2">
        {/* Pending Approvals Quick Actions */}
        <div className="rounded-xl border border-border bg-card">
          <div className="flex items-center justify-between border-b border-border px-6 py-4">
            <div className="flex items-center gap-2">
              <Key className="h-5 w-5 text-warning" />
              <h3 className="font-semibold text-foreground">Pending Approvals</h3>
              {pendingCount > 0 && (
                <span className="rounded-full bg-warning px-2 py-0.5 text-xs font-medium text-warning-foreground">
                  {pendingCount}
                </span>
              )}
            </div>
          </div>
          <div className="p-4">
            {senderIds.filter(s => s.status === "pending").length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <CheckCircle className="h-10 w-10 text-success/50 mb-2" />
                <p className="text-sm text-muted-foreground">All caught up! No pending approvals.</p>
              </div>
            ) : (
              <div className="space-y-3">
                {senderIds.filter(s => s.status === "pending").slice(0, 5).map((sender) => (
                  <div key={sender.id} className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3">
                    <div className="flex items-center gap-3">
                      <div className={cn(
                        "flex h-8 w-8 items-center justify-center rounded-lg",
                        sender.type === "sms" ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
                      )}>
                        {sender.type === "sms" ? <MessageSquare className="h-4 w-4" /> : <Mail className="h-4 w-4" />}
                      </div>
                      <div>
                        <p className="text-sm font-medium text-foreground">
                          {sender.sender_id || sender.sender_email}
                        </p>
                        <p className="text-xs text-muted-foreground">{sender.user_email}</p>
                      </div>
                    </div>
                    <div className="flex gap-1">
                      <Button
                        size="sm"
                        variant="ghost"
                        className="h-8 w-8 p-0 text-success hover:bg-success/10 hover:text-success"
                        onClick={() => handleApproveSenderId(sender.id)}
                        disabled={actionLoading === `approve-${sender.id}`}
                      >
                        {actionLoading === `approve-${sender.id}` ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <Check className="h-4 w-4" />
                        )}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="h-8 w-8 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => handleRejectSenderId(sender.id)}
                        disabled={actionLoading === `reject-${sender.id}`}
                      >
                        {actionLoading === `reject-${sender.id}` ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <X className="h-4 w-4" />
                        )}
                      </Button>
                    </div>
                  </div>
                ))}
                {senderIds.filter(s => s.status === "pending").length > 5 && (
                  <p className="text-center text-xs text-muted-foreground pt-2">
                    +{senderIds.filter(s => s.status === "pending").length - 5} more pending
                  </p>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Recent Activity */}
        <div className="rounded-xl border border-border bg-card">
          <div className="flex items-center justify-between border-b border-border px-6 py-4">
            <div className="flex items-center gap-2">
              <Activity className="h-5 w-5 text-primary" />
              <h3 className="font-semibold text-foreground">Recent Activity</h3>
            </div>
          </div>
          <div className="p-4">
            {auditLogs.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Activity className="h-10 w-10 text-muted-foreground/50 mb-2" />
                <p className="text-sm text-muted-foreground">No recent activity</p>
              </div>
            ) : (
              <div className="space-y-3">
                {auditLogs.slice(0, 5).map((log) => {
                  const config = actionConfig[log.action] || { 
                    label: log.action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()), 
                    icon: Activity, 
                    color: "text-muted-foreground" 
                  };
                  const ActionIcon = config.icon;
                  
                  return (
                    <div key={log.id} className="flex items-start gap-3 rounded-lg border border-border bg-muted/30 p-3">
                      <div className={cn("flex h-8 w-8 items-center justify-center rounded-lg bg-muted", config.color)}>
                        <ActionIcon className="h-4 w-4" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-foreground">{config.label}</p>
                        <p className="text-xs text-muted-foreground truncate">
                          {log.user_name || "System"} • {log.entity_type} {log.entity_id ? `#${log.entity_id}` : ""}
                        </p>
                      </div>
                      <div className="text-xs text-muted-foreground whitespace-nowrap">
                        {(() => {
                          const date = new Date(log.created_at);
                          const now = new Date();
                          const diffMs = now.getTime() - date.getTime();
                          const diffMins = Math.floor(diffMs / 60000);
                          const diffHours = Math.floor(diffMs / 3600000);
                          const diffDays = Math.floor(diffMs / 86400000);
                          
                          if (diffMins < 1) return "Just now";
                          if (diffMins < 60) return `${diffMins}m ago`;
                          if (diffHours < 24) return `${diffHours}h ago`;
                          return `${diffDays}d ago`;
                        })()}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Scheduled Campaigns Widget */}
      {scheduledCampaigns.length > 0 && (
        <div className="mb-8 rounded-xl border border-border bg-card">
          <div className="flex items-center justify-between border-b border-border px-6 py-4">
            <div className="flex items-center gap-2">
              <Timer className="h-5 w-5 text-primary" />
              <h3 className="font-semibold text-foreground">Upcoming Scheduled Campaigns</h3>
              <span className="rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">
                {scheduledCampaigns.length}
              </span>
            </div>
            <Button 
              size="sm"
              onClick={handleRunScheduledCampaigns} 
              disabled={runningCron}
              className="gap-2"
            >
              {runningCron ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Play className="h-4 w-4" />
              )}
              Process Now
            </Button>
          </div>
          <div className="p-4">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {scheduledCampaigns.slice(0, 6).map((campaign) => {
                const scheduledDate = new Date(campaign.scheduled_at);
                const now = new Date();
                const isPast = scheduledDate <= now;
                
                return (
                  <ScheduledCampaignCard 
                    key={campaign.id} 
                    campaign={campaign} 
                    isPast={isPast} 
                  />
                );
              })}
            </div>
            {scheduledCampaigns.length > 6 && (
              <p className="text-center text-sm text-muted-foreground mt-4">
                +{scheduledCampaigns.length - 6} more scheduled campaigns
              </p>
            )}
          </div>
        </div>
      )}

      {/* System Health & Activity Heatmap Widgets */}
      <div className="mb-8 grid gap-6 lg:grid-cols-2">
        {/* System Health Widget */}
        <div className="rounded-xl border border-border bg-card">
          <div className="flex items-center justify-between border-b border-border px-6 py-4">
            <div className="flex items-center gap-2">
              <Thermometer className={cn(
                "h-5 w-5",
                systemHealth?.overall === 'healthy' ? "text-success" : 
                systemHealth?.overall === 'warning' ? "text-warning" : "text-destructive"
              )} />
              <h3 className="font-semibold text-foreground">System Health</h3>
              {systemHealth && (
                <span className={cn(
                  "rounded-full px-2 py-0.5 text-xs font-medium",
                  systemHealth.overall === 'healthy' ? "bg-success/10 text-success" :
                  systemHealth.overall === 'warning' ? "bg-warning/10 text-warning" :
                  "bg-destructive/10 text-destructive"
                )}>
                  {systemHealth.overall.charAt(0).toUpperCase() + systemHealth.overall.slice(1)}
                </span>
              )}
            </div>
            <Button 
              size="sm" 
              variant="outline" 
              onClick={refreshHealth}
              disabled={healthLoading}
              className="gap-2"
            >
              <RefreshCw className={cn("h-4 w-4", healthLoading && "animate-spin")} />
              Refresh
            </Button>
          </div>
          <div className="p-4">
            {!systemHealth ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
              </div>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2">
                {/* Database Status */}
                <div className={cn(
                  "rounded-lg border p-3",
                  systemHealth.database.status === 'healthy' ? "border-success/30 bg-success/5" :
                  systemHealth.database.status === 'warning' ? "border-warning/30 bg-warning/5" :
                  "border-destructive/30 bg-destructive/5"
                )}>
                  <div className="flex items-center gap-2 mb-2">
                    <Database className={cn(
                      "h-4 w-4",
                      systemHealth.database.status === 'healthy' ? "text-success" :
                      systemHealth.database.status === 'warning' ? "text-warning" : "text-destructive"
                    )} />
                    <span className="font-medium text-foreground">Database</span>
                  </div>
                  <p className="text-sm text-muted-foreground">{systemHealth.database.message}</p>
                  {systemHealth.database.response_time_ms && (
                    <p className="text-xs text-muted-foreground mt-1">
                      Response: {systemHealth.database.response_time_ms}ms
                    </p>
                  )}
                </div>

                {/* SMTP Status */}
                <div className={cn(
                  "rounded-lg border p-3",
                  systemHealth.smtp.status === 'healthy' ? "border-success/30 bg-success/5" :
                  systemHealth.smtp.status === 'warning' ? "border-warning/30 bg-warning/5" :
                  "border-destructive/30 bg-destructive/5"
                )}>
                  <div className="flex items-center gap-2 mb-2">
                    <Mail className={cn(
                      "h-4 w-4",
                      systemHealth.smtp.status === 'healthy' ? "text-success" :
                      systemHealth.smtp.status === 'warning' ? "text-warning" : "text-destructive"
                    )} />
                    <span className="font-medium text-foreground">SMTP</span>
                  </div>
                  <p className="text-sm text-muted-foreground">{systemHealth.smtp.message}</p>
                  {systemHealth.smtp.host && (
                    <p className="text-xs text-muted-foreground mt-1">
                      Host: {systemHealth.smtp.host}
                    </p>
                  )}
                </div>

                {/* API Status */}
                <div className={cn(
                  "rounded-lg border p-3",
                  systemHealth.api.status === 'healthy' ? "border-success/30 bg-success/5" :
                  systemHealth.api.status === 'warning' ? "border-warning/30 bg-warning/5" :
                  "border-destructive/30 bg-destructive/5"
                )}>
                  <div className="flex items-center gap-2 mb-2">
                    {systemHealth.api.status === 'healthy' ? (
                      <Wifi className="h-4 w-4 text-success" />
                    ) : systemHealth.api.status === 'warning' ? (
                      <Wifi className="h-4 w-4 text-warning" />
                    ) : (
                      <WifiOff className="h-4 w-4 text-destructive" />
                    )}
                    <span className="font-medium text-foreground">API</span>
                  </div>
                  <p className="text-sm text-muted-foreground">{systemHealth.api.message}</p>
                  {systemHealth.api.response_time_ms && (
                    <p className="text-xs text-muted-foreground mt-1">
                      Response: {systemHealth.api.response_time_ms}ms
                      {systemHealth.api.details?.error_rate && ` • Errors: ${systemHealth.api.details.error_rate}`}
                    </p>
                  )}
                </div>

                {/* Storage Status */}
                <div className={cn(
                  "rounded-lg border p-3",
                  systemHealth.storage.status === 'healthy' ? "border-success/30 bg-success/5" :
                  systemHealth.storage.status === 'warning' ? "border-warning/30 bg-warning/5" :
                  "border-destructive/30 bg-destructive/5"
                )}>
                  <div className="flex items-center gap-2 mb-2">
                    <HardDrive className={cn(
                      "h-4 w-4",
                      systemHealth.storage.status === 'healthy' ? "text-success" :
                      systemHealth.storage.status === 'warning' ? "text-warning" : "text-destructive"
                    )} />
                    <span className="font-medium text-foreground">Storage</span>
                  </div>
                  <p className="text-sm text-muted-foreground">{systemHealth.storage.message}</p>
                  {systemHealth.storage.details && (
                    <p className="text-xs text-muted-foreground mt-1">
                      {systemHealth.storage.details.free_space_gb}GB free • {systemHealth.storage.details.used_percent} used
                    </p>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Activity Heatmap Widget */}
        <div className="rounded-xl border border-border bg-card">
          <div className="flex items-center justify-between border-b border-border px-6 py-4">
            <div className="flex items-center gap-2">
              <TrendingUp className="h-5 w-5 text-primary" />
              <h3 className="font-semibold text-foreground">Activity Heatmap</h3>
              <span className="text-xs text-muted-foreground">(Last 30 days)</span>
            </div>
          </div>
          <div className="p-4">
            {!heatmapData ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
              </div>
            ) : (
              <div className="space-y-4">
                {/* Registrations Heatmap */}
                <div>
                  <p className="text-sm font-medium text-foreground mb-2 flex items-center gap-2">
                    <Users className="h-4 w-4 text-primary" />
                    Registrations
                  </p>
                  <div className="flex gap-1">
                    <div className="flex flex-col gap-1 text-[10px] text-muted-foreground pr-1">
                      {heatmapData.registrations.map((day) => (
                        <div key={day.day} className="h-3 flex items-center">{day.day}</div>
                      ))}
                    </div>
                    <div className="flex-1 overflow-x-auto">
                      <div className="flex flex-col gap-1 min-w-[384px]">
                        {heatmapData.registrations.map((day) => {
                          const maxVal = Math.max(...day.hours, 1);
                          return (
                            <div key={day.day} className="flex gap-[2px]">
                              {day.hours.map((count, hour) => (
                                <div
                                  key={hour}
                                  className={cn(
                                    "w-4 h-3 rounded-[2px]",
                                    count === 0 ? "bg-muted" :
                                    count / maxVal < 0.25 ? "bg-primary/20" :
                                    count / maxVal < 0.5 ? "bg-primary/40" :
                                    count / maxVal < 0.75 ? "bg-primary/60" :
                                    "bg-primary"
                                  )}
                                  title={`${day.day} ${hour}:00 - ${count} registrations`}
                                />
                              ))}
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Campaigns Heatmap */}
                <div>
                  <p className="text-sm font-medium text-foreground mb-2 flex items-center gap-2">
                    <MessageSquare className="h-4 w-4 text-success" />
                    Campaigns Created
                  </p>
                  <div className="flex gap-1">
                    <div className="flex flex-col gap-1 text-[10px] text-muted-foreground pr-1">
                      {heatmapData.campaigns.map((day) => (
                        <div key={day.day} className="h-3 flex items-center">{day.day}</div>
                      ))}
                    </div>
                    <div className="flex-1 overflow-x-auto">
                      <div className="flex flex-col gap-1 min-w-[384px]">
                        {heatmapData.campaigns.map((day) => {
                          const maxVal = Math.max(...day.hours, 1);
                          return (
                            <div key={day.day} className="flex gap-[2px]">
                              {day.hours.map((count, hour) => (
                                <div
                                  key={hour}
                                  className={cn(
                                    "w-4 h-3 rounded-[2px]",
                                    count === 0 ? "bg-muted" :
                                    count / maxVal < 0.25 ? "bg-success/20" :
                                    count / maxVal < 0.5 ? "bg-success/40" :
                                    count / maxVal < 0.75 ? "bg-success/60" :
                                    "bg-success"
                                  )}
                                  title={`${day.day} ${hour}:00 - ${count} campaigns`}
                                />
                              ))}
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Hour labels */}
                <div className="flex gap-1 pl-7">
                  <div className="flex gap-[2px] text-[8px] text-muted-foreground min-w-[384px]">
                    {Array.from({ length: 24 }, (_, i) => (
                      <div key={i} className="w-4 text-center">
                        {i % 4 === 0 ? `${i}` : ''}
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Message Delivery Heatmap Widget - Full Width */}
      <div className="mb-8 rounded-xl border border-border bg-card">
        <div className="flex items-center justify-between border-b border-border px-6 py-4">
          <div className="flex items-center gap-2">
            <Send className="h-5 w-5 text-success" />
            <h3 className="font-semibold text-foreground">Message Delivery Success</h3>
            <span className="text-xs text-muted-foreground">(Last 30 days)</span>
          </div>
          <div className="flex items-center gap-2">
            {/* Export buttons */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button size="sm" variant="outline" className="gap-2">
                  <Download className="h-4 w-4" />
                  Export
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="bg-popover">
                <DropdownMenuItem
                  onClick={() => {
                    const token = localStorage.getItem('auth_token');
                    window.open(`${import.meta.env.VITE_API_URL || 'https://sms.ieosuia.com/api'}/admin/heatmap/export?format=csv&token=${token}`, '_blank');
                  }}
                >
                  <FileSpreadsheet className="h-4 w-4 mr-2" />
                  Export as CSV
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() => {
                    const token = localStorage.getItem('auth_token');
                    window.open(`${import.meta.env.VITE_API_URL || 'https://sms.ieosuia.com/api'}/admin/heatmap/export?format=pdf&token=${token}`, '_blank');
                  }}
                >
                  <FileText className="h-4 w-4 mr-2" />
                  Export as PDF
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
            
            {/* Legend */}
            <div className="hidden sm:flex items-center gap-4 text-xs text-muted-foreground ml-4">
              <div className="flex items-center gap-1">
                <div className="w-3 h-3 rounded-sm bg-muted" />
                <span>No data</span>
              </div>
              <div className="flex items-center gap-1">
                <div className="w-3 h-3 rounded-sm bg-destructive/40" />
                <span>&lt;50%</span>
              </div>
              <div className="flex items-center gap-1">
                <div className="w-3 h-3 rounded-sm bg-warning/60" />
                <span>50-80%</span>
              </div>
              <div className="flex items-center gap-1">
                <div className="w-3 h-3 rounded-sm bg-success" />
                <span>&gt;90%</span>
              </div>
            </div>
          </div>
        </div>
        <div className="p-4">
          {!heatmapData ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <div className="space-y-4">
              <div className="grid gap-6 lg:grid-cols-2">
                {/* Delivered Messages Heatmap */}
                <div>
                  <p className="text-sm font-medium text-foreground mb-2 flex items-center gap-2">
                    <CheckCircle className="h-4 w-4 text-success" />
                    Delivered Messages
                  </p>
                  <div className="flex gap-1">
                    <div className="flex flex-col gap-1 text-[10px] text-muted-foreground pr-1">
                      {heatmapData.delivered.map((day) => (
                        <div key={day.day} className="h-3 flex items-center">{day.day}</div>
                      ))}
                    </div>
                    <div className="flex-1 overflow-x-auto">
                      <div className="flex flex-col gap-1 min-w-[384px]">
                        {heatmapData.delivered.map((day) => {
                          const maxVal = Math.max(...day.hours, 1);
                          return (
                            <div key={day.day} className="flex gap-[2px]">
                              {day.hours.map((count, hour) => (
                                <div
                                  key={hour}
                                  className={cn(
                                    "w-4 h-3 rounded-[2px]",
                                    count === 0 ? "bg-muted" :
                                    count / maxVal < 0.25 ? "bg-success/20" :
                                    count / maxVal < 0.5 ? "bg-success/40" :
                                    count / maxVal < 0.75 ? "bg-success/60" :
                                    "bg-success"
                                  )}
                                  title={`${day.day} ${hour}:00 - ${count} delivered`}
                                />
                              ))}
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Delivery Rate Heatmap */}
                <div>
                  <p className="text-sm font-medium text-foreground mb-2 flex items-center gap-2">
                    <TrendingUp className="h-4 w-4 text-primary" />
                    Delivery Success Rate (%)
                  </p>
                  <div className="flex gap-1">
                    <div className="flex flex-col gap-1 text-[10px] text-muted-foreground pr-1">
                      {heatmapData.delivery_rates.map((day) => (
                        <div key={day.day} className="h-3 flex items-center">{day.day}</div>
                      ))}
                    </div>
                    <div className="flex-1 overflow-x-auto">
                      <div className="flex flex-col gap-1 min-w-[384px]">
                        {heatmapData.delivery_rates.map((day) => (
                          <div key={day.day} className="flex gap-[2px]">
                            {day.hours.map((rate, hour) => (
                              <div
                                key={hour}
                                className={cn(
                                  "w-4 h-3 rounded-[2px]",
                                  rate === null ? "bg-muted" :
                                  rate < 50 ? "bg-destructive/60" :
                                  rate < 70 ? "bg-warning/40" :
                                  rate < 80 ? "bg-warning/60" :
                                  rate < 90 ? "bg-success/40" :
                                  "bg-success"
                                )}
                                title={rate === null ? `${day.day} ${hour}:00 - No data` : `${day.day} ${hour}:00 - ${rate}% success rate`}
                              />
                            ))}
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Failed Messages Heatmap */}
              <div>
                <p className="text-sm font-medium text-foreground mb-2 flex items-center gap-2">
                  <XCircle className="h-4 w-4 text-destructive" />
                  Failed Messages
                </p>
                <div className="flex gap-1">
                  <div className="flex flex-col gap-1 text-[10px] text-muted-foreground pr-1">
                    {heatmapData.failed.map((day) => (
                      <div key={day.day} className="h-3 flex items-center">{day.day}</div>
                    ))}
                  </div>
                  <div className="flex-1 overflow-x-auto">
                    <div className="flex flex-col gap-1 min-w-[384px]">
                      {heatmapData.failed.map((day) => {
                        const maxVal = Math.max(...day.hours, 1);
                        return (
                          <div key={day.day} className="flex gap-[2px]">
                            {day.hours.map((count, hour) => (
                              <div
                                key={hour}
                                className={cn(
                                  "w-4 h-3 rounded-[2px]",
                                  count === 0 ? "bg-muted" :
                                  count / maxVal < 0.25 ? "bg-destructive/20" :
                                  count / maxVal < 0.5 ? "bg-destructive/40" :
                                  count / maxVal < 0.75 ? "bg-destructive/60" :
                                  "bg-destructive"
                                )}
                                title={`${day.day} ${hour}:00 - ${count} failed`}
                              />
                            ))}
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </div>
              </div>

              {/* Hour labels */}
              <div className="flex gap-1 pl-7">
                <div className="flex gap-[2px] text-[8px] text-muted-foreground min-w-[384px]">
                  {Array.from({ length: 24 }, (_, i) => (
                    <div key={i} className="w-4 text-center">
                      {i % 4 === 0 ? `${i}h` : ''}
                    </div>
                  ))}
                </div>
              </div>

              {/* Best times insight */}
              {heatmapData.delivery_rates && (
                <div className="mt-4 rounded-lg border border-border bg-muted/30 p-4">
                  <p className="text-sm font-medium text-foreground mb-2">📈 Delivery Insights</p>
                  <p className="text-sm text-muted-foreground">
                    {(() => {
                      // Find best delivery times
                      let bestRate = 0;
                      let bestTimes: string[] = [];
                      
                      heatmapData.delivery_rates.forEach((day) => {
                        day.hours.forEach((rate, hour) => {
                          if (rate !== null && rate >= 90) {
                            if (rate > bestRate) {
                              bestRate = rate;
                              bestTimes = [`${day.day} at ${hour}:00`];
                            } else if (rate === bestRate) {
                              bestTimes.push(`${day.day} at ${hour}:00`);
                            }
                          }
                        });
                      });
                      
                      if (bestTimes.length === 0) {
                        return "Not enough data to determine optimal sending times. Send more campaigns to see insights.";
                      }
                      
                      return `Best delivery times (${bestRate}% success): ${bestTimes.slice(0, 3).join(", ")}${bestTimes.length > 3 ? ` and ${bestTimes.length - 3} more` : ""}`;
                    })()}
                  </p>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <Tabs defaultValue="sender-ids">
        <TabsList>
          <TabsTrigger value="sender-ids" className="gap-2">
            <Key className="h-4 w-4" />
            Sender IDs
            {pendingCount > 0 && (
              <span className="ml-1 rounded-full bg-warning px-2 py-0.5 text-xs text-warning-foreground">
                {pendingCount}
              </span>
            )}
          </TabsTrigger>
          <TabsTrigger value="users" className="gap-2">
            <Users className="h-4 w-4" />
            Users
          </TabsTrigger>
          <TabsTrigger value="scheduled" className="gap-2">
            <CalendarIcon className="h-4 w-4" />
            Scheduled
            {scheduledCampaigns.length > 0 && (
              <span className="ml-1 rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground">
                {scheduledCampaigns.length}
              </span>
            )}
          </TabsTrigger>
          <TabsTrigger value="activity" className="gap-2">
            <Activity className="h-4 w-4" />
            Activity Log
          </TabsTrigger>
          <TabsTrigger value="contact-emails" className="gap-2">
            <Inbox className="h-4 w-4" />
            Contact Emails
            {unreadContactCount > 0 && (
              <span className="ml-1 rounded-full bg-warning px-2 py-0.5 text-xs text-warning-foreground">
                {unreadContactCount}
              </span>
            )}
          </TabsTrigger>
          <TabsTrigger value="settings" className="gap-2">
            <Settings className="h-4 w-4" />
            Settings
          </TabsTrigger>
        </TabsList>

        <TabsContent value="sender-ids" className="mt-6">
          {/* Filters */}
          <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="relative flex-1 max-w-md">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search sender IDs..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-10"
              />
            </div>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-40">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="pending">Pending</SelectItem>
                <SelectItem value="approved">Approved</SelectItem>
                <SelectItem value="rejected">Rejected</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Sender IDs Table */}
          <div className="rounded-xl border border-border bg-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">User</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Type</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Sender ID</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Status</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Created</th>
                    <th className="px-6 py-4 text-right text-sm font-medium text-muted-foreground">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {filteredSenderIds.map((sender) => {
                    const StatusIcon = statusConfig[sender.status].icon;
                    return (
                      <tr key={sender.id} className="transition-colors hover:bg-muted/30">
                        <td className="px-6 py-4">
                          <p className="font-medium text-foreground">{sender.user_name || "User"}</p>
                          <p className="text-sm text-muted-foreground">{sender.user_email}</p>
                        </td>
                        <td className="px-6 py-4">
                          <span className={cn(
                            "inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium",
                            sender.type === "sms" ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
                          )}>
                            {sender.type === "sms" ? <MessageSquare className="h-3 w-3" /> : <Mail className="h-3 w-3" />}
                            {sender.type.toUpperCase()}
                          </span>
                        </td>
                        <td className="px-6 py-4 font-medium text-foreground">
                          {sender.sender_id || sender.sender_email}
                        </td>
                        <td className="px-6 py-4">
                          <span className={cn("status-badge inline-flex items-center gap-1", statusConfig[sender.status].class)}>
                            <StatusIcon className="h-3 w-3" />
                            {statusConfig[sender.status].label}
                          </span>
                        </td>
                        <td className="px-6 py-4 text-muted-foreground">
                          {new Date(sender.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-6 py-4">
                          <div className="flex items-center justify-end gap-2">
                            {sender.status === "pending" && (
                              <>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="gap-1 text-success border-success/50 hover:bg-success/10"
                                  onClick={() => handleApproveSenderId(sender.id)}
                                  disabled={actionLoading === `approve-${sender.id}`}
                                >
                                  {actionLoading === `approve-${sender.id}` ? (
                                    <Loader2 className="h-3 w-3 animate-spin" />
                                  ) : (
                                    <Check className="h-3 w-3" />
                                  )}
                                  Approve
                                </Button>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="gap-1 text-destructive border-destructive/50 hover:bg-destructive/10"
                                  onClick={() => handleRejectSenderId(sender.id)}
                                  disabled={actionLoading === `reject-${sender.id}`}
                                >
                                  {actionLoading === `reject-${sender.id}` ? (
                                    <Loader2 className="h-3 w-3 animate-spin" />
                                  ) : (
                                    <X className="h-3 w-3" />
                                  )}
                                  Reject
                                </Button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {filteredSenderIds.length === 0 && (
              <div className="flex flex-col items-center justify-center py-12">
                <Key className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-lg font-medium text-foreground">No sender IDs found</p>
              </div>
            )}
          </div>
        </TabsContent>

        <TabsContent value="users" className="mt-6">
          {/* Search */}
          <div className="mb-4">
            <div className="relative max-w-md">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search users..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-10"
              />
            </div>
          </div>

          {/* Users Table */}
          <div className="rounded-xl border border-border bg-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">User</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Role</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Status</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Verified</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Joined</th>
                    <th className="px-6 py-4 text-right text-sm font-medium text-muted-foreground">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {filteredUsers.map((user) => (
                    <tr key={user.id} className="transition-colors hover:bg-muted/30">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 font-medium text-primary">
                            {user.name.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <p className="font-medium text-foreground">{user.name}</p>
                            <p className="text-sm text-muted-foreground">{user.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <Select
                          value={user.role}
                          onValueChange={(role) => handleChangeUserRole(user.id, role)}
                          disabled={actionLoading === `role-${user.id}`}
                        >
                          <SelectTrigger className="w-28">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="user">User</SelectItem>
                            <SelectItem value="moderator">Moderator</SelectItem>
                            <SelectItem value="admin">Admin</SelectItem>
                          </SelectContent>
                        </Select>
                      </td>
                      <td className="px-6 py-4">
                        <span className={cn(
                          "status-badge",
                          user.is_active ? "status-delivered" : "status-failed"
                        )}>
                          {user.is_active ? "Active" : "Inactive"}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        {user.email_verified_at ? (
                          <CheckCircle className="h-5 w-5 text-success" />
                        ) : (
                          <XCircle className="h-5 w-5 text-muted-foreground" />
                        )}
                      </td>
                      <td className="px-6 py-4 text-muted-foreground">
                        {new Date(user.created_at).toLocaleDateString()}
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex justify-end">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="icon">
                                <MoreHorizontal className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() => setConfirmAction({
                                  type: user.is_active ? "deactivate" : "activate",
                                  id: user.id,
                                  data: { name: user.name, activate: !user.is_active }
                                })}
                              >
                                {user.is_active ? (
                                  <>
                                    <Ban className="mr-2 h-4 w-4" />
                                    Deactivate
                                  </>
                                ) : (
                                  <>
                                    <CheckCircle className="mr-2 h-4 w-4" />
                                    Activate
                                  </>
                                )}
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem>
                                <Mail className="mr-2 h-4 w-4" />
                                Send Email
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {filteredUsers.length === 0 && (
              <div className="flex flex-col items-center justify-center py-12">
                <Users className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-lg font-medium text-foreground">No users found</p>
              </div>
            )}
          </div>
        </TabsContent>

        <TabsContent value="scheduled" className="mt-6">
          {/* Cron Job Status */}
          <div className="mb-6 rounded-xl border border-border bg-card p-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                  <Timer className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground">Scheduled Campaign Processor</h3>
                  <p className="text-sm text-muted-foreground">
                    {cronJobs.find(j => j.job_name === 'process_scheduled_campaigns')?.last_run_at
                      ? `Last run: ${new Date(cronJobs.find(j => j.job_name === 'process_scheduled_campaigns')!.last_run_at!).toLocaleString()}`
                      : 'Never run'}
                    {cronJobs.find(j => j.job_name === 'process_scheduled_campaigns')?.run_count 
                      ? ` · ${cronJobs.find(j => j.job_name === 'process_scheduled_campaigns')!.run_count} total runs`
                      : ''}
                  </p>
                </div>
              </div>
              <Button 
                onClick={handleRunScheduledCampaigns} 
                disabled={runningCron}
                className="gap-2"
              >
                {runningCron ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Play className="h-4 w-4" />
                )}
                Run Now
              </Button>
            </div>
          </div>

          {/* Scheduled Campaigns Table */}
          <div className="rounded-xl border border-border bg-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Campaign</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">User</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Type</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Recipients</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Scheduled For</th>
                    <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {scheduledCampaigns.map((campaign) => {
                    const scheduledDate = new Date(campaign.scheduled_at);
                    const isPast = scheduledDate <= new Date();
                    return (
                      <tr key={campaign.id} className="transition-colors hover:bg-muted/30">
                        <td className="px-6 py-4">
                          <p className="font-medium text-foreground">{campaign.name}</p>
                        </td>
                        <td className="px-6 py-4">
                          <p className="text-foreground">{campaign.user?.name || "Unknown"}</p>
                          <p className="text-sm text-muted-foreground">{campaign.user?.email}</p>
                        </td>
                        <td className="px-6 py-4">
                          <span className={cn(
                            "inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium",
                            campaign.type === "sms" ? "bg-primary/10 text-primary" : "bg-accent/10 text-accent"
                          )}>
                            {campaign.type === "sms" ? <MessageSquare className="h-3 w-3" /> : <Mail className="h-3 w-3" />}
                            {campaign.type.toUpperCase()}
                          </span>
                        </td>
                        <td className="px-6 py-4 text-foreground">
                          {campaign.total_recipients}
                        </td>
                        <td className="px-6 py-4">
                          <p className={cn("font-medium", isPast ? "text-warning" : "text-foreground")}>
                            {scheduledDate.toLocaleDateString()}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            {scheduledDate.toLocaleTimeString()}
                          </p>
                        </td>
                        <td className="px-6 py-4">
                          <span className={cn(
                            "status-badge",
                            isPast ? "status-pending" : "status-delivered"
                          )}>
                            {isPast ? "Overdue" : "Scheduled"}
                          </span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {scheduledCampaigns.length === 0 && (
              <div className="py-12 text-center">
                <CalendarIcon className="mx-auto h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-lg font-medium text-foreground">No scheduled campaigns</p>
                <p className="text-sm text-muted-foreground">Campaigns scheduled for future sending will appear here</p>
              </div>
            )}
          </div>
        </TabsContent>

        <TabsContent value="activity" className="mt-6">
          {/* Filters and Export */}
          <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-wrap gap-2">
              <Select value={actionFilter} onValueChange={setActionFilter}>
                <SelectTrigger className="w-48">
                  <SelectValue placeholder="Filter by action" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Actions</SelectItem>
                  <SelectItem value="activate_user">Activate User</SelectItem>
                  <SelectItem value="deactivate_user">Deactivate User</SelectItem>
                  <SelectItem value="change_role">Change Role</SelectItem>
                  <SelectItem value="approve_sender_id">Approve Sender ID</SelectItem>
                  <SelectItem value="reject_sender_id">Reject Sender ID</SelectItem>
                  <SelectItem value="campaign_created">Campaign Created</SelectItem>
                  <SelectItem value="campaign_sent">Campaign Sent</SelectItem>
                  <SelectItem value="campaign_scheduled_sent">Scheduled Campaign Sent</SelectItem>
                  <SelectItem value="campaign_deleted">Campaign Deleted</SelectItem>
                  <SelectItem value="user_registered">User Registered</SelectItem>
                  <SelectItem value="cron_executed">Cron Executed</SelectItem>
                </SelectContent>
              </Select>
              
              {/* Date From */}
              <Popover>
                <PopoverTrigger asChild>
                  <Button variant="outline" className={cn("w-40 justify-start text-left font-normal", !dateFrom && "text-muted-foreground")}>
                    <CalendarIcon className="mr-2 h-4 w-4" />
                    {dateFrom ? format(dateFrom, "MMM d, yyyy") : "From date"}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-auto p-0" align="start">
                  <Calendar
                    mode="single"
                    selected={dateFrom}
                    onSelect={setDateFrom}
                    initialFocus
                    className="p-3 pointer-events-auto"
                  />
                </PopoverContent>
              </Popover>

              {/* Date To */}
              <Popover>
                <PopoverTrigger asChild>
                  <Button variant="outline" className={cn("w-40 justify-start text-left font-normal", !dateTo && "text-muted-foreground")}>
                    <CalendarIcon className="mr-2 h-4 w-4" />
                    {dateTo ? format(dateTo, "MMM d, yyyy") : "To date"}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-auto p-0" align="start">
                  <Calendar
                    mode="single"
                    selected={dateTo}
                    onSelect={setDateTo}
                    initialFocus
                    className="p-3 pointer-events-auto"
                  />
                </PopoverContent>
              </Popover>

              {(dateFrom || dateTo) && (
                <Button variant="ghost" size="sm" onClick={clearDateFilters} className="gap-1 text-muted-foreground">
                  <X className="h-4 w-4" />
                  Clear
                </Button>
              )}
            </div>
            
            <div className="flex gap-2">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" className="gap-2">
                    <Download className="h-4 w-4" />
                    Export
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem
                    onClick={() => {
                      const params = new URLSearchParams();
                      params.set('format', 'csv');
                      if (actionFilter !== 'all') params.set('action', actionFilter);
                      if (dateFrom) params.set('from_date', format(dateFrom, 'yyyy-MM-dd'));
                      if (dateTo) params.set('to_date', format(dateTo, 'yyyy-MM-dd'));
                      window.open(`${import.meta.env.VITE_API_URL || ''}/api/admin/audit-logs/export?${params.toString()}`, '_blank');
                    }}
                  >
                    <FileSpreadsheet className="mr-2 h-4 w-4" />
                    Export as CSV
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    onClick={() => {
                      const params = new URLSearchParams();
                      params.set('format', 'pdf');
                      if (actionFilter !== 'all') params.set('action', actionFilter);
                      if (dateFrom) params.set('from_date', format(dateFrom, 'yyyy-MM-dd'));
                      if (dateTo) params.set('to_date', format(dateTo, 'yyyy-MM-dd'));
                      window.open(`${import.meta.env.VITE_API_URL || ''}/api/admin/audit-logs/export?${params.toString()}`, '_blank');
                    }}
                  >
                    <FileText className="mr-2 h-4 w-4" />
                    Export as PDF
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </div>

          {/* Activity Log */}
          <div className="rounded-xl border border-border bg-card overflow-hidden">
            {logsLoading && auditLogs.length === 0 ? (
              <div className="flex items-center justify-center py-12">
                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b border-border bg-muted/50">
                      <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Action</th>
                      <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Admin</th>
                      <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Details</th>
                      <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">IP Address</th>
                      <th className="px-6 py-4 text-left text-sm font-medium text-muted-foreground">Time</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {auditLogs.map((log) => {
                      const config = actionConfig[log.action] || { 
                        label: log.action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()), 
                        icon: Activity, 
                        color: "text-muted-foreground" 
                      };
                      const ActionIcon = config.icon;
                      
                      let oldVals: Record<string, any> | null = null;
                      let newVals: Record<string, any> | null = null;
                      try {
                        if (log.old_values) oldVals = JSON.parse(log.old_values);
                        if (log.new_values) newVals = JSON.parse(log.new_values);
                      } catch {}
                      
                      return (
                        <tr key={log.id} className="transition-colors hover:bg-muted/30">
                          <td className="px-6 py-4">
                            <div className="flex items-center gap-2">
                              <ActionIcon className={cn("h-4 w-4", config.color)} />
                              <span className="font-medium text-foreground">{config.label}</span>
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <p className="text-foreground">{log.user_name || "System"}</p>
                            <p className="text-sm text-muted-foreground">{log.user_email}</p>
                          </td>
                          <td className="px-6 py-4">
                            <div className="text-sm">
                              <span className="text-muted-foreground capitalize">{log.entity_type}</span>
                              {log.entity_id && (
                                <span className="text-muted-foreground"> #{log.entity_id}</span>
                              )}
                              {oldVals && newVals && (
                                <div className="mt-1 text-xs">
                                  {Object.keys(newVals).map(key => (
                                    <span key={key} className="inline-flex items-center gap-1">
                                      <span className="text-destructive line-through">{String(oldVals?.[key])}</span>
                                      <span className="text-muted-foreground">→</span>
                                      <span className="text-success">{String(newVals?.[key])}</span>
                                    </span>
                                  ))}
                                </div>
                              )}
                            </div>
                          </td>
                          <td className="px-6 py-4 text-muted-foreground font-mono text-sm">
                            {log.ip_address || "—"}
                          </td>
                          <td className="px-6 py-4 text-muted-foreground">
                            <div className="text-sm">
                              {new Date(log.created_at).toLocaleDateString()}
                            </div>
                            <div className="text-xs text-muted-foreground">
                              {new Date(log.created_at).toLocaleTimeString()}
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            {!logsLoading && auditLogs.length === 0 && (
              <div className="flex flex-col items-center justify-center py-12">
                <Activity className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-lg font-medium text-foreground">No activity logs found</p>
                <p className="text-sm text-muted-foreground">Admin actions will appear here</p>
              </div>
            )}

            {auditLogs.length > 0 && auditLogs.length % 100 === 0 && (
              <div className="p-4 border-t border-border">
                <Button 
                  variant="outline" 
                  onClick={loadMoreLogs} 
                  disabled={logsLoading}
                  className="w-full gap-2"
                >
                  {logsLoading ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <RefreshCw className="h-4 w-4" />
                  )}
                  Load More
                </Button>
              </div>
            )}
          </div>
        </TabsContent>

        <TabsContent value="contact-emails" className="mt-6">
          <div className="space-y-6">
            {/* Contact Emails Stats */}
            {contactEmailStats && (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {/* Total Submissions */}
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                      <Inbox className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-foreground">{contactEmailStats.total}</p>
                      <p className="text-sm text-muted-foreground">Total Submissions</p>
                    </div>
                  </div>
                  <div className="mt-3 flex items-center gap-4 text-xs">
                    <span className="text-muted-foreground">Today: <span className="font-medium text-foreground">{contactEmailStats.today}</span></span>
                    <span className="text-muted-foreground">This Week: <span className="font-medium text-foreground">{contactEmailStats.this_week}</span></span>
                  </div>
                </div>

                {/* Response Rate */}
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-success/10">
                      <Reply className="h-5 w-5 text-success" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-foreground">{contactEmailStats.reply_status.response_rate}%</p>
                      <p className="text-sm text-muted-foreground">Response Rate</p>
                    </div>
                  </div>
                  <div className="mt-3 flex items-center gap-4 text-xs">
                    <span className="text-muted-foreground">Replied: <span className="font-medium text-success">{contactEmailStats.reply_status.replied}</span></span>
                    <span className="text-muted-foreground">Pending: <span className="font-medium text-warning">{contactEmailStats.reply_status.pending}</span></span>
                  </div>
                </div>

                {/* Average Response Time */}
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10">
                      <Clock className="h-5 w-5 text-accent" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-foreground">{contactEmailStats.reply_status.avg_response_time || '—'}</p>
                      <p className="text-sm text-muted-foreground">Avg Response Time</p>
                    </div>
                  </div>
                  <div className="mt-3 flex items-center gap-4 text-xs">
                    <span className="text-muted-foreground">Read: <span className="font-medium text-foreground">{contactEmailStats.read_status.read}</span></span>
                    <span className="text-muted-foreground">Unread: <span className="font-medium text-primary">{contactEmailStats.read_status.unread}</span></span>
                  </div>
                </div>

                {/* Delivery Status */}
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-warning/10">
                      <Mail className="h-5 w-5 text-warning" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-foreground">{contactEmailStats.status.sent}</p>
                      <p className="text-sm text-muted-foreground">Delivered</p>
                    </div>
                  </div>
                  <div className="mt-3 flex items-center gap-4 text-xs">
                    <span className="text-muted-foreground">Failed: <span className="font-medium text-destructive">{contactEmailStats.status.failed}</span></span>
                    <span className="text-muted-foreground">Bounced: <span className="font-medium text-destructive">{contactEmailStats.status.bounced}</span></span>
                  </div>
                </div>
              </div>
            )}

            {/* Purpose Breakdown */}
            {contactEmailStats && (
              <div className="rounded-xl border border-border bg-card p-4">
                <h4 className="text-sm font-medium text-foreground mb-3">Submissions by Category</h4>
                <div className="flex gap-6">
                  <div className="flex items-center gap-2">
                    <div className="h-3 w-3 rounded-full bg-primary" />
                    <span className="text-sm text-muted-foreground">General: <span className="font-medium text-foreground">{contactEmailStats.purpose.general}</span></span>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="h-3 w-3 rounded-full bg-warning" />
                    <span className="text-sm text-muted-foreground">Support: <span className="font-medium text-foreground">{contactEmailStats.purpose.support}</span></span>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="h-3 w-3 rounded-full bg-success" />
                    <span className="text-sm text-muted-foreground">Sales: <span className="font-medium text-foreground">{contactEmailStats.purpose.sales}</span></span>
                  </div>
                </div>
              </div>
            )}

            {/* Contact Emails Header */}
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-lg font-semibold text-foreground">Contact Form Submissions</h3>
                <p className="text-sm text-muted-foreground">
                  View and manage messages from the contact form. {unreadContactCount > 0 && `${unreadContactCount} unread`}
                </p>
              </div>
              <Button variant="outline" size="sm" onClick={loadData} className="gap-2">
                <RefreshCw className="h-4 w-4" />
                Refresh
              </Button>
            </div>

            {/* Email List */}
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              {contactEmails.length === 0 ? (
                <div className="py-16 text-center">
                  <Inbox className="mx-auto h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-lg font-medium text-foreground">No contact submissions yet</p>
                  <p className="text-sm text-muted-foreground">Messages from the contact form will appear here</p>
                </div>
              ) : (
                <div className="divide-y divide-border">
                  {contactEmails.map((email) => (
                    <div
                      key={email.id}
                      className={cn(
                        "p-4 cursor-pointer transition-colors hover:bg-muted/30",
                        !email.read_by_admin && "bg-primary/5"
                      )}
                      onClick={async () => {
                        setSelectedEmail(email);
                        setEmailNotes(email.notes || "");
                        // Mark as read if unread
                        if (!email.read_by_admin) {
                          try {
                            await api.get(`/admin/contact-emails/${email.id}`);
                            setContactEmails(prev => prev.map(e => 
                              e.id === email.id ? {...e, read_by_admin: true, read_at: new Date().toISOString()} : e
                            ));
                            setUnreadContactCount(prev => Math.max(0, prev - 1));
                          } catch (error) {
                            console.error("Failed to mark as read:", error);
                          }
                        }
                      }}
                    >
                      <div className="flex items-start gap-4">
                        <div className={cn(
                          "flex h-10 w-10 items-center justify-center rounded-lg flex-shrink-0",
                          email.purpose === "support" ? "bg-warning/10" :
                          email.purpose === "sales" ? "bg-success/10" : "bg-primary/10"
                        )}>
                          {email.purpose === "support" ? (
                            <HelpCircle className={cn("h-5 w-5", email.purpose === "support" && "text-warning")} />
                          ) : email.purpose === "sales" ? (
                            <TrendingUp className="h-5 w-5 text-success" />
                          ) : (
                            <MessageIcon className="h-5 w-5 text-primary" />
                          )}
                        </div>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 mb-1">
                            <p className={cn(
                              "font-medium text-foreground truncate",
                              !email.read_by_admin && "font-semibold"
                            )}>
                              {email.sender_name}
                            </p>
                            {!email.read_by_admin && (
                              <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground">New</span>
                            )}
                            {email.replied && (
                              <span className="rounded-full bg-success/10 px-2 py-0.5 text-xs text-success flex items-center gap-1">
                                <Reply className="h-3 w-3" />
                                Replied
                              </span>
                            )}
                            <span className={cn(
                              "rounded-full px-2 py-0.5 text-xs capitalize",
                              email.purpose === "support" ? "bg-warning/10 text-warning" :
                              email.purpose === "sales" ? "bg-success/10 text-success" : "bg-primary/10 text-primary"
                            )}>
                              {email.purpose}
                            </span>
                          </div>
                          <p className="text-sm text-muted-foreground truncate">{email.sender_email}</p>
                          <p className="text-sm text-muted-foreground line-clamp-2 mt-1">{email.message}</p>
                        </div>
                        <div className="text-xs text-muted-foreground whitespace-nowrap">
                          {formatDistanceToNow(new Date(email.created_at), { addSuffix: true })}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Selected Email Detail Modal */}
            {selectedEmail && (
              <AlertDialog open={!!selectedEmail} onOpenChange={() => setSelectedEmail(null)}>
                <AlertDialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
                  <AlertDialogHeader>
                    <AlertDialogTitle className="flex items-center gap-2">
                      <Mail className="h-5 w-5" />
                      Message from {selectedEmail.sender_name}
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                      <div className="space-y-4 text-left">
                        <div className="grid grid-cols-2 gap-4 text-sm">
                          <div>
                            <span className="text-muted-foreground">From:</span>
                            <p className="font-medium text-foreground">{selectedEmail.sender_name}</p>
                            <a href={`mailto:${selectedEmail.sender_email}`} className="text-primary hover:underline">
                              {selectedEmail.sender_email}
                            </a>
                          </div>
                          <div>
                            <span className="text-muted-foreground">Category:</span>
                            <p className="font-medium text-foreground capitalize">{selectedEmail.purpose}</p>
                          </div>
                          <div>
                            <span className="text-muted-foreground">Sent to:</span>
                            <p className="font-medium text-foreground">{selectedEmail.recipient_email}</p>
                          </div>
                          <div>
                            <span className="text-muted-foreground">Received:</span>
                            <p className="font-medium text-foreground">
                              {format(new Date(selectedEmail.created_at), "PPp")}
                            </p>
                          </div>
                        </div>
                        
                        <div className="rounded-lg bg-muted p-4">
                          <p className="text-sm text-muted-foreground mb-2">Message:</p>
                          <p className="text-foreground whitespace-pre-wrap">{selectedEmail.message}</p>
                        </div>

                        {selectedEmail.origin_url && (
                          <div className="text-xs text-muted-foreground">
                            <span>Origin: </span>
                            <a href={selectedEmail.origin_url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                              {selectedEmail.origin_url}
                            </a>
                          </div>
                        )}

                        <div className="border-t pt-4">
                          <Label htmlFor="notes" className="text-sm">Admin Notes</Label>
                          <textarea
                            id="notes"
                            value={emailNotes}
                            onChange={(e) => setEmailNotes(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm resize-none"
                            rows={3}
                            placeholder="Add internal notes about this inquiry..."
                          />
                        </div>
                      </div>
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                    <Button
                      variant="outline"
                      onClick={() => window.open(`mailto:${selectedEmail.sender_email}?subject=Re: ${selectedEmail.subject}`, '_blank')}
                      className="gap-2"
                    >
                      <Mail className="h-4 w-4" />
                      Reply via Email
                    </Button>
                    {!selectedEmail.replied && (
                      <Button
                        onClick={async () => {
                          try {
                            await api.post(`/admin/contact-emails/${selectedEmail.id}/replied`, { notes: emailNotes });
                            setContactEmails(prev => prev.map(e =>
                              e.id === selectedEmail.id ? {...e, replied: true, replied_at: new Date().toISOString(), notes: emailNotes} : e
                            ));
                            toast({ title: "Marked as replied" });
                            setSelectedEmail(null);
                          } catch (error) {
                            handleApiError(error);
                          }
                        }}
                        className="gap-2"
                      >
                        <Reply className="h-4 w-4" />
                        Mark as Replied
                      </Button>
                    )}
                    <AlertDialogCancel>Close</AlertDialogCancel>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            )}
          </div>
        </TabsContent>

        <TabsContent value="settings" className="mt-6">
          <div className="space-y-8">
            {/* Notification Settings */}
            <div>
              <div className="mb-4">
                <h3 className="text-lg font-semibold text-foreground flex items-center gap-2">
                  <Bell className="h-5 w-5" />
                  Admin Notifications
                </h3>
                <p className="text-sm text-muted-foreground">
                  Configure which events trigger notifications for administrators
                </p>
              </div>

              <div className="rounded-xl border border-border bg-card overflow-hidden">
                <table className="w-full">
                  <thead>
                    <tr className="border-b border-border bg-muted/50">
                      <th className="px-6 py-3 text-left text-sm font-medium text-muted-foreground">Event</th>
                      <th className="px-6 py-3 text-center text-sm font-medium text-muted-foreground w-24">Enabled</th>
                      <th className="px-6 py-3 text-center text-sm font-medium text-muted-foreground w-24">Email</th>
                      <th className="px-6 py-3 text-center text-sm font-medium text-muted-foreground w-24">In-App</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {notificationSettings.map((setting) => (
                      <tr key={setting.event_type} className="transition-colors hover:bg-muted/30">
                        <td className="px-6 py-4">
                          <div>
                            <p className="font-medium text-foreground">{setting.event_label}</p>
                            {setting.event_description && (
                              <p className="text-sm text-muted-foreground">{setting.event_description}</p>
                            )}
                          </div>
                        </td>
                        <td className="px-6 py-4 text-center">
                          <div className="flex justify-center">
                            <Switch
                              checked={setting.is_enabled}
                              onCheckedChange={(checked) => handleToggleNotification(setting.event_type, "is_enabled", checked)}
                              disabled={notificationSaving === setting.event_type}
                            />
                          </div>
                        </td>
                        <td className="px-6 py-4 text-center">
                          <div className="flex justify-center">
                            <Switch
                              checked={setting.notify_email}
                              onCheckedChange={(checked) => handleToggleNotification(setting.event_type, "notify_email", checked)}
                              disabled={!setting.is_enabled || notificationSaving === setting.event_type}
                            />
                          </div>
                        </td>
                        <td className="px-6 py-4 text-center">
                          <div className="flex justify-center">
                            <Switch
                              checked={setting.notify_inapp}
                              onCheckedChange={(checked) => handleToggleNotification(setting.event_type, "notify_inapp", checked)}
                              disabled={!setting.is_enabled || notificationSaving === setting.event_type}
                            />
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                {notificationSettings.length === 0 && (
                  <div className="py-12 text-center">
                    <BellOff className="mx-auto h-12 w-12 text-muted-foreground/50" />
                    <p className="mt-4 text-lg font-medium text-foreground">No notification settings</p>
                    <p className="text-sm text-muted-foreground">Run migration 020 to add notification settings</p>
                  </div>
                )}
              </div>
            </div>

            {/* SMTP Settings Header */}
            <div>
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h3 className="text-lg font-semibold text-foreground flex items-center gap-2">
                    <Mail className="h-5 w-5" />
                    Email Configuration
                  </h3>
                  <p className="text-sm text-muted-foreground">
                    Configure SMTP settings for system emails and campaign emails separately
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Label htmlFor="testEmail" className="text-sm text-muted-foreground">Test Email:</Label>
                  <Input
                    id="testEmail"
                    type="email"
                    placeholder={user?.email || "Enter email"}
                    value={testEmail}
                    onChange={(e) => setTestEmail(e.target.value)}
                    className="w-64"
                  />
                </div>
              </div>

              {/* SMTP Settings Cards */}
              <div className="grid gap-6 md:grid-cols-2">
                {/* System Email Settings */}
                {(() => {
                  const systemSetting = smtpSettings.find(s => s.setting_type === "system");
                  return (
                    <div className="rounded-xl border border-border bg-card p-6">
                      <div className="flex items-center justify-between mb-4">
                        <div className="flex items-center gap-3">
                          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                            <Mail className="h-5 w-5 text-primary" />
                          </div>
                          <div>
                            <h4 className="font-medium text-foreground">System Emails</h4>
                            <p className="text-xs text-muted-foreground">Verification, Password Reset</p>
                          </div>
                        </div>
                        {systemSetting?.last_test_result && (
                          <span className={cn(
                            "flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-full",
                            systemSetting.last_test_result === "success" 
                              ? "bg-success/10 text-success" 
                              : "bg-destructive/10 text-destructive"
                          )}>
                            {systemSetting.last_test_result === "success" ? (
                              <CheckCircle className="h-3 w-3" />
                            ) : (
                              <XCircle className="h-3 w-3" />
                            )}
                            {systemSetting.last_test_result === "success" ? "Working" : "Failed"}
                          </span>
                        )}
                      </div>
                      
                      {editingSmtp === "system" ? (
                        <div className="space-y-4">
                          <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                              <Label htmlFor="system-host">SMTP Host</Label>
                              <Input
                                id="system-host"
                                value={smtpForm.host || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, host: e.target.value})}
                                placeholder="smtp.example.com"
                              />
                            </div>
                            <div>
                              <Label htmlFor="system-port">Port</Label>
                              <Input
                                id="system-port"
                                type="number"
                                value={smtpForm.port || 465}
                                onChange={(e) => setSmtpForm({...smtpForm, port: parseInt(e.target.value)})}
                              />
                            </div>
                          </div>
                          <div>
                            <Label htmlFor="system-encryption">Encryption</Label>
                            <Select 
                              value={smtpForm.encryption || "ssl"} 
                              onValueChange={(v) => setSmtpForm({...smtpForm, encryption: v as "none" | "ssl" | "tls"})}
                            >
                              <SelectTrigger>
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="ssl">SSL/TLS (Port 465)</SelectItem>
                                <SelectItem value="tls">STARTTLS (Port 587)</SelectItem>
                                <SelectItem value="none">None</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>
                          <div>
                            <Label htmlFor="system-username">Username</Label>
                            <Input
                              id="system-username"
                              value={smtpForm.username || ""}
                              onChange={(e) => setSmtpForm({...smtpForm, username: e.target.value})}
                              placeholder="noreply@sms.ieosuia.com"
                            />
                          </div>
                          <div>
                            <Label htmlFor="system-password">Password</Label>
                            <div className="relative">
                              <Input
                                id="system-password"
                                type={showPasswords.system ? "text" : "password"}
                                value={smtpForm.password || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, password: e.target.value})}
                                placeholder="Enter password"
                              />
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7"
                                onClick={() => setShowPasswords({...showPasswords, system: !showPasswords.system})}
                              >
                                {showPasswords.system ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                              </Button>
                            </div>
                          </div>
                          <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                              <Label htmlFor="system-from-email">From Email</Label>
                              <Input
                                id="system-from-email"
                                type="email"
                                value={smtpForm.from_email || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, from_email: e.target.value})}
                              />
                            </div>
                            <div>
                              <Label htmlFor="system-from-name">From Name</Label>
                              <Input
                                id="system-from-name"
                                value={smtpForm.from_name || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, from_name: e.target.value})}
                              />
                            </div>
                          </div>
                          <div className="flex gap-2 pt-2">
                            <Button onClick={handleSaveSmtp} disabled={smtpSaving} className="gap-2">
                              {smtpSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                              Save
                            </Button>
                            <Button variant="outline" onClick={() => setEditingSmtp(null)}>Cancel</Button>
                          </div>
                        </div>
                      ) : (
                        <div className="space-y-3">
                          <div className="grid grid-cols-2 gap-2 text-sm">
                            <div className="text-muted-foreground">Host:</div>
                            <div className="font-medium text-foreground">{systemSetting?.host || "Not configured"}</div>
                            <div className="text-muted-foreground">Port:</div>
                            <div className="font-medium text-foreground">{systemSetting?.port || 465}</div>
                            <div className="text-muted-foreground">Username:</div>
                            <div className="font-medium text-foreground truncate">{systemSetting?.username || "—"}</div>
                            <div className="text-muted-foreground">From:</div>
                            <div className="font-medium text-foreground truncate">{systemSetting?.from_email || "—"}</div>
                          </div>
                          {systemSetting?.last_tested_at && (
                            <p className="text-xs text-muted-foreground">
                              Last tested: {new Date(systemSetting.last_tested_at).toLocaleString()}
                            </p>
                          )}
                          {systemSetting?.last_test_error && (
                            <div className="flex items-start gap-2 p-2 rounded bg-destructive/10 text-xs text-destructive">
                              <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5" />
                              <span className="line-clamp-2">{systemSetting.last_test_error}</span>
                            </div>
                          )}
                          <div className="flex gap-2 pt-2">
                            <Button variant="outline" size="sm" onClick={() => handleEditSmtp("system")} className="gap-1">
                              <Settings className="h-3 w-3" />
                              Configure
                            </Button>
                            <Button 
                              variant="outline" 
                              size="sm" 
                              onClick={() => handleTestSmtp("system")}
                              disabled={smtpTesting === "system"}
                              className="gap-1"
                            >
                              {smtpTesting === "system" ? (
                                <Loader2 className="h-3 w-3 animate-spin" />
                              ) : (
                                <Send className="h-3 w-3" />
                              )}
                              Test
                            </Button>
                          </div>
                        </div>
                      )}
                    </div>
                  );
                })()}

                {/* Campaign Email Settings */}
                {(() => {
                  const campaignSetting = smtpSettings.find(s => s.setting_type === "campaign");
                  return (
                    <div className="rounded-xl border border-border bg-card p-6">
                      <div className="flex items-center justify-between mb-4">
                        <div className="flex items-center gap-3">
                          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10">
                            <MessageSquare className="h-5 w-5 text-accent" />
                          </div>
                          <div>
                            <h4 className="font-medium text-foreground">Campaign Emails</h4>
                            <p className="text-xs text-muted-foreground">Marketing, Newsletters</p>
                          </div>
                        </div>
                        {campaignSetting?.last_test_result && (
                          <span className={cn(
                            "flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-full",
                            campaignSetting.last_test_result === "success" 
                              ? "bg-success/10 text-success" 
                              : "bg-destructive/10 text-destructive"
                          )}>
                            {campaignSetting.last_test_result === "success" ? (
                              <CheckCircle className="h-3 w-3" />
                            ) : (
                              <XCircle className="h-3 w-3" />
                            )}
                            {campaignSetting.last_test_result === "success" ? "Working" : "Failed"}
                          </span>
                        )}
                      </div>
                      
                      {editingSmtp === "campaign" ? (
                        <div className="space-y-4">
                          <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                              <Label htmlFor="campaign-host">SMTP Host</Label>
                              <Input
                                id="campaign-host"
                                value={smtpForm.host || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, host: e.target.value})}
                                placeholder="smtp.example.com"
                              />
                            </div>
                            <div>
                              <Label htmlFor="campaign-port">Port</Label>
                              <Input
                                id="campaign-port"
                                type="number"
                                value={smtpForm.port || 465}
                                onChange={(e) => setSmtpForm({...smtpForm, port: parseInt(e.target.value)})}
                              />
                            </div>
                          </div>
                          <div>
                            <Label htmlFor="campaign-encryption">Encryption</Label>
                            <Select 
                              value={smtpForm.encryption || "ssl"} 
                              onValueChange={(v) => setSmtpForm({...smtpForm, encryption: v as "none" | "ssl" | "tls"})}
                            >
                              <SelectTrigger>
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="ssl">SSL/TLS (Port 465)</SelectItem>
                                <SelectItem value="tls">STARTTLS (Port 587)</SelectItem>
                                <SelectItem value="none">None</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>
                          <div>
                            <Label htmlFor="campaign-username">Username</Label>
                            <Input
                              id="campaign-username"
                              value={smtpForm.username || ""}
                              onChange={(e) => setSmtpForm({...smtpForm, username: e.target.value})}
                              placeholder="email@sms.ieosuia.com"
                            />
                          </div>
                          <div>
                            <Label htmlFor="campaign-password">Password</Label>
                            <div className="relative">
                              <Input
                                id="campaign-password"
                                type={showPasswords.campaign ? "text" : "password"}
                                value={smtpForm.password || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, password: e.target.value})}
                                placeholder="Enter password"
                              />
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7"
                                onClick={() => setShowPasswords({...showPasswords, campaign: !showPasswords.campaign})}
                              >
                                {showPasswords.campaign ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                              </Button>
                            </div>
                          </div>
                          <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                              <Label htmlFor="campaign-from-email">From Email</Label>
                              <Input
                                id="campaign-from-email"
                                type="email"
                                value={smtpForm.from_email || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, from_email: e.target.value})}
                              />
                            </div>
                            <div>
                              <Label htmlFor="campaign-from-name">From Name</Label>
                              <Input
                                id="campaign-from-name"
                                value={smtpForm.from_name || ""}
                                onChange={(e) => setSmtpForm({...smtpForm, from_name: e.target.value})}
                              />
                            </div>
                          </div>
                          <div className="flex gap-2 pt-2">
                            <Button onClick={handleSaveSmtp} disabled={smtpSaving} className="gap-2">
                              {smtpSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                              Save
                            </Button>
                            <Button variant="outline" onClick={() => setEditingSmtp(null)}>Cancel</Button>
                          </div>
                        </div>
                      ) : (
                        <div className="space-y-3">
                          <div className="grid grid-cols-2 gap-2 text-sm">
                            <div className="text-muted-foreground">Host:</div>
                            <div className="font-medium text-foreground">{campaignSetting?.host || "Not configured"}</div>
                            <div className="text-muted-foreground">Port:</div>
                            <div className="font-medium text-foreground">{campaignSetting?.port || 465}</div>
                            <div className="text-muted-foreground">Username:</div>
                            <div className="font-medium text-foreground truncate">{campaignSetting?.username || "—"}</div>
                            <div className="text-muted-foreground">From:</div>
                            <div className="font-medium text-foreground truncate">{campaignSetting?.from_email || "—"}</div>
                          </div>
                          {campaignSetting?.last_tested_at && (
                            <p className="text-xs text-muted-foreground">
                              Last tested: {new Date(campaignSetting.last_tested_at).toLocaleString()}
                            </p>
                          )}
                          {campaignSetting?.last_test_error && (
                            <div className="flex items-start gap-2 p-2 rounded bg-destructive/10 text-xs text-destructive">
                              <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5" />
                              <span className="line-clamp-2">{campaignSetting.last_test_error}</span>
                            </div>
                          )}
                          <div className="flex gap-2 pt-2">
                            <Button variant="outline" size="sm" onClick={() => handleEditSmtp("campaign")} className="gap-1">
                              <Settings className="h-3 w-3" />
                              Configure
                            </Button>
                            <Button 
                              variant="outline" 
                              size="sm" 
                              onClick={() => handleTestSmtp("campaign")}
                              disabled={smtpTesting === "campaign"}
                              className="gap-1"
                            >
                              {smtpTesting === "campaign" ? (
                                <Loader2 className="h-3 w-3 animate-spin" />
                              ) : (
                                <Send className="h-3 w-3" />
                              )}
                              Test
                            </Button>
                          </div>
                        </div>
                      )}
                    </div>
                  );
                })()}
              </div>

              {/* Info Box */}
              <div className="mt-6 rounded-lg bg-muted/50 p-4 border border-border">
                <div className="flex items-start gap-3">
                  <Server className="h-5 w-5 text-muted-foreground mt-0.5" />
                  <div>
                    <h4 className="font-medium text-foreground">About Email Configuration</h4>
                    <p className="text-sm text-muted-foreground mt-1">
                      <strong>System Emails</strong> (noreply@...): Used for email verification, password resets, and account notifications. These are sent from the portal system.
                    </p>
                    <p className="text-sm text-muted-foreground mt-1">
                      <strong>Campaign Emails</strong> (email@...): Used for email campaigns and marketing messages. Configure this for bulk email sending.
                    </p>
                    <p className="text-sm text-muted-foreground mt-2">
                      Settings are stored securely in the database and override the default .env configuration.
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </TabsContent>
      </Tabs>

      {/* Confirm Dialog */}
      <AlertDialog open={!!confirmAction} onOpenChange={() => setConfirmAction(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {confirmAction?.type === "deactivate" ? "Deactivate User?" : "Activate User?"}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {confirmAction?.type === "deactivate"
                ? `This will prevent ${confirmAction.data?.name} from accessing their account.`
                : `This will restore access for ${confirmAction?.data?.name}.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => confirmAction && handleToggleUserStatus(confirmAction.id, confirmAction.data?.activate)}
              className={confirmAction?.type === "deactivate" ? "bg-destructive text-destructive-foreground hover:bg-destructive/90" : ""}
            >
              {actionLoading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              {confirmAction?.type === "deactivate" ? "Deactivate" : "Activate"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DashboardLayout>
  );
}
