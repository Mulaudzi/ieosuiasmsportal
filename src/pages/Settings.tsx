import { useState } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  User,
  Building,
  Shield,
  Bell,
  Key,
  Check,
  Upload,
  Loader2,
} from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { saveSettings } from "@/lib/api";

export default function Settings() {
  const [savingSection, setSavingSection] = useState<string | null>(null);

  const handleSave = async (section: string) => {
    setSavingSection(section);
    try {
      const response = await saveSettings(section, {});
      if (response.success) {
        toast({
          title: "Settings saved",
          description: `Your ${section} settings have been updated.`,
        });
      }
    } catch (error) {
      toast({
        title: "Save failed",
        description: "Please try again.",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
    }
  };

  const handleAddSenderId = () => {
    toast({
      title: "Add Sender ID",
      description: "Sender ID registration form would open here.",
    });
  };

  const handleEditSenderId = (id: string) => {
    toast({
      title: "Edit Sender ID",
      description: `Editing sender ID: ${id}`,
    });
  };

  return (
    <DashboardLayout
      title="Settings"
      subtitle="Manage your account and organization settings"
    >
      <Tabs defaultValue="profile" className="w-full">
        <TabsList className="mb-6">
          <TabsTrigger value="profile" className="gap-2">
            <User className="h-4 w-4" />
            Profile
          </TabsTrigger>
          <TabsTrigger value="organization" className="gap-2">
            <Building className="h-4 w-4" />
            Organization
          </TabsTrigger>
          <TabsTrigger value="senderids" className="gap-2">
            <Key className="h-4 w-4" />
            Sender IDs
          </TabsTrigger>
          <TabsTrigger value="notifications" className="gap-2">
            <Bell className="h-4 w-4" />
            Notifications
          </TabsTrigger>
          <TabsTrigger value="compliance" className="gap-2">
            <Shield className="h-4 w-4" />
            Compliance
          </TabsTrigger>
        </TabsList>

        <TabsContent value="profile">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-lg font-semibold text-foreground">
              Profile Information
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Update your personal information
            </p>

            <div className="mt-6 space-y-6">
              <div className="flex items-center gap-6">
                <div className="flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 text-2xl font-bold text-primary">
                  JD
                </div>
                <div>
                  <Button variant="outline" size="sm" className="gap-2">
                    <Upload className="h-4 w-4" />
                    Upload Photo
                  </Button>
                  <p className="mt-2 text-xs text-muted-foreground">
                    JPG, PNG or GIF. Max 2MB.
                  </p>
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label htmlFor="firstName">First Name</Label>
                  <Input id="firstName" defaultValue="John" className="mt-1.5" />
                </div>
                <div>
                  <Label htmlFor="lastName">Last Name</Label>
                  <Input id="lastName" defaultValue="Doe" className="mt-1.5" />
                </div>
              </div>

              <div>
                <Label htmlFor="email">Email Address</Label>
                <Input
                  id="email"
                  type="email"
                  defaultValue="john@company.com"
                  className="mt-1.5"
                />
              </div>

              <div>
                <Label htmlFor="phone">Phone Number</Label>
                <Input
                  id="phone"
                  type="tel"
                  defaultValue="+1 (555) 123-4567"
                  className="mt-1.5"
                />
              </div>

              <Button onClick={() => handleSave("organization")} disabled={savingSection === "organization"}>
                {savingSection === "organization" ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                Save Changes
              </Button>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="organization">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-lg font-semibold text-foreground">
              Organization Details
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Manage your organization information
            </p>

            <div className="mt-6 space-y-6">
              <div>
                <Label htmlFor="orgName">Organization Name</Label>
                <Input
                  id="orgName"
                  defaultValue="Acme Corporation"
                  className="mt-1.5"
                />
              </div>

              <div>
                <Label htmlFor="industry">Industry</Label>
                <Input
                  id="industry"
                  defaultValue="E-commerce"
                  className="mt-1.5"
                />
              </div>

              <div>
                <Label htmlFor="address">Address</Label>
                <Textarea
                  id="address"
                  defaultValue="123 Business Street, Suite 100&#10;New York, NY 10001"
                  className="mt-1.5"
                  rows={3}
                />
              </div>

              <div>
                <Label htmlFor="website">Website</Label>
                <Input
                  id="website"
                  type="url"
                  defaultValue="https://acme.com"
                  className="mt-1.5"
                />
              </div>

              <Button onClick={() => handleSave("profile")} disabled={savingSection === "profile"}>
                {savingSection === "profile" ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                Save Changes
              </Button>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="senderids">
          <div className="rounded-xl border border-border bg-card p-6">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-lg font-semibold text-foreground">
                  Sender IDs
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                  Manage your registered sender IDs
                </p>
              </div>
              <Button onClick={handleAddSenderId}>Add Sender ID</Button>
              
            </div>

            <div className="mt-6 space-y-4">
              {["IEOSUIA", "COMPANY", "PROMO"].map((senderId) => (
                <div
                  key={senderId}
                  className="flex items-center justify-between rounded-lg border border-border p-4"
                >
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                      <Key className="h-5 w-5" />
                    </div>
                    <div>
                      <p className="font-medium text-foreground">{senderId}</p>
                      <p className="text-sm text-muted-foreground">
                        Registered sender ID
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2.5 py-0.5 text-xs font-medium text-success">
                      <Check className="h-3 w-3" />
                      Verified
                    </span>
                    <Button variant="outline" size="sm" onClick={() => handleEditSenderId(senderId)}>
                      Edit
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </TabsContent>

        <TabsContent value="notifications">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-lg font-semibold text-foreground">
              Notification Preferences
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Configure how you receive notifications
            </p>

            <div className="mt-6 space-y-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-foreground">
                    Campaign Completion
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Get notified when a campaign finishes sending
                  </p>
                </div>
                <Switch defaultChecked />
              </div>

              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-foreground">Low Credit Alert</p>
                  <p className="text-sm text-muted-foreground">
                    Get notified when your credits are running low
                  </p>
                </div>
                <Switch defaultChecked />
              </div>

              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-foreground">
                    Delivery Reports
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Receive daily delivery summary reports
                  </p>
                </div>
                <Switch defaultChecked />
              </div>

              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-foreground">
                    Marketing Updates
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Receive product updates and tips
                  </p>
                </div>
                <Switch />
              </div>

              <Button onClick={() => handleSave("notifications")} disabled={savingSection === "notifications"}>
                {savingSection === "notifications" ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                Save Preferences
              </Button>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="compliance">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-lg font-semibold text-foreground">
              Compliance Settings
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Configure opt-out and privacy settings
            </p>

            <div className="mt-6 space-y-6">
              <div>
                <Label htmlFor="optOutText">Default Opt-Out Text</Label>
                <Textarea
                  id="optOutText"
                  defaultValue="Reply STOP to unsubscribe"
                  className="mt-1.5"
                  rows={2}
                />
                <p className="mt-1.5 text-xs text-muted-foreground">
                  This text will be appended to SMS messages
                </p>
              </div>

              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-foreground">
                    Auto Opt-Out Detection
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Automatically process opt-out replies (STOP, UNSUBSCRIBE)
                  </p>
                </div>
                <Switch defaultChecked />
              </div>

              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-foreground">GDPR Compliance</p>
                  <p className="text-sm text-muted-foreground">
                    Enable GDPR-compliant data handling
                  </p>
                </div>
                <Switch defaultChecked />
              </div>

              <div className="rounded-lg bg-info/10 p-4">
                <p className="text-sm font-medium text-info">
                  Compliance Notice
                </p>
                <p className="mt-1 text-sm text-muted-foreground">
                  Your account is configured to comply with GDPR and local privacy
                  regulations. All opt-out requests are processed automatically
                  within 24 hours.
                </p>
              </div>

              <Button onClick={() => handleSave("compliance")} disabled={savingSection === "compliance"}>
                {savingSection === "compliance" ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                Save Settings
              </Button>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </DashboardLayout>
  );
}
