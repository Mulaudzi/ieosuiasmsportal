import { useState, useEffect, useCallback, useRef } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Separator } from "@/components/ui/separator";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
  Shield,
  Zap,
  Eye,
  Bug,
  FileJson,
  Terminal,
  AlertTriangle,
  Info,
  Trash2,
  Activity,
  Server,
  Lock,
  Globe,
  Code,
  CheckSquare,
  XSquare,
  Clipboard,
  FileWarning,
  TreeDeciduous,
  Network,
} from "lucide-react";

// ========== TYPE DEFINITIONS ==========

interface TestResult {
  id: string;
  name: string;
  module: string;
  category: "frontend" | "backend" | "database" | "auth" | "integration";
  method?: string;
  endpoint?: string;
  payload?: Record<string, unknown> | null;
  status: "passed" | "failed" | "skipped" | "pending" | "running";
  response_status?: number | null;
  response_body?: unknown;
  db_verification?: {
    query?: string;
    expected?: unknown;
    actual?: unknown;
    passed: boolean;
  };
  error?: string | null;
  stack_trace?: string | null;
  duration_ms: number;
  fix_suggestion?: string;
  severity: "critical" | "high" | "medium" | "low";
}

interface ModuleTestResult {
  module: string;
  displayName: string;
  icon: string;
  tests: TestResult[];
  passed: number;
  failed: number;
  skipped: number;
  duration_ms: number;
}

interface HealthCheckResult {
  database: {
    connected: boolean;
    latency_ms: number;
    version?: string;
  };
  tables: Record<string, {
    exists: boolean;
    records: number;
    hasData: boolean;
  }>;
  api: {
    reachable: boolean;
    latency_ms: number;
  };
  auth: {
    tokenValid: boolean;
    userLoaded: boolean;
  };
  mockDetection: {
    hasMocks: boolean;
    mockLocations: string[];
  };
}

interface TestSuite {
  id: string;
  name: string;
  description: string;
  tests: TestConfig[];
}

interface TestConfig {
  id: string;
  name: string;
  category: "frontend" | "backend" | "database" | "auth" | "integration";
  module: string;
  method: string;
  endpoint: string;
  payload?: Record<string, unknown>;
  expectedStatus: number[];
  dbVerification?: {
    table: string;
    query: string;
    expectation: "exists" | "not_exists" | "count_increased" | "data_matches";
  };
  cleanup?: boolean;
  severity: "critical" | "high" | "medium" | "low";
  fixSuggestion?: string;
}

interface CleanupRecord {
  table: string;
  id: number;
}

// ========== MODULE CONFIGURATION ==========

const MODULE_CONFIG = [
  { id: "auth", name: "Authentication", icon: Lock, color: "text-red-500", priority: 0 },
  { id: "dashboard", name: "Dashboard", icon: LayoutDashboard, color: "text-blue-500", priority: 1 },
  { id: "contacts", name: "Contacts", icon: Users, color: "text-green-500", priority: 2 },
  { id: "contact_groups", name: "Contact Groups", icon: Users, color: "text-emerald-500", priority: 3 },
  { id: "templates", name: "Templates", icon: FileText, color: "text-purple-500", priority: 4 },
  { id: "sms_campaigns", name: "SMS Campaigns", icon: MessageSquare, color: "text-orange-500", priority: 5 },
  { id: "email_campaigns", name: "Email Campaigns", icon: Mail, color: "text-pink-500", priority: 6 },
  { id: "wallet", name: "Wallet", icon: Wallet, color: "text-yellow-500", priority: 7 },
  { id: "reports", name: "Reports", icon: BarChart3, color: "text-cyan-500", priority: 8 },
  { id: "settings", name: "Settings", icon: Settings, color: "text-gray-500", priority: 9 },
];

const SEVERITY_CONFIG = {
  critical: { color: "text-red-600 bg-red-100", label: "Critical", icon: AlertTriangle },
  high: { color: "text-orange-600 bg-orange-100", label: "High", icon: AlertCircle },
  medium: { color: "text-yellow-600 bg-yellow-100", label: "Medium", icon: Info },
  low: { color: "text-blue-600 bg-blue-100", label: "Low", icon: Info },
};

// ========== MAIN COMPONENT ==========

export default function AutomatedTestDashboard() {
  // State Management
  const [selectedModules, setSelectedModules] = useState<string[]>(["all"]);
  const [isRunning, setIsRunning] = useState(false);
  const [isPreparing, setIsPreparing] = useState(false);
  const [currentTest, setCurrentTest] = useState<string | null>(null);
  const [progress, setProgress] = useState(0);
  const [results, setResults] = useState<ModuleTestResult[]>([]);
  const [expandedModules, setExpandedModules] = useState<string[]>([]);
  const [expandedTests, setExpandedTests] = useState<string[]>([]);
  const [healthData, setHealthData] = useState<HealthCheckResult | null>(null);
  const [isLoadingHealth, setIsLoadingHealth] = useState(false);
  const [autoRunOnLoad, setAutoRunOnLoad] = useState(false);
  const [cleanupRecords, setCleanupRecords] = useState<CleanupRecord[]>([]);
  const [activeTab, setActiveTab] = useState("dashboard");
  const [consoleLogs, setConsoleLogs] = useState<string[]>([]);
  const [rootCauseTree, setRootCauseTree] = useState<Record<string, string[]>>({});
  
  const abortControllerRef = useRef<AbortController | null>(null);

  // ========== LOGGING UTILITY ==========
  
  const log = useCallback((message: string, type: "info" | "success" | "error" | "warn" = "info") => {
    const timestamp = new Date().toISOString().split("T")[1].split(".")[0];
    const prefix = {
      info: "ℹ️",
      success: "✅",
      error: "❌",
      warn: "⚠️",
    }[type];
    const logMessage = `[${timestamp}] ${prefix} ${message}`;
    setConsoleLogs(prev => [...prev, logMessage]);
    console.log(logMessage);
  }, []);

  // ========== CLIPBOARD UTILITY ==========
  
  const copyToClipboard = useCallback(async (text: string, label: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast({ title: "Copied!", description: `${label} copied to clipboard` });
    } catch (err) {
      toast({ title: "Failed to copy", description: "Please copy manually", variant: "destructive" });
    }
  }, []);

  // ========== HEALTH CHECK ==========
  
  const runHealthCheck = useCallback(async () => {
    setIsLoadingHealth(true);
    log("Starting comprehensive health check...");
    
    const health: HealthCheckResult = {
      database: { connected: false, latency_ms: 0 },
      tables: {},
      api: { reachable: false, latency_ms: 0 },
      auth: { tokenValid: false, userLoaded: false },
      mockDetection: { hasMocks: false, mockLocations: [] },
    };
    
    try {
      // API Health
      const apiStart = performance.now();
      const apiResponse = await fetch(`${import.meta.env.VITE_API_URL || ""}/up`);
      health.api.latency_ms = Math.round(performance.now() - apiStart);
      health.api.reachable = apiResponse.ok;
      log(`API health check: ${health.api.reachable ? "OK" : "FAILED"} (${health.api.latency_ms}ms)`, health.api.reachable ? "success" : "error");
      
      // Auth Check
      const token = localStorage.getItem("auth_token");
      health.auth.tokenValid = !!token;
      
      if (token) {
        try {
          const userResponse = await api.get<{ user?: unknown }>("/auth/user");
          health.auth.userLoaded = !!userResponse.data?.user;
          log(`Auth check: Token ${health.auth.tokenValid ? "valid" : "invalid"}, User ${health.auth.userLoaded ? "loaded" : "not loaded"}`, health.auth.userLoaded ? "success" : "warn");
        } catch {
          health.auth.userLoaded = false;
          log("Auth check: Failed to load user", "error");
        }
      }
      
      // Database Health via Backend
      try {
        const response = await api.get<{ health: { database: boolean; tables: Record<string, { exists: boolean; records: number }> } }>("/e2e/health");
        if (response.data?.health) {
          health.database.connected = response.data.health.database;
          Object.entries(response.data.health.tables || {}).forEach(([table, info]) => {
            health.tables[table] = {
              exists: info.exists,
              records: info.records || 0,
              hasData: (info.records || 0) > 0,
            };
          });
          log(`Database check: ${health.database.connected ? "Connected" : "Disconnected"}`, health.database.connected ? "success" : "error");
        }
      } catch (err) {
        log("Database health check failed", "error");
      }
      
      // Mock Detection (check for common mock patterns in localStorage/sessionStorage)
      const mockPatterns = ["mock", "fake", "test_mode", "demo_mode"];
      mockPatterns.forEach(pattern => {
        if (localStorage.getItem(pattern) || sessionStorage.getItem(pattern)) {
          health.mockDetection.hasMocks = true;
          health.mockDetection.mockLocations.push(`storage:${pattern}`);
        }
      });
      
      // Check window for mock globals
      if ((window as unknown as Record<string, unknown>).__MOCK_API__ || (window as unknown as Record<string, unknown>).__TEST_MODE__) {
        health.mockDetection.hasMocks = true;
        health.mockDetection.mockLocations.push("window:__MOCK_API__");
      }
      
      if (health.mockDetection.hasMocks) {
        log(`Mock detection: Found mocks at ${health.mockDetection.mockLocations.join(", ")}`, "warn");
      } else {
        log("Mock detection: No mocks detected - using real data", "success");
      }
      
      setHealthData(health);
      
    } catch (error) {
      log(`Health check failed: ${error instanceof Error ? error.message : "Unknown error"}`, "error");
    } finally {
      setIsLoadingHealth(false);
    }
  }, [log]);

  // ========== CLEANUP EXISTING TEST DATA ==========
  
  const cleanupTestData = useCallback(async () => {
    log("Cleaning up previous test data...");
    
    // Clean from tracked records
    for (const record of cleanupRecords) {
      try {
        await api.delete(`/${record.table}/${record.id}`);
        log(`Cleaned up ${record.table}:${record.id}`, "info");
      } catch {
        // Ignore cleanup errors
      }
    }
    
    setCleanupRecords([]);
    log("Cleanup complete", "success");
  }, [cleanupRecords, log]);

  // ========== TEST EXECUTION ENGINE ==========
  
  const executeTest = useCallback(async (config: TestConfig): Promise<TestResult> => {
    const startTime = performance.now();
    
    const result: TestResult = {
      id: config.id,
      name: config.name,
      module: config.module,
      category: config.category,
      method: config.method,
      endpoint: config.endpoint,
      payload: config.payload,
      status: "running",
      severity: config.severity,
      duration_ms: 0,
    };
    
    try {
      let response;
      
      switch (config.method) {
        case "GET":
          response = await api.get(config.endpoint);
          break;
        case "POST":
          response = await api.post(config.endpoint, config.payload);
          break;
        case "PUT":
          response = await api.put(config.endpoint, config.payload);
          break;
        case "DELETE":
          response = await api.delete(config.endpoint);
          break;
        default:
          throw new Error(`Unknown method: ${config.method}`);
      }
      
      result.response_status = response.status || 200;
      result.response_body = response.data;
      
      // Check expected status
      if (!config.expectedStatus.includes(result.response_status)) {
        throw new Error(`Unexpected status ${result.response_status}, expected one of: ${config.expectedStatus.join(", ")}`);
      }
      
      // Track created records for cleanup
      if (config.method === "POST" && config.cleanup && response.data) {
        const createdId = response.data.id || response.data.contact?.id || response.data.template?.id || response.data.group?.id;
        if (createdId) {
          const table = config.endpoint.split("/")[1];
          setCleanupRecords(prev => [...prev, { table, id: createdId }]);
        }
      }
      
      result.status = "passed";
      
    } catch (error) {
      result.status = "failed";
      result.error = error instanceof Error ? error.message : "Unknown error";
      
      if (error instanceof Error && error.stack) {
        result.stack_trace = error.stack;
      }
      
      result.fix_suggestion = config.fixSuggestion || generateFixSuggestion(result);
      
      // Build root cause tree
      const rootCause = identifyRootCause(result);
      setRootCauseTree(prev => ({
        ...prev,
        [rootCause]: [...(prev[rootCause] || []), result.name],
      }));
    }
    
    result.duration_ms = Math.round(performance.now() - startTime);
    return result;
  }, []);

  // ========== FIX SUGGESTION GENERATOR ==========
  
  const generateFixSuggestion = (result: TestResult): string => {
    if (result.response_status === 401) {
      return "Check authentication token. Try logging out and back in. Verify JWT expiration.";
    }
    if (result.response_status === 403) {
      return "Insufficient permissions. Check user role/account_type. Verify RLS policies.";
    }
    if (result.response_status === 404) {
      return "Resource not found. Check if the record exists. Verify endpoint URL.";
    }
    if (result.response_status === 422) {
      return "Validation failed. Check request payload matches expected schema.";
    }
    if (result.response_status === 500) {
      return "Server error. Check backend logs. Look for PHP errors in api/logs.";
    }
    if (result.error?.includes("Network")) {
      return "Network error. Check CORS settings. Verify API URL configuration.";
    }
    return "Review error details and stack trace. Check related controllers and database.";
  };

  // ========== ROOT CAUSE IDENTIFIER ==========
  
  const identifyRootCause = (result: TestResult): string => {
    if (result.response_status === 401) return "Authentication Issues";
    if (result.response_status === 403) return "Authorization Issues";
    if (result.response_status === 404) return "Missing Resources";
    if (result.response_status === 422) return "Validation Failures";
    if (result.response_status === 500) return "Server Errors";
    if (result.error?.includes("Network")) return "Network/CORS Issues";
    if (result.error?.includes("Database")) return "Database Issues";
    return "Other Issues";
  };

  // ========== DEFINE ALL TEST SUITES ==========
  
  const generateTestSuites = useCallback((): TestConfig[] => {
    const timestamp = Date.now();
    const testPhone = `+1${2000000000 + Math.floor(Math.random() * 999999999)}`;
    const testEmail = `e2e_test_${timestamp}@test.local`;
    
    return [
      // ========== AUTH TESTS ==========
      {
        id: "auth_get_user",
        name: "GET Current User",
        category: "auth",
        module: "auth",
        method: "GET",
        endpoint: "/auth/user",
        expectedStatus: [200],
        severity: "critical",
        fixSuggestion: "Check if auth token exists in localStorage. Token may be expired.",
      },
      {
        id: "auth_refresh",
        name: "Refresh Token",
        category: "auth",
        module: "auth",
        method: "POST",
        endpoint: "/auth/refresh",
        expectedStatus: [200],
        severity: "critical",
      },
      
      // ========== DASHBOARD TESTS ==========
      {
        id: "dashboard_stats",
        name: "GET Dashboard Stats",
        category: "backend",
        module: "dashboard",
        method: "GET",
        endpoint: "/dashboard/stats",
        expectedStatus: [200],
        severity: "high",
      },
      {
        id: "dashboard_chart",
        name: "GET Dashboard Chart",
        category: "backend",
        module: "dashboard",
        method: "GET",
        endpoint: "/dashboard/chart?days=30",
        expectedStatus: [200],
        severity: "medium",
      },
      {
        id: "dashboard_recent",
        name: "GET Recent Campaigns",
        category: "backend",
        module: "dashboard",
        method: "GET",
        endpoint: "/dashboard/recent-campaigns",
        expectedStatus: [200],
        severity: "medium",
      },
      {
        id: "dashboard_recommendations",
        name: "GET Schedule Recommendations",
        category: "backend",
        module: "dashboard",
        method: "GET",
        endpoint: "/dashboard/schedule-recommendations?type=sms",
        expectedStatus: [200],
        severity: "low",
      },
      
      // ========== CONTACTS TESTS ==========
      {
        id: "contacts_list",
        name: "GET Contacts List",
        category: "backend",
        module: "contacts",
        method: "GET",
        endpoint: "/contacts",
        expectedStatus: [200],
        severity: "high",
      },
      {
        id: "contacts_create",
        name: "CREATE Contact",
        category: "integration",
        module: "contacts",
        method: "POST",
        endpoint: "/contacts",
        payload: {
          name: `E2E Test Contact ${timestamp}`,
          phone: testPhone,
          email: testEmail,
        },
        expectedStatus: [200, 201],
        cleanup: true,
        severity: "critical",
        fixSuggestion: "Check contacts table schema. Verify phone number format validation.",
      },
      {
        id: "contacts_export",
        name: "Export Contacts",
        category: "backend",
        module: "contacts",
        method: "GET",
        endpoint: "/contacts/export",
        expectedStatus: [200],
        severity: "medium",
      },
      
      // ========== CONTACT GROUPS TESTS ==========
      {
        id: "groups_list",
        name: "GET Contact Groups",
        category: "backend",
        module: "contact_groups",
        method: "GET",
        endpoint: "/contact-groups",
        expectedStatus: [200],
        severity: "high",
      },
      {
        id: "groups_create",
        name: "CREATE Contact Group",
        category: "integration",
        module: "contact_groups",
        method: "POST",
        endpoint: "/contact-groups",
        payload: {
          name: `E2E Test Group ${timestamp}`,
          description: "Created by automated E2E tests",
        },
        expectedStatus: [200, 201],
        cleanup: true,
        severity: "high",
      },
      
      // ========== TEMPLATES TESTS ==========
      {
        id: "templates_list",
        name: "GET Templates List",
        category: "backend",
        module: "templates",
        method: "GET",
        endpoint: "/templates",
        expectedStatus: [200],
        severity: "high",
      },
      {
        id: "templates_create",
        name: "CREATE Template",
        category: "integration",
        module: "templates",
        method: "POST",
        endpoint: "/templates",
        payload: {
          name: `E2E Test Template ${timestamp}`,
          content: "Hello {{name}}, this is an automated test message.",
          type: "sms",
        },
        expectedStatus: [200, 201],
        cleanup: true,
        severity: "high",
      },
      
      // ========== SMS CAMPAIGNS TESTS ==========
      {
        id: "sms_campaigns_list",
        name: "GET SMS Campaigns",
        category: "backend",
        module: "sms_campaigns",
        method: "GET",
        endpoint: "/sms/campaigns",
        expectedStatus: [200],
        severity: "high",
      },
      {
        id: "sms_campaigns_create",
        name: "CREATE SMS Campaign (Draft)",
        category: "integration",
        module: "sms_campaigns",
        method: "POST",
        endpoint: "/sms/campaigns",
        payload: {
          name: `E2E Test SMS ${timestamp}`,
          message: "Automated test message",
          recipients: ["+10000000000"],
          status: "draft",
        },
        expectedStatus: [200, 201],
        cleanup: true,
        severity: "critical",
      },
      
      // ========== EMAIL CAMPAIGNS TESTS ==========
      {
        id: "email_campaigns_list",
        name: "GET Email Campaigns",
        category: "backend",
        module: "email_campaigns",
        method: "GET",
        endpoint: "/email/campaigns",
        expectedStatus: [200],
        severity: "high",
      },
      {
        id: "email_campaigns_create",
        name: "CREATE Email Campaign (Draft)",
        category: "integration",
        module: "email_campaigns",
        method: "POST",
        endpoint: "/email/campaigns",
        payload: {
          name: `E2E Test Email ${timestamp}`,
          subject: "Automated Test",
          content: "<p>Automated test email content</p>",
          recipients: ["test@invalid.local"],
          status: "draft",
        },
        expectedStatus: [200, 201],
        cleanup: true,
        severity: "critical",
      },
      {
        id: "email_limits",
        name: "GET Email Limits",
        category: "backend",
        module: "email_campaigns",
        method: "GET",
        endpoint: "/email/limits",
        expectedStatus: [200],
        severity: "medium",
      },
      
      // ========== WALLET TESTS ==========
      {
        id: "wallet_balance",
        name: "GET Wallet Balance",
        category: "backend",
        module: "wallet",
        method: "GET",
        endpoint: "/wallet",
        expectedStatus: [200],
        severity: "critical",
      },
      {
        id: "wallet_stats",
        name: "GET Wallet Stats",
        category: "backend",
        module: "wallet",
        method: "GET",
        endpoint: "/wallet/stats",
        expectedStatus: [200],
        severity: "medium",
      },
      {
        id: "wallet_transactions",
        name: "GET Wallet Transactions",
        category: "backend",
        module: "wallet",
        method: "GET",
        endpoint: "/wallet/transactions",
        expectedStatus: [200],
        severity: "medium",
      },
      {
        id: "wallet_packages",
        name: "GET Credit Packages",
        category: "backend",
        module: "wallet",
        method: "GET",
        endpoint: "/wallet/packages",
        expectedStatus: [200],
        severity: "high",
      },
      
      // ========== REPORTS TESTS ==========
      {
        id: "reports_stats",
        name: "GET Report Stats",
        category: "backend",
        module: "reports",
        method: "GET",
        endpoint: "/reports/stats",
        expectedStatus: [200],
        severity: "high",
      },
      {
        id: "reports_chart",
        name: "GET Report Chart",
        category: "backend",
        module: "reports",
        method: "GET",
        endpoint: "/reports/chart",
        expectedStatus: [200],
        severity: "medium",
      },
      {
        id: "reports_delivery",
        name: "GET Delivery Breakdown",
        category: "backend",
        module: "reports",
        method: "GET",
        endpoint: "/reports/delivery",
        expectedStatus: [200],
        severity: "medium",
      },
      {
        id: "reports_campaigns",
        name: "GET Campaigns Report",
        category: "backend",
        module: "reports",
        method: "GET",
        endpoint: "/reports/campaigns",
        expectedStatus: [200],
        severity: "medium",
      },
      
      // ========== SETTINGS TESTS ==========
      {
        id: "settings_profile",
        name: "GET User Profile",
        category: "backend",
        module: "settings",
        method: "GET",
        endpoint: "/settings/profile",
        expectedStatus: [200],
        severity: "high",
      },
      
      // ========== NOTIFICATIONS TESTS ==========
      {
        id: "notifications_list",
        name: "GET Notifications",
        category: "backend",
        module: "settings",
        method: "GET",
        endpoint: "/notifications",
        expectedStatus: [200],
        severity: "medium",
      },
    ];
  }, []);

  // ========== RUN ALL TESTS ==========
  
  const runTests = useCallback(async () => {
    setIsRunning(true);
    setIsPreparing(true);
    setResults([]);
    setRootCauseTree({});
    setConsoleLogs([]);
    setExpandedModules([]);
    setExpandedTests([]);
    setProgress(0);
    
    abortControllerRef.current = new AbortController();
    
    log("🚀 AUTOMATED TEST SUITE STARTING");
    log("=====================================");
    
    // Phase 1: Cleanup
    log("Phase 1: Cleaning up previous test data...");
    await cleanupTestData();
    
    // Phase 2: Health Check
    log("Phase 2: Running health checks...");
    await runHealthCheck();
    
    setIsPreparing(false);
    
    // Phase 3: Generate and Run Tests
    log("Phase 3: Executing test suite...");
    
    const allTests = generateTestSuites();
    const modulesToTest = selectedModules.includes("all") 
      ? MODULE_CONFIG.map(m => m.id)
      : selectedModules;
    
    const filteredTests = allTests.filter(t => modulesToTest.includes(t.module));
    const totalTests = filteredTests.length;
    
    log(`Running ${totalTests} tests across ${modulesToTest.length} modules`);
    
    const moduleResults: Record<string, ModuleTestResult> = {};
    
    // Initialize module results
    MODULE_CONFIG.forEach(m => {
      if (modulesToTest.includes(m.id)) {
        moduleResults[m.id] = {
          module: m.id,
          displayName: m.name,
          icon: m.id,
          tests: [],
          passed: 0,
          failed: 0,
          skipped: 0,
          duration_ms: 0,
        };
      }
    });
    
    // Execute tests sequentially
    for (let i = 0; i < filteredTests.length; i++) {
      const testConfig = filteredTests[i];
      setCurrentTest(testConfig.name);
      setProgress(Math.round(((i + 1) / totalTests) * 100));
      
      log(`[${i + 1}/${totalTests}] Testing: ${testConfig.name}`);
      
      const result = await executeTest(testConfig);
      
      if (moduleResults[testConfig.module]) {
        moduleResults[testConfig.module].tests.push(result);
        moduleResults[testConfig.module].duration_ms += result.duration_ms;
        
        if (result.status === "passed") {
          moduleResults[testConfig.module].passed++;
          log(`  ✅ PASSED (${result.duration_ms}ms)`, "success");
        } else if (result.status === "failed") {
          moduleResults[testConfig.module].failed++;
          log(`  ❌ FAILED: ${result.error} (${result.duration_ms}ms)`, "error");
        } else {
          moduleResults[testConfig.module].skipped++;
          log(`  ⏭️ SKIPPED`, "warn");
        }
      }
      
      // Update results in real-time
      setResults(Object.values(moduleResults));
    }
    
    // Phase 4: Final Cleanup
    log("Phase 4: Cleaning up test records...");
    await cleanupTestData();
    
    // Phase 5: Summary
    log("=====================================");
    log("🏁 TEST SUITE COMPLETE");
    
    const finalResults = Object.values(moduleResults);
    const totalPassed = finalResults.reduce((sum, m) => sum + m.passed, 0);
    const totalFailed = finalResults.reduce((sum, m) => sum + m.failed, 0);
    const totalSkipped = finalResults.reduce((sum, m) => sum + m.skipped, 0);
    
    log(`Total: ${totalPassed} passed, ${totalFailed} failed, ${totalSkipped} skipped`);
    
    // Auto-expand failed modules
    const failedModules = finalResults.filter(m => m.failed > 0).map(m => m.module);
    setExpandedModules(failedModules);
    
    // Show toast
    if (totalFailed > 0) {
      toast({
        title: "Tests Completed with Failures",
        description: `${totalPassed} passed, ${totalFailed} failed`,
        variant: "destructive",
      });
    } else {
      toast({
        title: "All Tests Passed! 🎉",
        description: `${totalPassed} tests passed successfully`,
      });
    }
    
    setCurrentTest(null);
    setIsRunning(false);
  }, [selectedModules, cleanupTestData, runHealthCheck, generateTestSuites, executeTest, log]);

  // ========== EXPORT FUNCTIONS ==========
  
  const exportFullReport = useCallback(() => {
    const report = {
      generated_at: new Date().toISOString(),
      health: healthData,
      results,
      rootCauseTree,
      consoleLogs,
      summary: {
        total: results.reduce((sum, m) => sum + m.tests.length, 0),
        passed: results.reduce((sum, m) => sum + m.passed, 0),
        failed: results.reduce((sum, m) => sum + m.failed, 0),
        skipped: results.reduce((sum, m) => sum + m.skipped, 0),
      },
    };
    
    const blob = new Blob([JSON.stringify(report, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `test-report-${new Date().toISOString().split("T")[0]}.json`;
    a.click();
    URL.revokeObjectURL(url);
    
    toast({ title: "Report exported", description: "JSON report downloaded" });
  }, [healthData, results, rootCauseTree, consoleLogs]);
  
  const copyFailedTests = useCallback(() => {
    const failedTests = results.flatMap(m => m.tests.filter(t => t.status === "failed"));
    
    if (failedTests.length === 0) {
      toast({ title: "No failures", description: "All tests passed" });
      return;
    }
    
    const report = failedTests.map(t => `
═══════════════════════════════════════
TEST: ${t.name}
MODULE: ${t.module}
SEVERITY: ${t.severity.toUpperCase()}
═══════════════════════════════════════
Method: ${t.method} ${t.endpoint}
HTTP Status: ${t.response_status || "N/A"}
Error: ${t.error || "N/A"}
Fix Suggestion: ${t.fix_suggestion || "N/A"}

Payload:
${t.payload ? JSON.stringify(t.payload, null, 2) : "N/A"}

Response:
${t.response_body ? JSON.stringify(t.response_body, null, 2) : "N/A"}

Stack Trace:
${t.stack_trace || "N/A"}
`).join("\n");
    
    copyToClipboard(report, "Failed tests report");
  }, [results, copyToClipboard]);

  // ========== TOGGLE FUNCTIONS ==========
  
  const toggleModule = (moduleId: string) => {
    if (moduleId === "all") {
      setSelectedModules(["all"]);
      return;
    }
    setSelectedModules(prev => {
      const newSelection = prev.filter(m => m !== "all");
      if (newSelection.includes(moduleId)) {
        const filtered = newSelection.filter(m => m !== moduleId);
        return filtered.length === 0 ? ["all"] : filtered;
      }
      return [...newSelection, moduleId];
    });
  };
  
  const toggleModuleExpand = (moduleId: string) => {
    setExpandedModules(prev =>
      prev.includes(moduleId) ? prev.filter(m => m !== moduleId) : [...prev, moduleId]
    );
  };
  
  const toggleTestExpand = (testKey: string) => {
    setExpandedTests(prev =>
      prev.includes(testKey) ? prev.filter(t => t !== testKey) : [...prev, testKey]
    );
  };

  // ========== STATUS HELPERS ==========
  
  const getStatusIcon = (status: string) => {
    switch (status) {
      case "passed": return <CheckCircle2 className="h-4 w-4 text-green-500" />;
      case "failed": return <XCircle className="h-4 w-4 text-red-500" />;
      case "skipped": return <AlertCircle className="h-4 w-4 text-yellow-500" />;
      case "running": return <Loader2 className="h-4 w-4 text-blue-500 animate-spin" />;
      default: return <Clock className="h-4 w-4 text-muted-foreground" />;
    }
  };
  
  const getStatusBadge = (status: string) => {
    const variants: Record<string, "default" | "destructive" | "secondary" | "outline"> = {
      passed: "default",
      failed: "destructive",
      skipped: "secondary",
      pending: "outline",
      running: "outline",
    };
    return <Badge variant={variants[status] || "outline"} className="capitalize">{status}</Badge>;
  };

  // ========== SUMMARY CALCULATIONS ==========
  
  const totalTests = results.reduce((sum, m) => sum + m.tests.length, 0);
  const totalPassed = results.reduce((sum, m) => sum + m.passed, 0);
  const totalFailed = results.reduce((sum, m) => sum + m.failed, 0);
  const totalSkipped = results.reduce((sum, m) => sum + m.skipped, 0);
  const totalDuration = results.reduce((sum, m) => sum + m.duration_ms, 0);

  // ========== AUTO-RUN ON LOAD ==========
  
  useEffect(() => {
    if (autoRunOnLoad) {
      runTests();
    }
  }, [autoRunOnLoad]);

  // ========== RENDER ==========
  
  return (
    <DashboardLayout
      title="Automated Test Dashboard"
      subtitle="Comprehensive E2E testing with cleanup, diagnostics, and fix suggestions"
      actions={
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={runHealthCheck} disabled={isLoadingHealth}>
            {isLoadingHealth ? <Loader2 className="h-4 w-4 animate-spin" /> : <Heart className="h-4 w-4" />}
            <span className="ml-2">Health Check</span>
          </Button>
          <Button onClick={runTests} disabled={isRunning} size="lg" className="gap-2">
            {isRunning ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                {isPreparing ? "Preparing..." : `Testing (${progress}%)`}
              </>
            ) : (
              <>
                <Play className="h-4 w-4" />
                Run All Tests
              </>
            )}
          </Button>
        </div>
      }
    >
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="dashboard" className="gap-2">
            <LayoutDashboard className="h-4 w-4" />
            Dashboard
          </TabsTrigger>
          <TabsTrigger value="results" className="gap-2">
            <CheckSquare className="h-4 w-4" />
            Results
          </TabsTrigger>
          <TabsTrigger value="rootcause" className="gap-2">
            <TreeDeciduous className="h-4 w-4" />
            Root Causes
          </TabsTrigger>
          <TabsTrigger value="console" className="gap-2">
            <Terminal className="h-4 w-4" />
            Console
          </TabsTrigger>
          <TabsTrigger value="health" className="gap-2">
            <Activity className="h-4 w-4" />
            Health
          </TabsTrigger>
        </TabsList>

        {/* ========== DASHBOARD TAB ========== */}
        <TabsContent value="dashboard" className="space-y-6">
          {isRunning && (
            <Card>
              <CardContent className="pt-6">
                <div className="space-y-2">
                  <div className="flex justify-between text-sm">
                    <span>{currentTest || "Initializing..."}</span>
                    <span>{progress}%</span>
                  </div>
                  <Progress value={progress} className="h-2" />
                </div>
              </CardContent>
            </Card>
          )}

          <div className="grid gap-6 lg:grid-cols-3">
            {/* Module Selection */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <Settings className="h-5 w-5" />
                  Test Configuration
                </CardTitle>
                <CardDescription>Select modules to test</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <label className="flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-muted">
                  <Checkbox
                    checked={selectedModules.includes("all")}
                    onCheckedChange={() => toggleModule("all")}
                  />
                  <Zap className="h-4 w-4 text-yellow-500" />
                  <span className="font-medium">All Modules</span>
                </label>
                <Separator />
                <ScrollArea className="h-[250px]">
                  {MODULE_CONFIG.map(module => {
                    const Icon = module.icon;
                    return (
                      <label
                        key={module.id}
                        className="flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-muted"
                      >
                        <Checkbox
                          checked={selectedModules.includes("all") || selectedModules.includes(module.id)}
                          onCheckedChange={() => toggleModule(module.id)}
                          disabled={selectedModules.includes("all")}
                        />
                        <Icon className={`h-4 w-4 ${module.color}`} />
                        <span>{module.name}</span>
                      </label>
                    );
                  })}
                </ScrollArea>
              </CardContent>
            </Card>

            {/* Test Summary */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <BarChart3 className="h-5 w-5" />
                  Test Summary
                </CardTitle>
                <CardDescription>
                  {totalTests > 0 ? `Completed in ${totalDuration}ms` : "Run tests to see results"}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {totalTests > 0 ? (
                  <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                      <div className="text-center p-3 bg-green-500/10 rounded-lg">
                        <p className="text-2xl font-bold text-green-600">{totalPassed}</p>
                        <p className="text-sm text-muted-foreground">Passed</p>
                      </div>
                      <div className="text-center p-3 bg-red-500/10 rounded-lg">
                        <p className="text-2xl font-bold text-red-600">{totalFailed}</p>
                        <p className="text-sm text-muted-foreground">Failed</p>
                      </div>
                      <div className="text-center p-3 bg-yellow-500/10 rounded-lg">
                        <p className="text-2xl font-bold text-yellow-600">{totalSkipped}</p>
                        <p className="text-sm text-muted-foreground">Skipped</p>
                      </div>
                      <div className="text-center p-3 bg-muted rounded-lg">
                        <p className="text-2xl font-bold">{totalTests}</p>
                        <p className="text-sm text-muted-foreground">Total</p>
                      </div>
                    </div>
                    <Separator />
                    <div className="flex gap-2">
                      <Button variant="outline" size="sm" className="flex-1" onClick={copyFailedTests} disabled={totalFailed === 0}>
                        <Copy className="mr-1 h-3 w-3" />
                        Copy Failures
                      </Button>
                      <Button variant="outline" size="sm" className="flex-1" onClick={exportFullReport}>
                        <Download className="mr-1 h-3 w-3" />
                        Export JSON
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="text-center py-8 text-muted-foreground">
                    <RefreshCw className="h-8 w-8 mx-auto mb-2 opacity-50" />
                    <p>No test results yet</p>
                    <p className="text-xs mt-1">Click "Run All Tests" to start</p>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Quick Health Status */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-lg">
                  <Activity className="h-5 w-5" />
                  Quick Status
                </CardTitle>
                <CardDescription>System health overview</CardDescription>
              </CardHeader>
              <CardContent>
                {healthData ? (
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <span className="flex items-center gap-2">
                        <Server className="h-4 w-4" />
                        API
                      </span>
                      <Badge variant={healthData.api.reachable ? "default" : "destructive"}>
                        {healthData.api.reachable ? `OK (${healthData.api.latency_ms}ms)` : "DOWN"}
                      </Badge>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="flex items-center gap-2">
                        <Database className="h-4 w-4" />
                        Database
                      </span>
                      <Badge variant={healthData.database.connected ? "default" : "destructive"}>
                        {healthData.database.connected ? "Connected" : "Disconnected"}
                      </Badge>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="flex items-center gap-2">
                        <Lock className="h-4 w-4" />
                        Auth Token
                      </span>
                      <Badge variant={healthData.auth.tokenValid ? "default" : "destructive"}>
                        {healthData.auth.tokenValid ? "Valid" : "Invalid"}
                      </Badge>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="flex items-center gap-2">
                        <Shield className="h-4 w-4" />
                        Mock Detection
                      </span>
                      <Badge variant={healthData.mockDetection.hasMocks ? "destructive" : "default"}>
                        {healthData.mockDetection.hasMocks ? "MOCKS DETECTED" : "Real Data"}
                      </Badge>
                    </div>
                  </div>
                ) : (
                  <div className="text-center py-4 text-muted-foreground">
                    <p className="text-sm">Click Health Check to load</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Production Readiness Verdict */}
          {totalTests > 0 && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Shield className="h-5 w-5" />
                  Production Readiness Verdict
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="flex items-center gap-4">
                  {totalFailed === 0 ? (
                    <>
                      <div className="flex items-center justify-center w-16 h-16 rounded-full bg-green-500/10">
                        <CheckCircle2 className="h-8 w-8 text-green-500" />
                      </div>
                      <div>
                        <p className="text-xl font-bold text-green-600">✅ PRODUCTION READY</p>
                        <p className="text-muted-foreground">All {totalPassed} tests passed. System is ready for deployment.</p>
                      </div>
                    </>
                  ) : totalFailed <= 3 ? (
                    <>
                      <div className="flex items-center justify-center w-16 h-16 rounded-full bg-yellow-500/10">
                        <AlertCircle className="h-8 w-8 text-yellow-500" />
                      </div>
                      <div>
                        <p className="text-xl font-bold text-yellow-600">⚠️ READY WITH CAVEATS</p>
                        <p className="text-muted-foreground">{totalFailed} test(s) failed. Review failures before deploying.</p>
                      </div>
                    </>
                  ) : (
                    <>
                      <div className="flex items-center justify-center w-16 h-16 rounded-full bg-red-500/10">
                        <XCircle className="h-8 w-8 text-red-500" />
                      </div>
                      <div>
                        <p className="text-xl font-bold text-red-600">❌ NOT READY</p>
                        <p className="text-muted-foreground">{totalFailed} tests failed. Critical issues must be resolved.</p>
                      </div>
                    </>
                  )}
                </div>
              </CardContent>
            </Card>
          )}
        </TabsContent>

        {/* ========== RESULTS TAB ========== */}
        <TabsContent value="results" className="space-y-6">
          {results.length > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle>Detailed Test Results</CardTitle>
                <CardDescription>Click on a module to expand test details</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="space-y-4">
                  {results.map(moduleResult => {
                    const moduleConfig = MODULE_CONFIG.find(m => m.id === moduleResult.module);
                    const Icon = moduleConfig?.icon || LayoutDashboard;
                    
                    return (
                      <Collapsible
                        key={moduleResult.module}
                        open={expandedModules.includes(moduleResult.module)}
                        onOpenChange={() => toggleModuleExpand(moduleResult.module)}
                      >
                        <CollapsibleTrigger className="w-full">
                          <div className="flex items-center justify-between p-4 bg-muted/50 rounded-lg hover:bg-muted transition-colors">
                            <div className="flex items-center gap-3">
                              {expandedModules.includes(moduleResult.module) ? (
                                <ChevronDown className="h-4 w-4" />
                              ) : (
                                <ChevronRight className="h-4 w-4" />
                              )}
                              <Icon className={`h-5 w-5 ${moduleConfig?.color || ""}`} />
                              <span className="font-medium">{moduleResult.displayName}</span>
                              <span className="text-xs text-muted-foreground">({moduleResult.duration_ms}ms)</span>
                            </div>
                            <div className="flex items-center gap-2">
                              {moduleResult.passed > 0 && (
                                <Badge variant="default">{moduleResult.passed} passed</Badge>
                              )}
                              {moduleResult.failed > 0 && (
                                <Badge variant="destructive">{moduleResult.failed} failed</Badge>
                              )}
                            </div>
                          </div>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                          <div className="mt-2 space-y-2 pl-8">
                            {moduleResult.tests.map((test, idx) => {
                              const testKey = `${moduleResult.module}-${idx}`;
                              const isExpanded = expandedTests.includes(testKey);
                              const SeverityIcon = SEVERITY_CONFIG[test.severity].icon;

                              return (
                                <div key={testKey} className="border rounded-lg overflow-hidden">
                                  <div
                                    className="flex items-center justify-between p-3 cursor-pointer hover:bg-muted/30"
                                    onClick={() => toggleTestExpand(testKey)}
                                  >
                                    <div className="flex items-center gap-3">
                                      {getStatusIcon(test.status)}
                                      <span className="text-sm">{test.name}</span>
                                      <Badge variant="outline" className="text-xs">{test.method}</Badge>
                                      <Badge className={`text-xs ${SEVERITY_CONFIG[test.severity].color}`}>
                                        <SeverityIcon className="h-3 w-3 mr-1" />
                                        {test.severity}
                                      </Badge>
                                    </div>
                                    <div className="flex items-center gap-2">
                                      <span className="text-xs text-muted-foreground">{test.duration_ms}ms</span>
                                      {getStatusBadge(test.status)}
                                    </div>
                                  </div>

                                  {isExpanded && (
                                    <div className="border-t p-4 bg-muted/20 space-y-3">
                                      {test.endpoint && (
                                        <div>
                                          <p className="text-xs font-medium text-muted-foreground">Endpoint</p>
                                          <code className="text-sm">{test.method} {test.endpoint}</code>
                                        </div>
                                      )}

                                      {test.response_status && (
                                        <div>
                                          <p className="text-xs font-medium text-muted-foreground">HTTP Status</p>
                                          <Badge variant={test.response_status >= 400 ? "destructive" : "default"}>
                                            {test.response_status}
                                          </Badge>
                                        </div>
                                      )}

                                      {test.payload && (
                                        <div>
                                          <div className="flex items-center justify-between">
                                            <p className="text-xs font-medium text-muted-foreground">Request Payload</p>
                                            <Button
                                              variant="ghost"
                                              size="sm"
                                              className="h-6 px-2"
                                              onClick={() => copyToClipboard(JSON.stringify(test.payload, null, 2), "Payload")}
                                            >
                                              <Copy className="h-3 w-3" />
                                            </Button>
                                          </div>
                                          <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-32">
                                            {JSON.stringify(test.payload, null, 2)}
                                          </pre>
                                        </div>
                                      )}

                                      {test.response_body && (
                                        <div>
                                          <div className="flex items-center justify-between">
                                            <p className="text-xs font-medium text-muted-foreground">Response</p>
                                            <Button
                                              variant="ghost"
                                              size="sm"
                                              className="h-6 px-2"
                                              onClick={() => copyToClipboard(JSON.stringify(test.response_body, null, 2), "Response")}
                                            >
                                              <Copy className="h-3 w-3" />
                                            </Button>
                                          </div>
                                          <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-32">
                                            {JSON.stringify(test.response_body, null, 2)}
                                          </pre>
                                        </div>
                                      )}

                                      {test.error && (
                                        <div>
                                          <p className="text-xs font-medium text-red-500">Error</p>
                                          <p className="text-sm text-red-600">{test.error}</p>
                                        </div>
                                      )}

                                      {test.fix_suggestion && test.status === "failed" && (
                                        <div className="bg-blue-50 dark:bg-blue-950 p-3 rounded-lg">
                                          <p className="text-xs font-medium text-blue-600 dark:text-blue-400 flex items-center gap-1">
                                            <Bug className="h-3 w-3" />
                                            Fix Suggestion
                                          </p>
                                          <p className="text-sm text-blue-800 dark:text-blue-200 mt-1">{test.fix_suggestion}</p>
                                        </div>
                                      )}

                                      {test.stack_trace && (
                                        <div>
                                          <div className="flex items-center justify-between">
                                            <p className="text-xs font-medium text-muted-foreground">Stack Trace</p>
                                            <Button
                                              variant="ghost"
                                              size="sm"
                                              className="h-6 px-2"
                                              onClick={() => copyToClipboard(test.stack_trace || "", "Stack trace")}
                                            >
                                              <Copy className="h-3 w-3" />
                                            </Button>
                                          </div>
                                          <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-48 text-red-500">
                                            {test.stack_trace}
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
Severity: ${test.severity.toUpperCase()}
Method: ${test.method} ${test.endpoint}
HTTP Status: ${test.response_status || "N/A"}
Error: ${test.error || "N/A"}
Fix Suggestion: ${test.fix_suggestion || "N/A"}

Payload:
${test.payload ? JSON.stringify(test.payload, null, 2) : "N/A"}

Response:
${test.response_body ? JSON.stringify(test.response_body, null, 2) : "N/A"}

Stack Trace:
${test.stack_trace || "N/A"}
                                            `.trim();
                                            copyToClipboard(report, "Test details");
                                          }}
                                        >
                                          <Clipboard className="mr-1 h-3 w-3" />
                                          Copy Full Test Details
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
          ) : (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <FileWarning className="h-12 w-12 mx-auto mb-4 opacity-50" />
                <p>No test results available</p>
                <p className="text-sm">Run the test suite to see detailed results</p>
              </CardContent>
            </Card>
          )}
        </TabsContent>

        {/* ========== ROOT CAUSE TAB ========== */}
        <TabsContent value="rootcause" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <TreeDeciduous className="h-5 w-5" />
                Root Cause Analysis
              </CardTitle>
              <CardDescription>Grouped failures by root cause for faster debugging</CardDescription>
            </CardHeader>
            <CardContent>
              {Object.keys(rootCauseTree).length > 0 ? (
                <div className="space-y-4">
                  {Object.entries(rootCauseTree).map(([cause, tests]) => (
                    <div key={cause} className="border rounded-lg p-4">
                      <div className="flex items-center gap-2 mb-3">
                        <AlertTriangle className="h-5 w-5 text-red-500" />
                        <h3 className="font-semibold text-lg">{cause}</h3>
                        <Badge variant="destructive">{tests.length} failure(s)</Badge>
                      </div>
                      <ul className="list-disc list-inside space-y-1 text-sm text-muted-foreground">
                        {tests.map((test, idx) => (
                          <li key={idx}>{test}</li>
                        ))}
                      </ul>
                      <Separator className="my-3" />
                      <div className="bg-muted p-3 rounded">
                        <p className="text-xs font-medium mb-1">Suggested Resolution:</p>
                        <p className="text-sm">{getResolutionForCause(cause)}</p>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-8 text-muted-foreground">
                  <CheckCircle2 className="h-12 w-12 mx-auto mb-4 text-green-500 opacity-50" />
                  <p>No failures to analyze</p>
                  <p className="text-sm">All tests passed or no tests have been run</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* ========== CONSOLE TAB ========== */}
        <TabsContent value="console" className="space-y-6">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="flex items-center gap-2">
                    <Terminal className="h-5 w-5" />
                    Test Console Log
                  </CardTitle>
                  <CardDescription>Real-time execution log</CardDescription>
                </div>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => copyToClipboard(consoleLogs.join("\n"), "Console logs")}
                  >
                    <Copy className="h-4 w-4 mr-1" />
                    Copy Logs
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setConsoleLogs([])}
                  >
                    <Trash2 className="h-4 w-4 mr-1" />
                    Clear
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <ScrollArea className="h-[500px] w-full rounded-md border bg-black p-4">
                <pre className="text-xs text-green-400 font-mono whitespace-pre-wrap">
                  {consoleLogs.length > 0 ? consoleLogs.join("\n") : "Console output will appear here when tests run..."}
                </pre>
              </ScrollArea>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ========== HEALTH TAB ========== */}
        <TabsContent value="health" className="space-y-6">
          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Database className="h-5 w-5" />
                  Database Tables
                </CardTitle>
                <CardDescription>Table existence and record counts</CardDescription>
              </CardHeader>
              <CardContent>
                {healthData?.tables ? (
                  <ScrollArea className="h-[300px]">
                    <div className="space-y-2">
                      {Object.entries(healthData.tables).map(([table, info]) => (
                        <div key={table} className="flex items-center justify-between p-2 rounded hover:bg-muted">
                          <span className="font-mono text-sm">{table}</span>
                          <div className="flex items-center gap-2">
                            {info.exists ? (
                              <>
                                <Badge variant={info.hasData ? "default" : "secondary"}>
                                  {info.records} rows
                                </Badge>
                                <CheckCircle2 className="h-4 w-4 text-green-500" />
                              </>
                            ) : (
                              <>
                                <Badge variant="destructive">Missing</Badge>
                                <XCircle className="h-4 w-4 text-red-500" />
                              </>
                            )}
                          </div>
                        </div>
                      ))}
                    </div>
                  </ScrollArea>
                ) : (
                  <p className="text-center text-muted-foreground py-8">Run health check to see table status</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Network className="h-5 w-5" />
                  System Status
                </CardTitle>
                <CardDescription>API, auth, and mock detection</CardDescription>
              </CardHeader>
              <CardContent>
                {healthData ? (
                  <div className="space-y-4">
                    <div className="p-4 rounded-lg bg-muted">
                      <div className="flex items-center gap-2 mb-2">
                        <Globe className="h-4 w-4" />
                        <span className="font-medium">API Endpoint</span>
                      </div>
                      <p className="text-sm text-muted-foreground">
                        Status: {healthData.api.reachable ? "✅ Reachable" : "❌ Unreachable"}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        Latency: {healthData.api.latency_ms}ms
                      </p>
                    </div>

                    <div className="p-4 rounded-lg bg-muted">
                      <div className="flex items-center gap-2 mb-2">
                        <Lock className="h-4 w-4" />
                        <span className="font-medium">Authentication</span>
                      </div>
                      <p className="text-sm text-muted-foreground">
                        Token: {healthData.auth.tokenValid ? "✅ Valid" : "❌ Invalid/Missing"}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        User Loaded: {healthData.auth.userLoaded ? "✅ Yes" : "❌ No"}
                      </p>
                    </div>

                    <div className={`p-4 rounded-lg ${healthData.mockDetection.hasMocks ? "bg-red-100 dark:bg-red-950" : "bg-green-100 dark:bg-green-950"}`}>
                      <div className="flex items-center gap-2 mb-2">
                        <Code className="h-4 w-4" />
                        <span className="font-medium">Mock Detection</span>
                      </div>
                      {healthData.mockDetection.hasMocks ? (
                        <>
                          <p className="text-sm text-red-600 dark:text-red-400 font-medium">
                            ⚠️ MOCKS DETECTED - Tests may use fake data!
                          </p>
                          <ul className="text-sm text-red-600 dark:text-red-400 mt-2 list-disc list-inside">
                            {healthData.mockDetection.mockLocations.map((loc, idx) => (
                              <li key={idx}>{loc}</li>
                            ))}
                          </ul>
                        </>
                      ) : (
                        <p className="text-sm text-green-600 dark:text-green-400">
                          ✅ No mocks detected - Using real data and APIs
                        </p>
                      )}
                    </div>
                  </div>
                ) : (
                  <p className="text-center text-muted-foreground py-8">Run health check to see system status</p>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>
      </Tabs>
    </DashboardLayout>
  );
}

// ========== HELPER FUNCTIONS ==========

function getResolutionForCause(cause: string): string {
  const resolutions: Record<string, string> = {
    "Authentication Issues": "1. Check if auth_token exists in localStorage\n2. Verify token is not expired\n3. Try logging out and back in\n4. Check Auth.php::check() method",
    "Authorization Issues": "1. Verify user has correct account_type\n2. Check RLS policies in database\n3. Review controller permission checks",
    "Missing Resources": "1. Verify the record exists in database\n2. Check if user owns the resource\n3. Verify endpoint URL is correct",
    "Validation Failures": "1. Check request payload matches expected schema\n2. Review validation rules in controller\n3. Verify required fields are provided",
    "Server Errors": "1. Check PHP error logs\n2. Review controller method for exceptions\n3. Verify database connection\n4. Check for missing dependencies",
    "Network/CORS Issues": "1. Verify VITE_API_URL is correct\n2. Check CORS headers in api/index.php\n3. Verify API server is running",
    "Database Issues": "1. Verify database connection\n2. Check table exists\n3. Review column types and constraints\n4. Check for database migration issues",
    "Other Issues": "Review error details and stack trace for specific guidance",
  };
  return resolutions[cause] || resolutions["Other Issues"];
}
