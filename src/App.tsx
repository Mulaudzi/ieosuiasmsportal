import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { AuthProvider, ProtectedRoute } from "@/hooks/useAuth";
import { AdminRoute } from "@/components/auth/AdminRoute";

import { CookieConsent } from "@/components/CookieConsent";
import Landing from "./pages/Landing";
import VerifyEmail from "./pages/VerifyEmail";
import VerifyEmailReminder from "./pages/VerifyEmailReminder";
import ForgotPassword from "./pages/ForgotPassword";
import Dashboard from "./pages/Dashboard";
import SmsCampaigns from "./pages/SmsCampaigns";
import CreateSmsCampaign from "./pages/CreateSmsCampaign";
import CampaignDetails from "./pages/CampaignDetails";
import EmailCampaigns from "./pages/EmailCampaigns";
import Contacts from "./pages/Contacts";
import Templates from "./pages/Templates";
import Wallet from "./pages/Wallet";
import PaymentHistory from "./pages/PaymentHistory";
import PaymentSuccess from "./pages/PaymentSuccess";
import PaymentFailed from "./pages/PaymentFailed";
import Reports from "./pages/Reports";
import CampaignComparison from "./pages/CampaignComparison";
import Settings from "./pages/Settings";
import Profile from "./pages/Profile";
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

import AdminDashboard from "./pages/AdminDashboard";
import AdminOperations from "./pages/AdminOperations";
import Pricing from "./pages/Pricing";
import AdminManagement from "./pages/AdminManagement";
import AdminOperationalLogs from "./pages/AdminOperationalLogs";

const queryClient = new QueryClient();

const App = () => (
  <QueryClientProvider client={queryClient}>
    <TooltipProvider>
      <Toaster />
      <Sonner />
      <BrowserRouter>
        <AuthProvider>
          <CookieConsent />
          <Routes>
            <Route path="/" element={<Landing />} />
            <Route path="/landing" element={<Navigate to="/" replace />} />
            <Route path="/pricing" element={<Pricing />} />
            <Route path="/login" element={<CentralAuthRedirect />} />
            <Route path="/register" element={<CentralAuthRedirect mode="signup" />} />
            <Route path="/auth/callback" element={<CentralAuthRedirect callback />} />
            <Route path="/guymhan/auth/callback" element={<CentralAuthRedirect mode="admin" callback />} />
            <Route path="/verify-email" element={<VerifyEmail />} />
            <Route path="/verify-email-reminder" element={<ProtectedRoute><VerifyEmailReminder /></ProtectedRoute>} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/terms-of-service" element={<TermsOfService />} />
            <Route path="/privacy-policy" element={<PrivacyPolicy />} />
            <Route path="/cookie-policy" element={<CookiePolicy />} />
            <Route path="/popia-compliance" element={<PopiaCompliance />} />
            <Route path="/support" element={<Support />} />
            <Route path="/documentation" element={<Documentation />} />
            <Route path="/careers" element={<Careers />} />
            <Route path="/contact" element={<Contact />} />
            
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
        </AuthProvider>
      </BrowserRouter>
    </TooltipProvider>
  </QueryClientProvider>
);

export default App;
