import { useState, useEffect, useRef } from "react";
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
  X,
} from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { saveSettings, updateProfile, uploadBranding, handleApiError } from "@/lib/api";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";

const profileSchema = z.object({
  firstName: z.string().min(1, "First name is required"),
  lastName: z.string().min(1, "Last name is required"),
  email: z.string().email("Invalid email address"),
  phone: z.string().optional(),
});

type ProfileFormData = z.infer<typeof profileSchema>;

const organizationSchema = z.object({
  orgName: z.string().min(1, "Organization name is required"),
  industry: z.string().optional(),
  address: z.string().optional(),
  website: z.string().url("Invalid URL").optional().or(z.literal("")),
});

type OrganizationFormData = z.infer<typeof organizationSchema>;

export default function Settings() {
  const [savingSection, setSavingSection] = useState<string | null>(null);
  const [avatarPreview, setAvatarPreview] = useState<string | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const avatarInputRef = useRef<HTMLInputElement>(null);
  const logoInputRef = useRef<HTMLInputElement>(null);

  const profileForm = useForm<ProfileFormData>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      firstName: "John",
      lastName: "Doe",
      email: "john@company.com",
      phone: "+1 (555) 123-4567",
    },
  });

  const orgForm = useForm<OrganizationFormData>({
    resolver: zodResolver(organizationSchema),
    defaultValues: {
      orgName: "Acme Corporation",
      industry: "E-commerce",
      address: "123 Business Street, Suite 100\nNew York, NY 10001",
      website: "https://acme.com",
    },
  });

  const handleProfileSubmit = async (data: ProfileFormData) => {
    setSavingSection("profile");
    try {
      const response = await updateProfile({
        name: `${data.firstName} ${data.lastName}`,
        email: data.email,
        phone: data.phone,
      });
      if (response.success) {
        toast({
          title: "Profile updated",
          description: "Your profile settings have been saved.",
        });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSavingSection(null);
    }
  };

  const handleOrganizationSubmit = async (data: OrganizationFormData) => {
    setSavingSection("organization");
    try {
      const response = await saveSettings("organization", data);
      if (response.success) {
        toast({
          title: "Organization updated",
          description: "Your organization settings have been saved.",
        });
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setSavingSection(null);
    }
  };

  const handleAvatarUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Validate file
    if (!file.type.startsWith("image/")) {
      toast({ title: "Invalid file", description: "Please select an image file.", variant: "destructive" });
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      toast({ title: "File too large", description: "Maximum file size is 2MB.", variant: "destructive" });
      return;
    }

    // Show preview
    const reader = new FileReader();
    reader.onloadend = () => setAvatarPreview(reader.result as string);
    reader.readAsDataURL(file);

    // Upload
    const formData = new FormData();
    formData.append("avatar", file);

    try {
      const response = await uploadBranding(formData);
      if (response.success) {
        toast({ title: "Avatar uploaded", description: "Your profile photo has been updated." });
      }
    } catch (error) {
      handleApiError(error);
      setAvatarPreview(null);
    }
  };

  const handleLogoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast({ title: "Invalid file", description: "Please select an image file.", variant: "destructive" });
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast({ title: "File too large", description: "Maximum file size is 5MB.", variant: "destructive" });
      return;
    }

    const reader = new FileReader();
    reader.onloadend = () => setLogoPreview(reader.result as string);
    reader.readAsDataURL(file);

    const formData = new FormData();
    formData.append("logo", file);

    try {
      const response = await uploadBranding(formData);
      if (response.success) {
        toast({ title: "Logo uploaded", description: "Your organization logo has been updated." });
      }
    } catch (error) {
      handleApiError(error);
      setLogoPreview(null);
    }
  };

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
      handleApiError(error);
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
          <form onSubmit={profileForm.handleSubmit(handleProfileSubmit)}>
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="text-lg font-semibold text-foreground">
                Profile Information
              </h3>
              <p className="mt-1 text-sm text-muted-foreground">
                Update your personal information
              </p>

              <div className="mt-6 space-y-6">
                <div className="flex items-center gap-6">
                  <div className="relative">
                    {avatarPreview ? (
                      <div className="relative">
                        <img
                          src={avatarPreview}
                          alt="Avatar preview"
                          className="h-20 w-20 rounded-full object-cover"
                        />
                        <button
                          type="button"
                          onClick={() => setAvatarPreview(null)}
                          className="absolute -right-1 -top-1 rounded-full bg-destructive p-1 text-destructive-foreground"
                        >
                          <X className="h-3 w-3" />
                        </button>
                      </div>
                    ) : (
                      <div className="flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 text-2xl font-bold text-primary">
                        JD
                      </div>
                    )}
                  </div>
                  <div>
                    <input
                      type="file"
                      ref={avatarInputRef}
                      onChange={handleAvatarUpload}
                      accept="image/*"
                      className="hidden"
                    />
                    <Button 
                      type="button" 
                      variant="outline" 
                      size="sm" 
                      className="gap-2"
                      onClick={() => avatarInputRef.current?.click()}
                    >
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
                    <Input 
                      id="firstName" 
                      {...profileForm.register("firstName")}
                      className="mt-1.5" 
                    />
                    {profileForm.formState.errors.firstName && (
                      <p className="mt-1 text-xs text-destructive">
                        {profileForm.formState.errors.firstName.message}
                      </p>
                    )}
                  </div>
                  <div>
                    <Label htmlFor="lastName">Last Name</Label>
                    <Input 
                      id="lastName" 
                      {...profileForm.register("lastName")}
                      className="mt-1.5" 
                    />
                    {profileForm.formState.errors.lastName && (
                      <p className="mt-1 text-xs text-destructive">
                        {profileForm.formState.errors.lastName.message}
                      </p>
                    )}
                  </div>
                </div>

                <div>
                  <Label htmlFor="email">Email Address</Label>
                  <Input
                    id="email"
                    type="email"
                    {...profileForm.register("email")}
                    className="mt-1.5"
                  />
                  {profileForm.formState.errors.email && (
                    <p className="mt-1 text-xs text-destructive">
                      {profileForm.formState.errors.email.message}
                    </p>
                  )}
                </div>

                <div>
                  <Label htmlFor="phone">Phone Number</Label>
                  <Input
                    id="phone"
                    type="tel"
                    {...profileForm.register("phone")}
                    className="mt-1.5"
                  />
                </div>

                <Button type="submit" disabled={savingSection === "profile"}>
                  {savingSection === "profile" && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
                  Save Changes
                </Button>
              </div>
            </div>
          </form>
        </TabsContent>

        <TabsContent value="organization">
          <form onSubmit={orgForm.handleSubmit(handleOrganizationSubmit)}>
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="text-lg font-semibold text-foreground">
                Organization Details
              </h3>
              <p className="mt-1 text-sm text-muted-foreground">
                Manage your organization information and branding
              </p>

              <div className="mt-6 space-y-6">
                {/* Logo Upload */}
                <div>
                  <Label>Organization Logo</Label>
                  <div className="mt-2 flex items-center gap-4">
                    {logoPreview ? (
                      <div className="relative">
                        <img
                          src={logoPreview}
                          alt="Logo preview"
                          className="h-16 w-32 rounded-lg border border-border object-contain p-2"
                        />
                        <button
                          type="button"
                          onClick={() => setLogoPreview(null)}
                          className="absolute -right-1 -top-1 rounded-full bg-destructive p-1 text-destructive-foreground"
                        >
                          <X className="h-3 w-3" />
                        </button>
                      </div>
                    ) : (
                      <div className="flex h-16 w-32 items-center justify-center rounded-lg border-2 border-dashed border-border bg-muted/50">
                        <span className="text-xs text-muted-foreground">No logo</span>
                      </div>
                    )}
                    <input
                      type="file"
                      ref={logoInputRef}
                      onChange={handleLogoUpload}
                      accept="image/*"
                      className="hidden"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => logoInputRef.current?.click()}
                    >
                      <Upload className="h-4 w-4 mr-2" />
                      Upload Logo
                    </Button>
                  </div>
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    Recommended: 200x50px PNG or SVG. Max 5MB.
                  </p>
                </div>

                <div>
                  <Label htmlFor="orgName">Organization Name</Label>
                  <Input
                    id="orgName"
                    {...orgForm.register("orgName")}
                    className="mt-1.5"
                  />
                  {orgForm.formState.errors.orgName && (
                    <p className="mt-1 text-xs text-destructive">
                      {orgForm.formState.errors.orgName.message}
                    </p>
                  )}
                </div>

                <div>
                  <Label htmlFor="industry">Industry</Label>
                  <Input
                    id="industry"
                    {...orgForm.register("industry")}
                    className="mt-1.5"
                  />
                </div>

                <div>
                  <Label htmlFor="address">Address</Label>
                  <Textarea
                    id="address"
                    {...orgForm.register("address")}
                    className="mt-1.5"
                    rows={3}
                  />
                </div>

                <div>
                  <Label htmlFor="website">Website</Label>
                  <Input
                    id="website"
                    type="url"
                    {...orgForm.register("website")}
                    className="mt-1.5"
                  />
                  {orgForm.formState.errors.website && (
                    <p className="mt-1 text-xs text-destructive">
                      {orgForm.formState.errors.website.message}
                    </p>
                  )}
                </div>

                <Button type="submit" disabled={savingSection === "organization"}>
                  {savingSection === "organization" && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
                  Save Changes
                </Button>
              </div>
            </div>
          </form>
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
                {savingSection === "notifications" && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
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
                {savingSection === "compliance" && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
                Save Settings
              </Button>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </DashboardLayout>
  );
}
