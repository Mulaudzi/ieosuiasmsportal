import { lazy, Suspense, type ReactNode } from "react";
import { PublicSeo } from "./seo/PublicSeo";
import "./seo/public-seo.css";
import PublicGuide from "./pages/PublicGuide";
import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { AuthProvider, ProtectedRoute as AuthenticatedRoute } from "@/hooks/useAuth";
import { AdminRoute as AuthorizedAdminRoute } from "@/components/auth/AdminRoute";
import { AppLoadingScreen } from "@/components/ui/page-loading";
import { PortalPageLoading } from "@/components/layout/PortalPageLoading";

import { CookieConsent } from "@/components/CookieConsent";
import { PublicChrome } from "@/components/layout/PublicChrome";
import Landing from "./pages/Landing";
import VerifyEmail from "./pages/VerifyEmail";
import VerifyEmailReminder from "./pages/VerifyEmailReminder";
import ForgotPassword from "./pages/ForgotPassword";
const Dashboard = lazy(() => import("./pages/Dashboard"));
const SmsCampaigns = lazy(() => import("./pages/SmsCampaigns"));
const CreateSmsCampaign = lazy(() => import("./pages/CreateSmsCampaign"));
const CampaignDetails = lazy(() => import("./pages/CampaignDetails"));
const EmailCampaigns = lazy(() => import("./pages/EmailCampaigns"));
const Contacts = lazy(() => import("./pages/Contacts"));
const Templates = lazy(() => import("./pages/Templates"));
const Wallet = lazy(() => import("./pages/Wallet"));
const PaymentHistory = lazy(() => import("./pages/PaymentHistory"));
const PaymentSuccess = lazy(() => import("./pages/PaymentSuccess"));
const PaymentFailed = lazy(() => import("./pages/PaymentFailed"));
const Reports = lazy(() => import("./pages/Reports"));
const CampaignComparison = lazy(() => import("./pages/CampaignComparison"));
const Settings = lazy(() => import("./pages/Settings"));
const Profile = lazy(() => import("./pages/Profile"));
import NotFound from "./pages/NotFound";
import TermsOfService from "./pages/TermsOfService";
import PrivacyPolicy from "./pages/PrivacyPolicy";
import CookiePolicy from "./pages/CookiePolicy";
import PopiaCompliance from "./pages/PopiaCompliance";
import Support from "./pages/Support";
import Documentation from "./pages/Documentation";
import Careers from "./pages/Careers";
import Contact from "./pages/Contact";
import CentralAuthRedirect from "@/components/auth/CentralAuthRedirect";

const AdminDashboard = lazy(() => import("./pages/AdminDashboard"));
const AdminOperations = lazy(() => import("./pages/AdminOperations"));
import Pricing from "./pages/Pricing";
import BankingDetails from "./pages/BankingDetails";
const AdminManagement = lazy(() => import("./pages/AdminManagement"));
const AdminOperationalLogs = lazy(() => import("./pages/AdminOperationalLogs"));

const queryClient = new QueryClient();

// Keep chunk-loading placeholders behind the existing access guards.
function ProtectedRoute({ children, requireVerified = false }: { children: ReactNode; requireVerified?: boolean }) {
  return <AuthenticatedRoute requireVerified={requireVerified}><Suspense fallback={<PortalPageLoading />}>{children}</Suspense></AuthenticatedRoute>;
}

function AdminRoute({ children }: { children: ReactNode }) {
  return <AuthorizedAdminRoute><Suspense fallback={<PortalPageLoading admin />}>{children}</Suspense></AuthorizedAdminRoute>;
}

const App = () => (
  <QueryClientProvider client={queryClient}>
    <TooltipProvider>
      <Toaster />
      <Sonner />
      <BrowserRouter>
        <AuthProvider>
          <CookieConsent />
          <PublicChrome />
          <PublicSeo />
          <Suspense fallback={<AppLoadingScreen />}>
          <Routes>
            {["features","use-cases","contact-management","scheduled-sms","delivery-reports","faq"].map(path => <Route key={path} path={"/"+path} element={<PublicGuide/>}/>)}
            <Route path="/" element={<Landing />} />
            <Route path="/landing" element={<Navigate to="/" replace />} />
            <Route path="/pricing" element={<Pricing />} />
            <Route path="/banking-details" element={<BankingDetails />} />
            <Route path="/login" element={<CentralAuthRedirect />} />
            <Route path="/register" element={<CentralAuthRedirect mode="signup" />} />
            <Route path="/auth/callback" element={<CentralAuthRedirect callback />} />
            <Route path="/guymhan/auth/callback" element={<CentralAuthRedirect mode="admin" callback />} />
            <Route path="/verify-email" element={<VerifyEmail />} />
            <Route path="/verify-email-reminder" element={<ProtectedRoute><VerifyEmailReminder /></ProtectedRoute>} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/terms-of-service" element={<main className="public-legacy"><TermsOfService /></main>} />
            <Route path="/privacy-policy" element={<main className="public-legacy"><PrivacyPolicy /></main>} />
            <Route path="/cookie-policy" element={<main className="public-legacy"><CookiePolicy /></main>} />
            <Route path="/popia-compliance" element={<main className="public-legacy"><PopiaCompliance /></main>} />
            <Route path="/support" element={<main className="public-legacy"><Support /></main>} />
            <Route path="/documentation" element={<Documentation />} />
            <Route path="/careers" element={<main className="public-legacy"><Careers /></main>} />
            <Route path="/contact" element={<main className="public-legacy"><Contact /></main>} />
            
            {/* User Dashboard Routes */}
            <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
            {/* Route profile to settings to unify UX */}
            <Route path="/profile" element={<ProtectedRoute><Settings /></ProtectedRoute>} />
            <Route path="/sms-campaigns" element={<ProtectedRoute requireVerified><SmsCampaigns /></ProtectedRoute>} />
            <Route path="/sms-campaigns/new" element={<ProtectedRoute requireVerified><CreateSmsCampaign /></ProtectedRoute>} />
            <Route path="/sms-campaigns/:id" element={<ProtectedRoute requireVerified><CampaignDetails /></ProtectedRoute>} />
            <Route path="/email-campaigns" element={<ProtectedRoute requireVerified><EmailCampaigns /></ProtectedRoute>} />
            <Route path="/email-campaigns/new" element={<Navigate to="/email-campaigns" replace />} />
            <Route path="/email-campaigns/:id" element={<Navigate to="/email-campaigns" replace />} />
            <Route path="/contacts" element={<ProtectedRoute requireVerified><Contacts /></ProtectedRoute>} />
            <Route path="/contacts/import" element={<ProtectedRoute requireVerified><Contacts /></ProtectedRoute>} />
            <Route path="/templates" element={<ProtectedRoute requireVerified><Templates /></ProtectedRoute>} />
            <Route path="/wallet" element={<ProtectedRoute><Wallet /></ProtectedRoute>} />
            <Route path="/wallet/payments" element={<ProtectedRoute><PaymentHistory /></ProtectedRoute>} />
            <Route path="/payment/success" element={<ProtectedRoute><PaymentSuccess /></ProtectedRoute>} />
            <Route path="/payment/failed" element={<ProtectedRoute><PaymentFailed /></ProtectedRoute>} />
            <Route path="/reports" element={<ProtectedRoute requireVerified><Reports /></ProtectedRoute>} />
            <Route path="/reports/compare" element={<ProtectedRoute requireVerified><CampaignComparison /></ProtectedRoute>} />
            <Route path="/settings" element={<ProtectedRoute><Settings /></ProtectedRoute>} />
            
            <Route path="/guymhan/login" element={<CentralAuthRedirect mode="admin" />} />
            <Route path="/guymhan" element={<AdminRoute><AdminOperations /></AdminRoute>} />
            <Route path="/guymhan/tools" element={<AdminRoute><AdminDashboard /></AdminRoute>} />
            <Route path="/guymhan/users" element={<AdminRoute><AdminManagement /></AdminRoute>} />
            <Route path="/guymhan/logs" element={<AdminRoute><AdminOperationalLogs /></AdminRoute>} />
            
            <Route path="*" element={<NotFound />} />
          </Routes>
          </Suspense>
        </AuthProvider>
      </BrowserRouter>
    </TooltipProvider>
  </QueryClientProvider>
);

export default App;
