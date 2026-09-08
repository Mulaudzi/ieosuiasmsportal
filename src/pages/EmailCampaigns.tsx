import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Mail, MessageSquare, Sparkles } from "lucide-react";
import { Link } from "react-router-dom";

export default function EmailCampaigns() {
  return (
    <DashboardLayout title="Email Campaigns" subtitle="A new email campaign experience is being designed">
      <div className="mx-auto flex min-h-[60vh] max-w-2xl items-center justify-center px-4">
        <div className="w-full rounded-2xl border bg-card p-8 text-center shadow-sm sm:p-12">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10 text-primary"><Mail className="h-8 w-8" /></div>
          <Badge className="mt-6 gap-1" variant="secondary"><Sparkles className="h-3.5 w-3.5" />Coming soon</Badge>
          <h2 className="mt-4 text-2xl font-bold">Email campaigns are coming soon</h2>
          <p className="mt-3 text-muted-foreground">Email campaign creation and sending are temporarily unavailable while we build a more reliable experience. Your existing contacts and SMS campaigns are unaffected.</p>
          <Button asChild className="mt-7"><Link to="/sms-campaigns"><MessageSquare className="mr-2 h-4 w-4" />Go to SMS campaigns</Link></Button>
        </div>
      </div>
    </DashboardLayout>
  );
}
