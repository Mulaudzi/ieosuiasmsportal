import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { format } from "date-fns";
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
      const [usersRes, senderIdsRes, statsRes, logsRes, cronRes, scheduledRes, smtpRes, notifRes] = await Promise.all([
        api.get<{ users: User[] }>("/admin/users"),
        api.get<{ sender_ids: SenderId[] }>("/admin/sender-ids"),
        api.get<Stats>("/admin/stats"),
        api.get<{ logs: AuditLog[] }>("/admin/audit-logs?per_page=100"),
        api.get<{ jobs: CronJob[] }>("/admin/cron/status"),
        api.get<{ campaigns: ScheduledCampaign[] }>("/admin/cron/pending-campaigns"),
        api.get<{ settings: SmtpSetting[] }>("/admin/smtp-settings"),
        api.get<{ settings: NotificationSetting[] }>("/admin/notification-settings"),
      ]);

      if (usersRes.success) setUsers(usersRes.data?.users || []);
      if (senderIdsRes.success) setSenderIds(senderIdsRes.data?.sender_ids || []);
      if (statsRes.success) setStats(statsRes.data || null);
      if (logsRes.success) setAuditLogs(logsRes.data?.logs || []);
      if (cronRes.success) setCronJobs(cronRes.data?.jobs || []);
      if (scheduledRes.success) setScheduledCampaigns(scheduledRes.data?.campaigns || []);
      if (smtpRes.success) setSmtpSettings(smtpRes.data?.settings || []);
      if (notifRes.success) setNotificationSettings(notifRes.data?.settings || []);
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
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
