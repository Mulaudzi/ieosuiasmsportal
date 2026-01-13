import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { useAdminSession } from "@/hooks/useAdminSession";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
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
  Bug,
  Play,
  RefreshCw,
  Loader2,
  CheckCircle,
  XCircle,
  AlertTriangle,
  Info,
  ChevronDown,
  ChevronRight,
  Copy,
  Download,
  Trash2,
  Database,
  Server,
  Shield,
  Mail,
  MessageSquare,
  Users,
  Wallet,
  Activity,
  Zap,
  FileText,
  Clock,
} from "lucide-react";
import { useAuth } from "@/hooks/useAuth";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { api, handleApiError } from "@/lib/api";

// Interfaces
interface TestResult {
  name: string;
  type: string;
  system: string;
  component: string;
  status: "passed" | "failed" | "warning";
  message: string;
  details: string;
}

interface MissingItem {
  type: string;
  name: string;
  purpose: string;
  severity: string;
  suggestion: string;
}

interface TestResults {
  meta: {
    system: string;
    user_mode: string;
    test_type: string;
    started_at: string;
    completed_at: string;
    duration_ms: number;
    php_version: string;
  };
  smoke: Record<string, TestResult[]>;
  functional: Record<string, TestResult[]>;
  integration: Record<string, TestResult[]>;
  missing: MissingItem[];
  summary: {
    total: number;
    passed: number;
    warnings: number;
    failed: number;
    missing: number;
  };
  error?: {
    message: string;
    file: string;
    line: number;
  };
}

interface HealthOverview {
  database: string;
  tables: Record<string, string>;
  record_counts: Record<string, number>;
  error?: string;
}

const systemIcons: Record<string, React.ElementType> = {
  sms: MessageSquare,
  email: Mail,
  contacts: Users,
  credits: Wallet,
  auth: Shield,
  shared: Server,
};

const statusIcons: Record<string, { icon: React.ElementType; class: string }> = {
  passed: { icon: CheckCircle, class: "text-success" },
  failed: { icon: XCircle, class: "text-destructive" },
  warning: { icon: AlertTriangle, class: "text-warning" },
};

export default function QaConsole() {
  const navigate = useNavigate();
  const { user, isLoading: authLoading } = useAuth();
  
  // Admin session timeout handling
  useAdminSession();
  
  // State
  const [selectedSystem, setSelectedSystem] = useState("all");
  const [selectedUserMode, setSelectedUserMode] = useState("admin");
  const [selectedTestType, setSelectedTestType] = useState("all");
  const [isRunning, setIsRunning] = useState(false);
  const [results, setResults] = useState<TestResults | null>(null);
  const [healthOverview, setHealthOverview] = useState<HealthOverview | null>(null);
  const [healthLoading, setHealthLoading] = useState(false);
  const [expandedSections, setExpandedSections] = useState<Set<string>>(new Set());
  const [showCleanupDialog, setShowCleanupDialog] = useState(false);
  const [cleaningUp, setCleaningUp] = useState(false);
  const [seeding, setSeeding] = useState(false);
  const [accessDenied, setAccessDenied] = useState(false);

  // Check admin access
  useEffect(() => {
    if (authLoading) return;
    
    const adminSession = sessionStorage.getItem("admin_session");
    const isAdminUser = user?.email === "godtheson@ieosuia.com";
    
    if (!adminSession || !isAdminUser) {
      setAccessDenied(true);
    }
  }, [authLoading, user]);

  // Load health overview on mount
  useEffect(() => {
    if (!accessDenied && !authLoading) {
      loadHealthOverview();
    }
  }, [accessDenied, authLoading]);

  const loadHealthOverview = async () => {
    setHealthLoading(true);
    try {
      const res = await api.get<{ health: HealthOverview }>("/admin/qa/health");
      if (res.success) {
        setHealthOverview(res.data?.health || null);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setHealthLoading(false);
    }
  };

  const runTests = async () => {
    setIsRunning(true);
    setResults(null);
    
    try {
      const res = await api.post<{ results: TestResults }>("/admin/qa/run", {
        system: selectedSystem,
        user_mode: selectedUserMode,
        test_type: selectedTestType,
      });
      
      if (res.success && res.data?.results) {
        setResults(res.data.results);
        
        // Auto-expand sections with failures
        const sectionsWithFailures = new Set<string>();
        for (const testType of ["smoke", "functional", "integration"] as const) {
          const tests = res.data.results[testType];
          if (tests) {
            for (const [system, systemTests] of Object.entries(tests)) {
              if ((systemTests as TestResult[]).some((t) => t.status === "failed")) {
                sectionsWithFailures.add(`${testType}-${system}`);
              }
            }
          }
        }
        setExpandedSections(sectionsWithFailures);
        
        toast({
          title: "Tests Complete",
          description: `${res.data.results.summary.passed} passed, ${res.data.results.summary.failed} failed`,
        });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setIsRunning(false);
    }
  };

  const seedTestData = async () => {
    setSeeding(true);
    try {
      const res = await api.post("/admin/qa/seed", { system: selectedSystem });
      if (res.success) {
        toast({ title: "Test data seeded", description: "QA test data has been created" });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSeeding(false);
    }
  };

  const cleanupTestData = async () => {
    setCleaningUp(true);
    try {
      const res = await api.post("/admin/qa/cleanup", { prefix: "QA_TEST_" });
      if (res.success) {
        toast({ title: "Cleanup complete", description: "Test data has been removed" });
        setShowCleanupDialog(false);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setCleaningUp(false);
    }
  };

  const copyErrors = () => {
    if (!results) return;
    
    const errors: string[] = [];
    
    for (const testType of ["smoke", "functional", "integration"] as const) {
      const tests = results[testType];
      if (tests) {
        for (const [system, systemTests] of Object.entries(tests)) {
          for (const test of systemTests as TestResult[]) {
            if (test.status === "failed") {
              errors.push(
                `SYSTEM: ${system}\nTYPE: ${testType}\nTEST: ${test.name}\nERROR: ${test.message}\nDETAILS: ${test.details || "N/A"}\n`
              );
            }
          }
        }
      }
    }
    
    for (const missing of results.missing) {
      if (missing.severity === "error") {
        errors.push(
          `MISSING: ${missing.type}\nNAME: ${missing.name}\nPURPOSE: ${missing.purpose}\nSUGGESTION: ${missing.suggestion}\n`
        );
      }
    }
    
    if (errors.length === 0) {
      toast({ title: "No errors to copy" });
      return;
    }
    
    navigator.clipboard.writeText(errors.join("\n---\n\n"));
    toast({ title: "Errors copied to clipboard" });
  };

  const exportReport = () => {
    if (!results) return;
    
    const report = JSON.stringify(results, null, 2);
    const blob = new Blob([report], { type: "application/json" });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `qa_report_${new Date().toISOString().slice(0, 10)}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    toast({ title: "Report exported" });
  };

  const toggleSection = (key: string) => {
    const newExpanded = new Set(expandedSections);
    if (newExpanded.has(key)) {
      newExpanded.delete(key);
    } else {
      newExpanded.add(key);
    }
    setExpandedSections(newExpanded);
  };

  if (accessDenied) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <div className="text-center max-w-md mx-auto p-8">
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-destructive/10 mx-auto mb-6">
            <Shield className="h-10 w-10 text-destructive" />
          </div>
          <h1 className="text-2xl font-bold text-foreground mb-2">Access Denied</h1>
          <p className="text-muted-foreground mb-6">
            QA Console is admin-only. Please login with admin credentials.
          </p>
          <Button onClick={() => navigate("/login")} className="gap-2">
            <Shield className="h-4 w-4" />
            Go to Login
          </Button>
        </div>
      </div>
    );
  }

  return (
    <DashboardLayout
      title="System QA & Debug Console"
      subtitle="Universal testing and diagnostics for all platform systems"
      actions={
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={loadHealthOverview} disabled={healthLoading} className="gap-2">
            <RefreshCw className={cn("h-4 w-4", healthLoading && "animate-spin")} />
            Refresh Health
          </Button>
        </div>
      }
    >
      {/* QA Mode Banner */}
      <div className="mb-6 rounded-lg bg-warning/10 border border-warning/30 px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Bug className="h-5 w-5 text-warning" />
          <span className="font-medium text-warning">QA Mode</span>
          <span className="text-sm text-muted-foreground">
            System: <span className="text-foreground capitalize">{selectedSystem}</span> | 
            User Mode: <span className="text-foreground capitalize">{selectedUserMode}</span> |
            Test Type: <span className="text-foreground capitalize">{selectedTestType}</span>
          </span>
        </div>
        <div className="flex items-center gap-2">
          {results && (
            <>
              <Button variant="ghost" size="sm" onClick={copyErrors} className="gap-1.5 text-xs">
                <Copy className="h-3.5 w-3.5" />
                Copy Errors
              </Button>
              <Button variant="ghost" size="sm" onClick={exportReport} className="gap-1.5 text-xs">
                <Download className="h-3.5 w-3.5" />
                Export
              </Button>
            </>
          )}
        </div>
      </div>

      {/* Controls */}
      <div className="mb-6 rounded-xl border border-border bg-card p-4">
        <div className="flex flex-wrap items-end gap-4">
          <div className="w-40">
            <Label className="text-xs text-muted-foreground">System</Label>
            <Select value={selectedSystem} onValueChange={setSelectedSystem}>
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Systems</SelectItem>
                <SelectItem value="sms">SMS System</SelectItem>
                <SelectItem value="email">Email System</SelectItem>
                <SelectItem value="contacts">Contacts</SelectItem>
                <SelectItem value="credits">Credits & Billing</SelectItem>
                <SelectItem value="auth">Auth & Users</SelectItem>
                <SelectItem value="shared">Shared Services</SelectItem>
              </SelectContent>
            </Select>
          </div>
          
          <div className="w-36">
            <Label className="text-xs text-muted-foreground">User Mode</Label>
            <Select value={selectedUserMode} onValueChange={setSelectedUserMode}>
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="admin">Admin</SelectItem>
                <SelectItem value="user">Normal User</SelectItem>
                <SelectItem value="readonly">Read-only</SelectItem>
              </SelectContent>
            </Select>
          </div>
          
          <div className="w-40">
            <Label className="text-xs text-muted-foreground">Test Type</Label>
            <Select value={selectedTestType} onValueChange={setSelectedTestType}>
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Tests</SelectItem>
                <SelectItem value="smoke">Smoke Tests</SelectItem>
                <SelectItem value="functional">Functional Tests</SelectItem>
                <SelectItem value="integration">Integration Tests</SelectItem>
              </SelectContent>
            </Select>
          </div>
          
          <div className="flex gap-2 ml-auto">
            <Button
              variant="outline"
              onClick={seedTestData}
              disabled={seeding}
              className="gap-2"
            >
              {seeding ? <Loader2 className="h-4 w-4 animate-spin" /> : <Database className="h-4 w-4" />}
              Seed Data
            </Button>
            <Button
              variant="outline"
              onClick={() => setShowCleanupDialog(true)}
              className="gap-2 text-destructive hover:text-destructive"
            >
              <Trash2 className="h-4 w-4" />
              Cleanup
            </Button>
            <Button
              onClick={runTests}
              disabled={isRunning}
              className="gap-2"
            >
              {isRunning ? <Loader2 className="h-4 w-4 animate-spin" /> : <Play className="h-4 w-4" />}
              Run Tests
            </Button>
          </div>
        </div>
      </div>

      <Tabs defaultValue="results" className="space-y-6">
        <TabsList>
          <TabsTrigger value="results" className="gap-2">
            <Activity className="h-4 w-4" />
            Test Results
          </TabsTrigger>
          <TabsTrigger value="health" className="gap-2">
            <Server className="h-4 w-4" />
            System Health
          </TabsTrigger>
          <TabsTrigger value="missing" className="gap-2">
            <AlertTriangle className="h-4 w-4" />
            Missing
            {results && results.missing.filter(m => m.severity === "error").length > 0 && (
              <span className="ml-1 rounded-full bg-destructive px-1.5 py-0.5 text-xs text-destructive-foreground">
                {results.missing.filter(m => m.severity === "error").length}
              </span>
            )}
          </TabsTrigger>
        </TabsList>

        {/* Results Tab */}
        <TabsContent value="results">
          {!results ? (
            <div className="rounded-xl border border-border bg-card p-12 text-center">
              <Bug className="mx-auto h-12 w-12 text-muted-foreground/50" />
              <h3 className="mt-4 text-lg font-medium text-foreground">No Test Results</h3>
              <p className="mt-1 text-sm text-muted-foreground">
                Click "Run Tests" to execute the QA test suite
              </p>
            </div>
          ) : (
            <div className="space-y-6">
              {/* Summary Cards */}
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                      <Activity className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-foreground">{results.summary.total}</p>
                      <p className="text-sm text-muted-foreground">Total Tests</p>
                    </div>
                  </div>
                </div>
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-success/10">
                      <CheckCircle className="h-5 w-5 text-success" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-success">{results.summary.passed}</p>
                      <p className="text-sm text-muted-foreground">Passed</p>
                    </div>
                  </div>
                </div>
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-warning/10">
                      <AlertTriangle className="h-5 w-5 text-warning" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-warning">{results.summary.warnings}</p>
                      <p className="text-sm text-muted-foreground">Warnings</p>
                    </div>
                  </div>
                </div>
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-destructive/10">
                      <XCircle className="h-5 w-5 text-destructive" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-destructive">{results.summary.failed}</p>
                      <p className="text-sm text-muted-foreground">Failed</p>
                    </div>
                  </div>
                </div>
                <div className="rounded-xl border border-border bg-card p-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                      <Info className="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-2xl font-bold text-muted-foreground">{results.summary.missing}</p>
                      <p className="text-sm text-muted-foreground">Missing</p>
                    </div>
                  </div>
                </div>
              </div>

              {/* Meta Info */}
              <div className="rounded-lg bg-muted/50 px-4 py-2 text-xs text-muted-foreground flex items-center gap-4">
                <span className="flex items-center gap-1">
                  <Clock className="h-3.5 w-3.5" />
                  Duration: {results.meta.duration_ms}ms
                </span>
                <span>PHP: {results.meta.php_version}</span>
                <span>Started: {results.meta.started_at}</span>
              </div>

              {/* Test Results by Type */}
              {(["smoke", "functional", "integration"] as const).map((testType) => {
                const tests = results[testType];
                if (!tests || Object.keys(tests).length === 0) return null;

                return (
                  <div key={testType} className="rounded-xl border border-border bg-card overflow-hidden">
                    <div className="border-b border-border bg-muted/50 px-4 py-3">
                      <h3 className="font-medium text-foreground capitalize flex items-center gap-2">
                        {testType === "smoke" && <Zap className="h-4 w-4" />}
                        {testType === "functional" && <Activity className="h-4 w-4" />}
                        {testType === "integration" && <Server className="h-4 w-4" />}
                        {testType} Tests
                      </h3>
                    </div>
                    <div className="divide-y divide-border">
                      {Object.entries(tests).map(([system, systemTests]) => {
                        const sectionKey = `${testType}-${system}`;
                        const isExpanded = expandedSections.has(sectionKey);
                        const Icon = systemIcons[system] || Activity;
                        const failedCount = (systemTests as TestResult[]).filter(t => t.status === "failed").length;
                        const passedCount = (systemTests as TestResult[]).filter(t => t.status === "passed").length;

                        return (
                          <Collapsible key={sectionKey} open={isExpanded} onOpenChange={() => toggleSection(sectionKey)}>
                            <CollapsibleTrigger asChild>
                              <div className="flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-muted/30 transition-colors">
                                <div className="flex items-center gap-3">
                                  {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                  <Icon className="h-4 w-4 text-muted-foreground" />
                                  <span className="font-medium text-foreground capitalize">{system}</span>
                                  <span className="text-xs text-muted-foreground">
                                    ({(systemTests as TestResult[]).length} tests)
                                  </span>
                                </div>
                                <div className="flex items-center gap-2">
                                  {passedCount > 0 && (
                                    <span className="flex items-center gap-1 text-xs text-success">
                                      <CheckCircle className="h-3.5 w-3.5" />
                                      {passedCount}
                                    </span>
                                  )}
                                  {failedCount > 0 && (
                                    <span className="flex items-center gap-1 text-xs text-destructive">
                                      <XCircle className="h-3.5 w-3.5" />
                                      {failedCount}
                                    </span>
                                  )}
                                </div>
                              </div>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                              <div className="px-4 pb-3 space-y-2">
                                {(systemTests as TestResult[]).map((test, idx) => {
                                  const StatusIcon = statusIcons[test.status]?.icon || Info;
                                  const statusClass = statusIcons[test.status]?.class || "";
                                  
                                  return (
                                    <div
                                      key={idx}
                                      className={cn(
                                        "flex items-start gap-3 rounded-lg border p-3",
                                        test.status === "failed" && "border-destructive/30 bg-destructive/5",
                                        test.status === "warning" && "border-warning/30 bg-warning/5",
                                        test.status === "passed" && "border-border bg-muted/30"
                                      )}
                                    >
                                      <StatusIcon className={cn("h-4 w-4 mt-0.5 flex-shrink-0", statusClass)} />
                                      <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-foreground">{test.name}</p>
                                        {test.message && test.status !== "passed" && (
                                          <p className="text-xs text-muted-foreground mt-0.5">{test.message}</p>
                                        )}
                                        {test.details && (
                                          <p className="text-xs text-muted-foreground mt-0.5 font-mono">{test.details}</p>
                                        )}
                                        <div className="flex items-center gap-2 mt-1">
                                          {test.component && (
                                            <span className="text-xs bg-muted px-1.5 py-0.5 rounded">{test.component}</span>
                                          )}
                                        </div>
                                      </div>
                                    </div>
                                  );
                                })}
                              </div>
                            </CollapsibleContent>
                          </Collapsible>
                        );
                      })}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </TabsContent>

        {/* Health Tab */}
        <TabsContent value="health">
          {!healthOverview ? (
            <div className="rounded-xl border border-border bg-card p-12 text-center">
              <Server className="mx-auto h-12 w-12 text-muted-foreground/50" />
              <h3 className="mt-4 text-lg font-medium text-foreground">Loading Health Data...</h3>
            </div>
          ) : (
            <div className="space-y-6">
              {/* Database Status */}
              <div className="rounded-xl border border-border bg-card p-4">
                <div className="flex items-center gap-3 mb-4">
                  <div className={cn(
                    "flex h-10 w-10 items-center justify-center rounded-lg",
                    healthOverview.database === "healthy" ? "bg-success/10" : "bg-destructive/10"
                  )}>
                    <Database className={cn(
                      "h-5 w-5",
                      healthOverview.database === "healthy" ? "text-success" : "text-destructive"
                    )} />
                  </div>
                  <div>
                    <p className="font-medium text-foreground">Database Connection</p>
                    <p className={cn(
                      "text-sm capitalize",
                      healthOverview.database === "healthy" ? "text-success" : "text-destructive"
                    )}>
                      {healthOverview.database}
                    </p>
                  </div>
                </div>
                
                {healthOverview.error && (
                  <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                    {healthOverview.error}
                  </div>
                )}
              </div>

              {/* Table Status */}
              <div className="rounded-xl border border-border bg-card overflow-hidden">
                <div className="border-b border-border bg-muted/50 px-4 py-3">
                  <h3 className="font-medium text-foreground">Database Tables</h3>
                </div>
                <div className="p-4">
                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {Object.entries(healthOverview.tables).map(([table, status]) => (
                      <div
                        key={table}
                        className={cn(
                          "flex items-center justify-between rounded-lg border p-3",
                          status === "exists" ? "border-border bg-muted/30" : "border-destructive/30 bg-destructive/5"
                        )}
                      >
                        <span className="text-sm font-medium text-foreground">{table}</span>
                        <div className="flex items-center gap-2">
                          {healthOverview.record_counts[table] !== undefined && (
                            <span className="text-xs text-muted-foreground">
                              {healthOverview.record_counts[table]} rows
                            </span>
                          )}
                          {status === "exists" ? (
                            <CheckCircle className="h-4 w-4 text-success" />
                          ) : (
                            <XCircle className="h-4 w-4 text-destructive" />
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}
        </TabsContent>

        {/* Missing Tab */}
        <TabsContent value="missing">
          {!results || results.missing.length === 0 ? (
            <div className="rounded-xl border border-border bg-card p-12 text-center">
              <CheckCircle className="mx-auto h-12 w-12 text-success/50" />
              <h3 className="mt-4 text-lg font-medium text-foreground">All Clear</h3>
              <p className="mt-1 text-sm text-muted-foreground">
                No missing components detected. Run tests to scan for missing items.
              </p>
            </div>
          ) : (
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              <div className="border-b border-border bg-muted/50 px-4 py-3">
                <h3 className="font-medium text-foreground">Missing & Not Implemented</h3>
              </div>
              <div className="divide-y divide-border">
                {results.missing.map((item, idx) => (
                  <div
                    key={idx}
                    className={cn(
                      "flex items-start gap-3 p-4",
                      item.severity === "error" && "bg-destructive/5",
                      item.severity === "info" && "bg-muted/30"
                    )}
                  >
                    {item.severity === "error" ? (
                      <XCircle className="h-5 w-5 text-destructive flex-shrink-0" />
                    ) : (
                      <Info className="h-5 w-5 text-muted-foreground flex-shrink-0" />
                    )}
                    <div className="flex-1">
                      <div className="flex items-center gap-2 mb-1">
                        <span className="font-medium text-foreground">{item.name}</span>
                        <span className="text-xs bg-muted px-1.5 py-0.5 rounded capitalize">{item.type}</span>
                      </div>
                      <p className="text-sm text-muted-foreground">{item.purpose}</p>
                      <p className="text-sm text-primary mt-1">{item.suggestion}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </TabsContent>
      </Tabs>

      {/* Cleanup Dialog */}
      <AlertDialog open={showCleanupDialog} onOpenChange={setShowCleanupDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Cleanup Test Data</AlertDialogTitle>
            <AlertDialogDescription>
              This will remove all test data with the "QA_TEST_" prefix from the database.
              This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={cleanupTestData}
              disabled={cleaningUp}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {cleaningUp ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
              Cleanup
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DashboardLayout>
  );
}
