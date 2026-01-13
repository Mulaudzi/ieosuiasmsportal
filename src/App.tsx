import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { AuthProvider, ProtectedRoute } from "@/hooks/useAuth";
import { AdminRoute } from "@/components/auth/AdminRoute";
import { CookieConsent } from "@/components/CookieConsent";
import Landing from "./pages/Landing";
import Login from "./pages/Login";
import AdminLogin from "./pages/AdminLogin";
import Register from "./pages/Register";
import GoogleCallback from "./pages/GoogleCallback";
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
import SenderIds from "./pages/SenderIds";
import CreateEmailCampaign from "./pages/CreateEmailCampaign";
import AdminDashboard from "./pages/AdminDashboard";
import QaConsole from "./pages/QaConsole";
import Pricing from "./pages/Pricing";
import AdminManagement from "./pages/AdminManagement";

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
            <Route path="/pricing" element={<Pricing />} />
            <Route path="/login" element={<Login />} />
            <Route path="/register" element={<Register />} />
            <Route path="/verify-email" element={<VerifyEmail />} />
            <Route path="/verify-email-reminder" element={<ProtectedRoute><VerifyEmailReminder /></ProtectedRoute>} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/auth/google/callback" element={<GoogleCallback />} />
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
            <Route path="/profile" element={<ProtectedRoute><Profile /></ProtectedRoute>} />
            <Route path="/sms-campaigns" element={<ProtectedRoute requireVerified><SmsCampaigns /></ProtectedRoute>} />
            <Route path="/sms-campaigns/new" element={<ProtectedRoute requireVerified><CreateSmsCampaign /></ProtectedRoute>} />
            <Route path="/sms-campaigns/:id" element={<ProtectedRoute requireVerified><CampaignDetails /></ProtectedRoute>} />
            <Route path="/email-campaigns" element={<ProtectedRoute requireVerified><EmailCampaigns /></ProtectedRoute>} />
            <Route path="/email-campaigns/new" element={<ProtectedRoute requireVerified><CreateEmailCampaign /></ProtectedRoute>} />
            <Route path="/email-campaigns/:id" element={<ProtectedRoute requireVerified><CampaignDetails /></ProtectedRoute>} />
            <Route path="/contacts" element={<ProtectedRoute requireVerified><Contacts /></ProtectedRoute>} />
            <Route path="/contacts/import" element={<ProtectedRoute requireVerified><Contacts /></ProtectedRoute>} />
            <Route path="/templates" element={<ProtectedRoute requireVerified><Templates /></ProtectedRoute>} />
            <Route path="/wallet" element={<ProtectedRoute><Wallet /></ProtectedRoute>} />
            <Route path="/reports" element={<ProtectedRoute requireVerified><Reports /></ProtectedRoute>} />
            <Route path="/reports/compare" element={<ProtectedRoute requireVerified><CampaignComparison /></ProtectedRoute>} />
            <Route path="/settings" element={<ProtectedRoute><Settings /></ProtectedRoute>} />
            <Route path="/sender-ids" element={<ProtectedRoute><SenderIds /></ProtectedRoute>} />
            <Route path="/qa" element={<ProtectedRoute><QaConsole /></ProtectedRoute>} />
            
            {/* Admin Routes */}
            <Route path="/admin/login" element={<AdminLogin />} />
            <Route path="/admin" element={<AdminRoute><AdminDashboard /></AdminRoute>} />
            <Route path="/admin/users" element={<AdminRoute><AdminManagement /></AdminRoute>} />
            
            <Route path="*" element={<NotFound />} />
          </Routes>
        </AuthProvider>
      </BrowserRouter>
    </TooltipProvider>
  </QueryClientProvider>
);

export default App;
