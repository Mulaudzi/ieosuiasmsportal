import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import Dashboard from "./pages/Dashboard";
import SmsCampaigns from "./pages/SmsCampaigns";
import CreateSmsCampaign from "./pages/CreateSmsCampaign";
import EmailCampaigns from "./pages/EmailCampaigns";
import Contacts from "./pages/Contacts";
import Templates from "./pages/Templates";
import Wallet from "./pages/Wallet";
import Reports from "./pages/Reports";
import Settings from "./pages/Settings";
import NotFound from "./pages/NotFound";

const queryClient = new QueryClient();

const App = () => (
  <QueryClientProvider client={queryClient}>
    <TooltipProvider>
      <Toaster />
      <Sonner />
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/sms-campaigns" element={<SmsCampaigns />} />
          <Route path="/sms-campaigns/new" element={<CreateSmsCampaign />} />
          <Route path="/email-campaigns" element={<EmailCampaigns />} />
          <Route path="/email-campaigns/new" element={<CreateSmsCampaign />} />
          <Route path="/contacts" element={<Contacts />} />
          <Route path="/contacts/import" element={<Contacts />} />
          <Route path="/templates" element={<Templates />} />
          <Route path="/wallet" element={<Wallet />} />
          <Route path="/reports" element={<Reports />} />
          <Route path="/settings" element={<Settings />} />
          <Route path="*" element={<NotFound />} />
        </Routes>
      </BrowserRouter>
    </TooltipProvider>
  </QueryClientProvider>
);

export default App;
