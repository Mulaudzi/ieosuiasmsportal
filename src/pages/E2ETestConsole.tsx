import { useState, useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Separator } from "@/components/ui/separator";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { toast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import {
  Play,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Clock,
  Copy,
  Download,
  RefreshCw,
  ChevronDown,
  ChevronRight,
  LayoutDashboard,
  MessageSquare,
  Mail,
  Users,
  FileText,
  Wallet,
  BarChart3,
  Settings,
  Loader2,
  Heart,
  Database,
} from "lucide-react";

interface TestResult {
  name: string;
  module: string;
  method?: string;
  endpoint?: string;
  payload?: Record<string, unknown> | null;
  status: "passed" | "failed" | "skipped" | "pending";
  response_status?: number | null;
  response_body?: unknown;
  db_query?: string | null;
  db_result?: unknown;
  error?: string | null;
  stack_trace?: string | null;
  duration_ms: number;
}

interface ModuleResult {
  module: string;
  tests: TestResult[];
}

interface TestResults {
  meta: {
    user_id: number;
    user_email: string;
    started_at: string;
    completed_at?: string;
    duration_ms?: number;
    php_version: string;
  };
  modules: Record<string, ModuleResult>;
  summary: {
    total: number;
    passed: number;
    failed: number;
    skipped: number;
  };
}

interface HealthData {
  database: boolean;
  tables: Record<string, { exists: boolean; records?: number; error?: string }>;
  user_data: Record<string, number>;
  error?: string;
}

const MODULE_CONFIG = [
  { id: "dashboard", name: "Dashboard", icon: LayoutDashboard, color: "text-blue-500" },
  { id: "contacts", name: "Contacts", icon: Users, color: "text-green-500" },
  { id: "contact_groups", name: "Contact Groups", icon: Users, color: "text-emerald-500" },
  { id: "templates", name: "Templates", icon: FileText, color: "text-purple-500" },
  { id: "sms_campaigns", name: "SMS Campaigns", icon: MessageSquare, color: "text-orange-500" },
  { id: "email_campaigns", name: "Email Campaigns", icon: Mail, color: "text-pink-500" },
  { id: "wallet", name: "Wallet", icon: Wallet, color: "text-yellow-500" },
  { id: "reports", name: "Reports", icon: BarChart3, color: "text-cyan-500" },
  { id: "settings", name: "Settings", icon: Settings, color: "text-gray-500" },
];

export default function E2ETestConsole() {
  const [selectedModules, setSelectedModules] = useState<string[]>(["all"]);
  const [isRunning, setIsRunning] = useState(false);
  const [results, setResults] = useState<TestResults | null>(null);
  const [expandedModules, setExpandedModules] = useState<string[]>([]);
  const [expandedTests, setExpandedTests] = useState<string[]>([]);
  const [healthData, setHealthData] = useState<HealthData | null>(null);
  const [isLoadingHealth, setIsLoadingHealth] = useState(false);

  useEffect(() => {
    loadHealth();
  }, []);

  const loadHealth = async () => {
    setIsLoadingHealth(true);
    try {
      const response = await api.get<{ health: HealthData }>("/e2e/health");
      if (response.data?.health) {
        setHealthData(response.data.health);
      }
    } catch (error) {
      console.error("Failed to load health:", error);
    } finally {
      setIsLoadingHealth(false);
    }
  };

  const toggleModule = (moduleId: string) => {
    if (moduleId === "all") {
      setSelectedModules(["all"]);
      return;
    }

    setSelectedModules((prev) => {
      const newSelection = prev.filter((m) => m !== "all");
      if (newSelection.includes(moduleId)) {
        const filtered = newSelection.filter((m) => m !== moduleId);
        return filtered.length === 0 ? ["all"] : filtered;
      }
      return [...newSelection, moduleId];
    });
  };

  const runTests = async () => {
    setIsRunning(true);
    setResults(null);
    setExpandedModules([]);
    setExpandedTests([]);

    try {
      const modules = selectedModules.includes("all")
        ? ["all"]
        : selectedModules;

      const response = await api.post<{ results: TestResults }>("/e2e/run", {
        modules,
      });

      if (response.data?.results) {
        setResults(response.data.results);

        // Auto-expand failed modules
        const failedModules = Object.entries(response.data.results.modules)
          .filter(([, data]) => data.tests.some((t) => t.status === "failed"))
          .map(([key]) => key);
        setExpandedModules(failedModules);

        // Show summary toast
        const summary = response.data.results.summary;
        if (summary.failed > 0) {
          toast({
            title: "Tests Completed with Failures",
            description: `${summary.passed} passed, ${summary.failed} failed, ${summary.skipped} skipped`,
            variant: "destructive",
          });
        } else {
          toast({
            title: "All Tests Passed! 🎉",
            description: `${summary.passed} tests passed in ${response.data.results.meta.duration_ms}ms`,
          });
        }
      }
    } catch (error) {
      toast({
        title: "Test Run Failed",
        description: error instanceof Error ? error.message : "Unknown error",
        variant: "destructive",
      });
    } finally {
      setIsRunning(false);
    }
  };

  const toggleModuleExpand = (moduleId: string) => {
    setExpandedModules((prev) =>
      prev.includes(moduleId)
        ? prev.filter((m) => m !== moduleId)
        : [...prev, moduleId]
    );
  };

  const toggleTestExpand = (testKey: string) => {
    setExpandedTests((prev) =>
      prev.includes(testKey)
        ? prev.filter((t) => t !== testKey)
        : [...prev, testKey]
    );
  };

  const copyFailedTests = () => {
    if (!results) return;

    const failedTests = Object.values(results.modules)
      .flatMap((m) => m.tests)
      .filter((t) => t.status === "failed");

    const report = failedTests
      .map((t) => {
        return `
=== ${t.name} ===
Module: ${t.module}
Method: ${t.method || "N/A"}
Endpoint: ${t.endpoint || "N/A"}
Status: ${t.response_status || "N/A"}
Error: ${t.error || "N/A"}
Payload: ${t.payload ? JSON.stringify(t.payload, null, 2) : "N/A"}
Response: ${t.response_body ? JSON.stringify(t.response_body, null, 2) : "N/A"}
Stack Trace: ${t.stack_trace || "N/A"}
`;
      })
      .join("\n---\n");

    navigator.clipboard.writeText(report);
    toast({ title: "Copied!", description: "Failed tests copied to clipboard" });
  };

  const exportFullReport = () => {
    if (!results) return;

    const report = JSON.stringify(results, null, 2);
    const blob = new Blob([report], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `e2e-test-report-${new Date().toISOString().split("T")[0]}.json`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case "passed":
        return <CheckCircle2 className="h-4 w-4 text-green-500" />;
      case "failed":
        return <XCircle className="h-4 w-4 text-red-500" />;
      case "skipped":
        return <AlertCircle className="h-4 w-4 text-yellow-500" />;
      default:
        return <Clock className="h-4 w-4 text-muted-foreground" />;
    }
  };

  const getStatusBadge = (status: string) => {
    const variants: Record<string, "default" | "destructive" | "secondary" | "outline"> = {
      passed: "default",
      failed: "destructive",
      skipped: "secondary",
      pending: "outline",
    };
    return (
      <Badge variant={variants[status] || "outline"} className="capitalize">
        {status}
      </Badge>
    );
  };

  return (
    <DashboardLayout title="E2E Test Console">
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">E2E Test Console</h1>
            <p className="text-muted-foreground">
              Comprehensive end-to-end testing for all application modules
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={loadHealth}
              disabled={isLoadingHealth}
            >
              {isLoadingHealth ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Heart className="h-4 w-4" />
              )}
              Health Check
            </Button>
            <Button onClick={runTests} disabled={isRunning} size="lg">
              {isRunning ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Running Tests...
                </>
              ) : (
                <>
                  <Play className="mr-2 h-4 w-4" />
                  Run All Tests
                </>
              )}
            </Button>
          </div>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Module Selection */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Select Modules</CardTitle>
              <CardDescription>Choose which modules to test</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              <label className="flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-muted">
                <Checkbox
                  checked={selectedModules.includes("all")}
                  onCheckedChange={() => toggleModule("all")}
                />
                <span className="font-medium">All Modules</span>
              </label>
              <Separator />
              {MODULE_CONFIG.map((module) => (
                <label
                  key={module.id}
                  className="flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-muted"
                >
                  <Checkbox
                    checked={
                      selectedModules.includes("all") ||
                      selectedModules.includes(module.id)
                    }
                    onCheckedChange={() => toggleModule(module.id)}
                    disabled={selectedModules.includes("all")}
                  />
                  <module.icon className={`h-4 w-4 ${module.color}`} />
                  <span>{module.name}</span>
                </label>
              ))}
            </CardContent>
          </Card>

          {/* Health Overview */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Database className="h-5 w-5" />
                System Health
              </CardTitle>
              <CardDescription>Database and table status</CardDescription>
            </CardHeader>
            <CardContent>
              {isLoadingHealth ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="h-6 w-6 animate-spin" />
                </div>
              ) : healthData ? (
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <span>Database Connection</span>
                    {healthData.database ? (
                      <Badge variant="default">Connected</Badge>
                    ) : (
                      <Badge variant="destructive">Disconnected</Badge>
                    )}
                  </div>
                  <Separator />
                  <ScrollArea className="h-[200px]">
                    <div className="space-y-2">
                      {Object.entries(healthData.tables || {}).map(
                        ([table, info]) => (
                          <div
                            key={table}
                            className="flex items-center justify-between text-sm"
                          >
                            <span className="font-mono">{table}</span>
                            {info.exists ? (
                              <span className="text-green-600">
                                {info.records} rows
                              </span>
                            ) : (
                              <span className="text-red-600">Missing</span>
                            )}
                          </div>
                        )
                      )}
                    </div>
                  </ScrollArea>
                  {healthData.user_data && (
                    <>
                      <Separator />
                      <div className="space-y-1">
                        <p className="text-sm font-medium">Your Data</p>
                        {Object.entries(healthData.user_data).map(
                          ([key, count]) => (
                            <div
                              key={key}
                              className="flex justify-between text-sm text-muted-foreground"
                            >
                              <span className="capitalize">{key}</span>
                              <span>{count}</span>
                            </div>
                          )
                        )}
                      </div>
                    </>
                  )}
                </div>
              ) : (
                <p className="text-muted-foreground text-sm">
                  Click Health Check to load status
                </p>
              )}
            </CardContent>
          </Card>

          {/* Summary */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Test Summary</CardTitle>
              <CardDescription>
                {results
                  ? `Completed in ${results.meta.duration_ms}ms`
                  : "Run tests to see results"}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {results ? (
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div className="text-center p-3 bg-green-500/10 rounded-lg">
                      <p className="text-2xl font-bold text-green-600">
                        {results.summary.passed}
                      </p>
                      <p className="text-sm text-muted-foreground">Passed</p>
                    </div>
                    <div className="text-center p-3 bg-red-500/10 rounded-lg">
                      <p className="text-2xl font-bold text-red-600">
                        {results.summary.failed}
                      </p>
                      <p className="text-sm text-muted-foreground">Failed</p>
                    </div>
                    <div className="text-center p-3 bg-yellow-500/10 rounded-lg">
                      <p className="text-2xl font-bold text-yellow-600">
                        {results.summary.skipped}
                      </p>
                      <p className="text-sm text-muted-foreground">Skipped</p>
                    </div>
                    <div className="text-center p-3 bg-muted rounded-lg">
                      <p className="text-2xl font-bold">
                        {results.summary.total}
                      </p>
                      <p className="text-sm text-muted-foreground">Total</p>
                    </div>
                  </div>
                  <Separator />
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      className="flex-1"
                      onClick={copyFailedTests}
                      disabled={results.summary.failed === 0}
                    >
                      <Copy className="mr-1 h-3 w-3" />
                      Copy Failures
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      className="flex-1"
                      onClick={exportFullReport}
                    >
                      <Download className="mr-1 h-3 w-3" />
                      Export JSON
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="text-center py-8 text-muted-foreground">
                  <RefreshCw className="h-8 w-8 mx-auto mb-2 opacity-50" />
                  <p>No test results yet</p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Test Results */}
        {results && (
          <Card>
            <CardHeader>
              <CardTitle>Test Results</CardTitle>
              <CardDescription>
                Detailed results for each module and test
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {Object.entries(results.modules).map(([moduleKey, moduleData]) => {
                  const moduleConfig = MODULE_CONFIG.find(
                    (m) => m.id === moduleKey
                  );
                  const passedCount = moduleData.tests.filter(
                    (t) => t.status === "passed"
                  ).length;
                  const failedCount = moduleData.tests.filter(
                    (t) => t.status === "failed"
                  ).length;
                  const Icon = moduleConfig?.icon || LayoutDashboard;

                  return (
                    <Collapsible
                      key={moduleKey}
                      open={expandedModules.includes(moduleKey)}
                      onOpenChange={() => toggleModuleExpand(moduleKey)}
                    >
                      <CollapsibleTrigger className="w-full">
                        <div className="flex items-center justify-between p-4 bg-muted/50 rounded-lg hover:bg-muted transition-colors">
                          <div className="flex items-center gap-3">
                            {expandedModules.includes(moduleKey) ? (
                              <ChevronDown className="h-4 w-4" />
                            ) : (
                              <ChevronRight className="h-4 w-4" />
                            )}
                            <Icon
                              className={`h-5 w-5 ${moduleConfig?.color || ""}`}
                            />
                            <span className="font-medium capitalize">
                              {moduleConfig?.name || moduleKey.replace("_", " ")}
                            </span>
                          </div>
                          <div className="flex items-center gap-2">
                            {passedCount > 0 && (
                              <Badge variant="default">{passedCount} passed</Badge>
                            )}
                            {failedCount > 0 && (
                              <Badge variant="destructive">
                                {failedCount} failed
                              </Badge>
                            )}
                          </div>
                        </div>
                      </CollapsibleTrigger>
                      <CollapsibleContent>
                        <div className="mt-2 space-y-2 pl-8">
                          {moduleData.tests.map((test, idx) => {
                            const testKey = `${moduleKey}-${idx}`;
                            const isExpanded = expandedTests.includes(testKey);

                            return (
                              <div
                                key={testKey}
                                className="border rounded-lg overflow-hidden"
                              >
                                <div
                                  className="flex items-center justify-between p-3 cursor-pointer hover:bg-muted/30"
                                  onClick={() => toggleTestExpand(testKey)}
                                >
                                  <div className="flex items-center gap-3">
                                    {getStatusIcon(test.status)}
                                    <span className="text-sm">{test.name}</span>
                                    {test.method && (
                                      <Badge variant="outline" className="text-xs">
                                        {test.method}
                                      </Badge>
                                    )}
                                  </div>
                                  <div className="flex items-center gap-2">
                                    <span className="text-xs text-muted-foreground">
                                      {test.duration_ms}ms
                                    </span>
                                    {getStatusBadge(test.status)}
                                  </div>
                                </div>

                                {isExpanded && (
                                  <div className="border-t p-4 bg-muted/20 space-y-3">
                                    {test.endpoint && (
                                      <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                          Endpoint
                                        </p>
                                        <code className="text-sm">
                                          {test.method} {test.endpoint}
                                        </code>
                                      </div>
                                    )}

                                    {test.response_status && (
                                      <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                          HTTP Status
                                        </p>
                                        <Badge
                                          variant={
                                            test.response_status >= 400
                                              ? "destructive"
                                              : "default"
                                          }
                                        >
                                          {test.response_status}
                                        </Badge>
                                      </div>
                                    )}

                                    {test.payload && (
                                      <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                          Request Payload
                                        </p>
                                        <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-32">
                                          {JSON.stringify(test.payload, null, 2)}
                                        </pre>
                                      </div>
                                    )}

                                    {test.response_body && (
                                      <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                          Response Body
                                        </p>
                                        <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-32">
                                          {JSON.stringify(test.response_body, null, 2)}
                                        </pre>
                                      </div>
                                    )}

                                    {test.error && (
                                      <div>
                                        <p className="text-xs font-medium text-red-500">
                                          Error
                                        </p>
                                        <p className="text-sm text-red-600">
                                          {test.error}
                                        </p>
                                      </div>
                                    )}

                                    {test.stack_trace && (
                                      <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                          Stack Trace
                                        </p>
                                        <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-48 text-red-500">
                                          {test.stack_trace}
                                        </pre>
                                      </div>
                                    )}

                                    {test.db_result && (
                                      <div>
                                        <p className="text-xs font-medium text-muted-foreground">
                                          Database Result
                                        </p>
                                        <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-32">
                                          {JSON.stringify(test.db_result, null, 2)}
                                        </pre>
                                      </div>
                                    )}

                                    {test.status === "failed" && (
                                      <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                          const report = `
Test: ${test.name}
Module: ${test.module}
Method: ${test.method || "N/A"}
Endpoint: ${test.endpoint || "N/A"}
HTTP Status: ${test.response_status || "N/A"}
Error: ${test.error || "N/A"}
Payload: ${test.payload ? JSON.stringify(test.payload, null, 2) : "N/A"}
Response: ${test.response_body ? JSON.stringify(test.response_body, null, 2) : "N/A"}
Stack Trace: ${test.stack_trace || "N/A"}
                                          `.trim();
                                          navigator.clipboard.writeText(report);
                                          toast({
                                            title: "Copied!",
                                            description: "Test details copied to clipboard",
                                          });
                                        }}
                                      >
                                        <Copy className="mr-1 h-3 w-3" />
                                        Copy Test Details
                                      </Button>
                                    )}
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      </CollapsibleContent>
                    </Collapsible>
                  );
                })}
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </DashboardLayout>
  );
}
