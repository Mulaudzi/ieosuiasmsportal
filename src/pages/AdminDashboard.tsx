import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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
  campaign_created: { label: "Campaign Created", icon: MessageSquare, color: "text-primary" },
  campaign_sent: { label: "Campaign Sent", icon: CheckCircle, color: "text-success" },
  campaign_deleted: { label: "Campaign Deleted", icon: XCircle, color: "text-destructive" },
  user_registered: { label: "User Registered", icon: UserCheck, color: "text-primary" },
};

export default function AdminDashboard() {
  const navigate = useNavigate();
  const { user, isLoading: authLoading } = useAuth();
  const [users, setUsers] = useState<User[]>([]);
  const [senderIds, setSenderIds] = useState<SenderId[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [auditLogs, setAuditLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [logsLoading, setLogsLoading] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [actionFilter, setActionFilter] = useState("all");
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [confirmAction, setConfirmAction] = useState<{ type: string; id: string; data?: any } | null>(null);
  const [accessDenied, setAccessDenied] = useState(false);

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
      const [usersRes, senderIdsRes, statsRes, logsRes] = await Promise.all([
        api.get<{ users: User[] }>("/admin/users"),
        api.get<{ sender_ids: SenderId[] }>("/admin/sender-ids"),
        api.get<Stats>("/admin/stats"),
        api.get<{ logs: AuditLog[] }>("/admin/audit-logs?per_page=100"),
      ]);

      if (usersRes.success) setUsers(usersRes.data?.users || []);
      if (senderIdsRes.success) setSenderIds(senderIdsRes.data?.sender_ids || []);
      if (statsRes.success) setStats(statsRes.data || null);
      if (logsRes.success) setAuditLogs(logsRes.data?.logs || []);
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  };

  const loadMoreLogs = async () => {
    setLogsLoading(true);
    try {
      const page = Math.floor(auditLogs.length / 100) + 1;
      const res = await api.get<{ logs: AuditLog[] }>(`/admin/audit-logs?page=${page}&per_page=100`);
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

  const filteredLogs = auditLogs.filter(log => {
    const matchesAction = actionFilter === "all" || log.action === actionFilter;
    return matchesAction;
  });

  const pendingCount = senderIds.filter(s => s.status === "pending").length;

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
          <TabsTrigger value="activity" className="gap-2">
            <Activity className="h-4 w-4" />
            Activity Log
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

        <TabsContent value="activity" className="mt-6">
          {/* Filters and Export */}
          <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
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
                <SelectItem value="campaign_deleted">Campaign Deleted</SelectItem>
                <SelectItem value="user_registered">User Registered</SelectItem>
              </SelectContent>
            </Select>
            
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
                  {filteredLogs.map((log) => {
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

            {filteredLogs.length === 0 && (
              <div className="flex flex-col items-center justify-center py-12">
                <Activity className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-lg font-medium text-foreground">No activity logs found</p>
                <p className="text-sm text-muted-foreground">Admin actions will appear here</p>
              </div>
            )}

            {filteredLogs.length > 0 && filteredLogs.length % 100 === 0 && (
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
