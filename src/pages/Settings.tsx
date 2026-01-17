import { useState, useRef, useEffect } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogTrigger, DialogFooter } from "@/components/ui/dialog";
import {
  Building,
  Shield,
  Bell,
  Upload,
  Loader2,
  X,
  User,
  Lock,
  CheckCircle,
  Zap,
} from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { useAuth } from "@/hooks/useAuth";
import { api, getProfile, updateProfile as apiUpdateProfile, uploadBranding, handleApiError } from "@/lib/api";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";

// Form schemas
const profileSchema = z.object({
  name: z.string().min(2, "Name must be at least 2 characters"),
  phone: z.string().optional(),
});

const organizationSchema = z.object({
  company_name: z.string().min(1, "Company name is required"),
  industry: z.string().optional(),
  address: z.string().optional(),
  website: z.string().url("Invalid URL").optional().or(z.literal("")),
});

const securitySchema = z.object({
  current_password: z.string().min(1, "Current password is required"),
  password: z.string().min(8, "Password must be at least 8 characters"),
  password_confirmation: z.string(),
}).refine((data) => data.password === data.password_confirmation, {
  message: "Passwords don't match",
  path: ["password_confirmation"],
});

type ProfileFormData = z.infer<typeof profileSchema>;
type OrganizationFormData = z.infer<typeof organizationSchema>;
type SecurityFormData = z.infer<typeof securitySchema>;

export default function Settings() {
  const { user, token, updateUser, isEmailVerified } = useAuth();
  const [savingSection, setSavingSection] = useState<string | null>(null);
  const [avatarPreview, setAvatarPreview] = useState<string | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [isEditingProfile, setIsEditingProfile] = useState(false);
  const [profileData, setProfileData] = useState<any>(null);
  const [organizationData, setOrganizationData] = useState<any>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [gdprDialogOpen, setGdprDialogOpen] = useState(false);
  // Tutorial progress state (persisted in localStorage)
  const tutorialItems: { key: string; label: string; description: string; href?: string }[] = [
    { 
      key: "verify-profile", 
      label: "Confirm your profile details", 
      description: "Visit your Profile tab and verify your name, email, and phone number. Upload a profile picture to personalize your account.",
      href: "/settings" 
    },
    { 
      key: "org-branding", 
      label: "Set organization details and branding", 
      description: "Fill in your company name, industry, address, and website in the Organization tab. This info appears on receipts and integrations.",
      href: "/settings" 
    },
    { 
      key: "upload-logo", 
      label: "Upload organization logo", 
      description: "Upload your company logo (PNG or SVG, max 5MB) so it displays in your branded communications and dashboard.",
      href: "/settings" 
    },
    { 
      key: "add-contacts", 
      label: "Create a contact group and add contacts", 
      description: "Go to Contacts and create a new group. Add recipients by uploading a CSV or entering them manually. Groups make bulk messaging easier.",
      href: "/contacts" 
    },
    { 
      key: "create-campaign", 
      label: "Create your first SMS campaign", 
      description: "Navigate to SMS Campaigns and click 'New Campaign'. Select a contact group, compose your message, and choose your sending settings.",
      href: "/campaigns/new" 
    },
    { 
      key: "send-test", 
      label: "Send a test message", 
      description: "Before going live, send a test SMS to yourself or a team member to check formatting, personalization, and tone.",
      href: "/campaigns" 
    },
    { 
      key: "schedule-send", 
      label: "Schedule or send immediately", 
      description: "Choose to send now or schedule for a specific date/time. Review the final preview and click 'Send' to go live.",
      href: "/campaigns" 
    },
    { 
      key: "monitor-reports", 
      label: "Monitor delivery and results", 
      description: "View real-time delivery status and metrics in Reports. Track opens, clicks, and engagement to optimize future campaigns.",
      href: "/reports" 
    },
    { 
      key: "gdpr-compliance", 
      label: "Review GDPR compliance settings", 
      description: "Check the Compliance tab to understand opt-out handling, data retention, and consent management to ensure full compliance.",
      href: "/settings" 
    },
  ];
  const [tutorialStatus, setTutorialStatus] = useState<Record<string, boolean>>(() => {
    try {
      const raw = localStorage.getItem("tutorialProgress_v1");
      if (raw) return JSON.parse(raw);
    } catch {}
    const init: Record<string, boolean> = {};
    for (const item of tutorialItems) init[item.key] = false;
    return init;
  });
  const completedCount = Object.values(tutorialStatus).filter(Boolean).length;
  const totalCount = tutorialItems.length;
  const progressPct = Math.round((completedCount / totalCount) * 100);

  const toggleTutorialStep = (key: string) => {
    setTutorialStatus((prev) => ({ ...prev, [key]: !prev[key] }));
  };
  const markAllTutorial = () => {
    const all: Record<string, boolean> = {};
    tutorialItems.forEach((i) => (all[i.key] = true));
    setTutorialStatus(all);
  };
  const resetTutorial = () => {
    const none: Record<string, boolean> = {};
    tutorialItems.forEach((i) => (none[i.key] = false));
    setTutorialStatus(none);
  };
  useEffect(() => {
    try {
      localStorage.setItem("tutorialProgress_v1", JSON.stringify(tutorialStatus));
    } catch {}
  }, [tutorialStatus]);
  
  const avatarInputRef = useRef<HTMLInputElement>(null);
  const logoInputRef = useRef<HTMLInputElement>(null);

  // Fetch profile and organization data on mount
  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const resp = await getProfile();
        if (resp.success) {
          const top: any = resp as any;
          const data: any = resp.data || {};
          const u = top.user ?? data.user ?? null;
          const acc = top.account ?? data.account ?? null;
          if (u) {
            setProfileData(u);
            if (u.avatar_url) setAvatarPreview(u.avatar_url);
          } else if (user) {
            setProfileData(user);
            if (user.avatar_url) setAvatarPreview(user.avatar_url);
          }
          if (acc) {
            setOrganizationData(acc);
            if (acc.logo_url) setLogoPreview(acc.logo_url);
          }
        } else if (user) {
          setProfileData(user);
          if (user.avatar_url) setAvatarPreview(user.avatar_url);
        }
      } catch (error) {
        console.error("Failed to fetch profile data:", error);
      } finally {
        setLoading(false);
      }
    };
    if (user) fetchData();
  }, [user]);

  // Profile form
  const profileForm = useForm<ProfileFormData>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      name: profileData?.name || "",
      phone: profileData?.phone || "",
    },
  });

  // Organization form
  const orgForm = useForm<OrganizationFormData>({
    resolver: zodResolver(organizationSchema),
    defaultValues: {
      company_name: organizationData?.company_name || "",
      industry: organizationData?.industry || "",
      address: organizationData?.address || "",
      website: organizationData?.website || "",
    },
  });

  // Security form
  const securityForm = useForm<SecurityFormData>({
    resolver: zodResolver(securitySchema),
  });

  // Update profile form when data changes
  useEffect(() => {
    if (profileData) {
      profileForm.reset({
        name: profileData.name,
        phone: profileData.phone || "",
      });
    }
  }, [profileData, profileForm]);

  // Update organization form when data changes
  useEffect(() => {
    if (organizationData) {
      orgForm.reset({
        company_name: organizationData.company_name || "",
        industry: organizationData.industry || "",
        address: organizationData.address || "",
        website: organizationData.website || "",
      });
    }
  }, [organizationData, orgForm]);

  // Handle avatar upload
  const handleAvatarUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast({ 
        title: "Invalid file", 
        description: "Please select an image file.", 
        variant: "destructive" 
      });
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      toast({ 
        title: "File too large", 
        description: "Maximum file size is 2MB.", 
        variant: "destructive" 
      });
      return;
    }

    setSavingSection("avatar");

    try {
      const reader = new FileReader();
      reader.onload = async (event) => {
        const base64String = event.target?.result as string;
        const resp = await api.post<any>("/auth/avatar", { avatar: base64String });
        if (resp.success) {
          const avatarUrl = (resp.data as any)?.avatar_url || (resp.avatar_url as string) || base64String;
          setAvatarPreview(avatarUrl);
          await updateUser({ avatar_url: avatarUrl });
          toast({ title: "Success", description: "Avatar updated successfully" });
        } else {
          toast({ title: "Error", description: resp.message || "Failed to upload avatar", variant: "destructive" });
        }
      };
      reader.readAsDataURL(file);
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to upload avatar",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
    }
  };

  // Handle profile submit
  const handleProfileSubmit = async (data: ProfileFormData) => {
    setSavingSection("profile");
    try {
      const result = await apiUpdateProfile({ name: data.name, phone: data.phone });
      if (result.success) {
        const updatedUser = (result.data as any)?.user || (result.user as any);
        if (updatedUser) setProfileData(updatedUser);
        await updateUser({ name: data.name, phone: data.phone });
        setIsEditingProfile(false);
        toast({ title: "Success", description: "Profile updated successfully" });
      } else {
        toast({ title: "Error", description: result.message || "Failed to update profile", variant: "destructive" });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to update profile",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
    }
  };

  // Handle organization submit
  const handleOrganizationSubmit = async (data: OrganizationFormData) => {
    setSavingSection("organization");
    try {
      const result = await apiUpdateProfile(data);
      if (result.success) {
        const updatedAccount = (result.data as any)?.account || (result.account as any);
        if (updatedAccount) setOrganizationData(updatedAccount);
        toast({ title: "Success", description: "Organization updated successfully" });
      } else {
        toast({ title: "Error", description: result.message || "Failed to update organization", variant: "destructive" });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to update organization",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
    }
  };

  // Handle logo upload
  const handleLogoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast({ 
        title: "Invalid file", 
        description: "Please select an image file.", 
        variant: "destructive" 
      });
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      toast({ 
        title: "File too large", 
        description: "Maximum file size is 5MB.", 
        variant: "destructive" 
      });
      return;
    }

    setSavingSection("logo");

    try {
      const formData = new FormData();
      formData.append("logo", file);

      const resp = await uploadBranding(formData);
      if (resp.success) {
        const reader = new FileReader();
        reader.onload = (event) => {
          setLogoPreview(event.target?.result as string);
        };
        reader.readAsDataURL(file);

        toast({ title: "Success", description: "Logo uploaded successfully" });
      } else {
        toast({ title: "Error", description: resp.message || "Failed to upload logo", variant: "destructive" });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to upload logo",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
    }
  };

  // Handle security/password change
  const handleSecuritySubmit = async (data: SecurityFormData) => {
    setSavingSection("security");
    try {
      const result = await updateUser({
        current_password: data.current_password,
        password: data.password,
        password_confirmation: data.password_confirmation,
      });
      if (result.success) {
        toast({ title: "Success", description: "Password updated successfully" });
        securityForm.reset();
      } else {
        toast({ title: "Error", description: result.error || "Failed to update password", variant: "destructive" });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to update password",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
    }
  };

  if (loading) {
    return (
      <DashboardLayout title="Settings" subtitle="Loading...">
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      </DashboardLayout>
    );
  }

  return (
    <DashboardLayout
      title="Settings"
      subtitle="Manage your profile, organization, and preferences"
    >
      <Tabs defaultValue="profile" className="w-full">
        <TabsList className="mb-6 grid w-full grid-cols-3 lg:grid-cols-6">
          <TabsTrigger value="profile" className="gap-2">
            <User className="h-4 w-4" />
            <span className="hidden sm:inline">Profile</span>
          </TabsTrigger>
          <TabsTrigger value="organization" className="gap-2">
            <Building className="h-4 w-4" />
            <span className="hidden sm:inline">Organization</span>
          </TabsTrigger>
          <TabsTrigger value="security" className="gap-2">
            <Lock className="h-4 w-4" />
            <span className="hidden sm:inline">Security</span>
          </TabsTrigger>
          <TabsTrigger value="notifications" className="gap-2">
            <Bell className="h-4 w-4" />
            <span className="hidden sm:inline">Notifications</span>
          </TabsTrigger>
          <TabsTrigger value="get-started" className="gap-2">
            <Zap className="h-4 w-4" />
            <span className="hidden sm:inline">Get Started</span>
          </TabsTrigger>
          <TabsTrigger value="compliance" className="gap-2">
            <Shield className="h-4 w-4" />
            <span className="hidden sm:inline">Compliance</span>
          </TabsTrigger>
        </TabsList>

        {/* Profile Tab */}
        <TabsContent value="profile">
          <div className="space-y-6">
            {/* Avatar Section */}
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="text-lg font-semibold text-foreground">
                Profile Picture
              </h3>
              <p className="mt-1 text-sm text-muted-foreground">
                Upload or change your profile avatar
              </p>

              <div className="mt-6 flex items-center gap-6">
                <div className="relative">
                  {avatarPreview ? (
                    <img
                      src={avatarPreview}
                      alt="Avatar"
                      className="h-24 w-24 rounded-full object-cover border-4 border-primary/20"
                    />
                  ) : (
                    <div className="flex h-24 w-24 items-center justify-center rounded-full bg-primary/10">
                      <User className="h-12 w-12 text-primary" />
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
                    onClick={() => avatarInputRef.current?.click()}
                    disabled={savingSection === "avatar"}
                  >
                    {savingSection === "avatar" && (
                      <Loader2 className="h-4 w-4 animate-spin mr-2" />
                    )}
                    <Upload className="h-4 w-4 mr-2" />
                    Change Avatar
                  </Button>
                  <p className="mt-2 text-xs text-muted-foreground">
                    JPG, PNG or GIF. Max 2MB.
                  </p>
                </div>
              </div>
            </div>

            {/* Profile Information Section */}
            {!isEditingProfile ? (
              <div className="rounded-xl border border-border bg-card p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-lg font-semibold text-foreground">
                      Profile Information
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Your personal details
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    onClick={() => setIsEditingProfile(true)}
                  >
                    Edit Profile
                  </Button>
                </div>

                <div className="mt-6 space-y-4">
                  <div>
                    <p className="text-sm font-medium text-muted-foreground">
                      Full Name
                    </p>
                    <p className="mt-1 text-foreground">{profileData.name}</p>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-muted-foreground">
                      Email
                    </p>
                    <div className="mt-1 flex items-center gap-2">
                      <p className="text-foreground">{profileData.email}</p>
                      {user?.email_verified_at && (
                        <div className="flex items-center gap-1 text-xs text-green-600 bg-green-50 px-2 py-1 rounded">
                          <CheckCircle className="h-3 w-3" />
                          Verified
                        </div>
                      )}
                    </div>
                  </div>
                  {profileData.phone && (
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">
                        Phone
                      </p>
                      <p className="mt-1 text-foreground">{profileData.phone}</p>
                    </div>
                  )}
                </div>
              </div>
            ) : (
              <form onSubmit={profileForm.handleSubmit(handleProfileSubmit)}>
                <div className="rounded-xl border border-border bg-card p-6 space-y-6">
                  <h3 className="text-lg font-semibold text-foreground">
                    Edit Profile
                  </h3>

                  <div>
                    <Label htmlFor="name">Full Name</Label>
                    <Input
                      id="name"
                      {...profileForm.register("name")}
                      className="mt-1.5"
                    />
                    {profileForm.formState.errors.name && (
                      <p className="mt-1 text-xs text-destructive">
                        {profileForm.formState.errors.name.message}
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

                  <div className="flex gap-3">
                    <Button
                      type="submit"
                      disabled={savingSection === "profile"}
                    >
                      {savingSection === "profile" && (
                        <Loader2 className="h-4 w-4 animate-spin mr-2" />
                      )}
                      Save Changes
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => setIsEditingProfile(false)}
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              </form>
            )}
          </div>
        </TabsContent>

        {/* Organization Tab */}
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
                        <span className="text-xs text-muted-foreground">
                          No logo
                        </span>
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
                      disabled={savingSection === "logo"}
                    >
                      {savingSection === "logo" && (
                        <Loader2 className="h-4 w-4 animate-spin mr-2" />
                      )}
                      <Upload className="h-4 w-4 mr-2" />
                      Upload Logo
                    </Button>
                  </div>
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    Recommended: 200x50px PNG or SVG. Max 5MB.
                  </p>
                </div>

                <div>
                  <Label htmlFor="company_name">Company Name</Label>
                  <Input
                    id="company_name"
                    {...orgForm.register("company_name")}
                    className="mt-1.5"
                  />
                  {orgForm.formState.errors.company_name && (
                    <p className="mt-1 text-xs text-destructive">
                      {orgForm.formState.errors.company_name.message}
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

                <Button
                  type="submit"
                  disabled={savingSection === "organization"}
                >
                  {savingSection === "organization" && (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  )}
                  Save Changes
                </Button>
              </div>
            </div>
          </form>
        </TabsContent>

        {/* Security Tab */}
        <TabsContent value="security">
          <form onSubmit={securityForm.handleSubmit(handleSecuritySubmit)}>
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="text-lg font-semibold text-foreground">
                Security Settings
              </h3>
              <p className="mt-1 text-sm text-muted-foreground">
                Change your password to secure your account
              </p>

              <div className="mt-6 space-y-6">
                <div>
                  <Label htmlFor="current_password">Current Password</Label>
                  <Input
                    id="current_password"
                    type="password"
                    {...securityForm.register("current_password")}
                    className="mt-1.5"
                  />
                  {securityForm.formState.errors.current_password && (
                    <p className="mt-1 text-xs text-destructive">
                      {securityForm.formState.errors.current_password.message}
                    </p>
                  )}
                </div>

                <div>
                  <Label htmlFor="password">New Password</Label>
                  <Input
                    id="password"
                    type="password"
                    {...securityForm.register("password")}
                    className="mt-1.5"
                  />
                  {securityForm.formState.errors.password && (
                    <p className="mt-1 text-xs text-destructive">
                      {securityForm.formState.errors.password.message}
                    </p>
                  )}
                </div>

                <div>
                  <Label htmlFor="password_confirmation">
                    Confirm Password
                  </Label>
                  <Input
                    id="password_confirmation"
                    type="password"
                    {...securityForm.register("password_confirmation")}
                    className="mt-1.5"
                  />
                  {securityForm.formState.errors.password_confirmation && (
                    <p className="mt-1 text-xs text-destructive">
                      {securityForm.formState.errors.password_confirmation.message}
                    </p>
                  )}
                </div>

                <Button
                  type="submit"
                  disabled={savingSection === "security"}
                >
                  {savingSection === "security" && (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  )}
                  Update Password
                </Button>
              </div>
            </div>
          </form>
        </TabsContent>

        {/* Notifications Tab */}
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
                  <p className="font-medium text-foreground">
                    Low Credit Alert
                  </p>
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
            </div>
          </div>
        </TabsContent>

        {/* Get Started Tab */}
        <TabsContent value="get-started">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-lg font-semibold text-foreground">
              Get Started
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Learn the basics and get up to speed
            </p>

            <div className="mt-6 space-y-4">
              <div className="flex items-start gap-4 p-4 rounded-lg bg-muted/50">
                <Zap className="h-5 w-5 text-yellow-500 mt-1 flex-shrink-0" />
                <div>
                  <h4 className="font-medium text-foreground">
                    Quick Start Guide
                  </h4>
                  <p className="text-sm text-muted-foreground mt-1">
                    Learn how to create your first campaign in 5 minutes
                  </p>
                  <Dialog>
                    <DialogTrigger asChild>
                      <Button variant="outline" size="sm" className="mt-2">
                        Open Guide
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-xl">
                      <DialogHeader>
                        <DialogTitle>Quick Start Tutorial</DialogTitle>
                        <DialogDescription>
                          Track your progress as you set up and send your first campaign.
                        </DialogDescription>
                      </DialogHeader>
                      <div className="space-y-4">
                        {/* Progress */}
                        <div>
                          <div className="flex items-center justify-between text-sm">
                            <p className="font-medium text-foreground">Your Progress</p>
                            <p className="text-muted-foreground">{completedCount}/{totalCount} ({progressPct}%)</p>
                          </div>
                          <div className="mt-2 h-2 w-full rounded bg-muted">
                            <div
                              className="h-2 rounded bg-primary transition-all duration-300"
                              style={{ width: `${progressPct}%` }}
                            />
                          </div>
                        </div>

                        {/* Animated Demo */}
                        <div className="rounded-lg bg-gradient-to-br from-primary/5 to-primary/10 border border-primary/20 p-4">
                          <p className="text-xs font-semibold text-primary uppercase tracking-wide">The Flow</p>
                          <div className="mt-3 space-y-2 text-xs text-foreground">
                            <div className="flex items-center gap-2">
                              <div className="w-6 h-6 rounded-full bg-primary flex items-center justify-center text-white text-xs font-bold">1</div>
                              <span>Profile Setup</span>
                            </div>
                            <div className="ml-3 h-3 border-l-2 border-primary/40"></div>
                            <div className="flex items-center gap-2">
                              <div className="w-6 h-6 rounded-full bg-primary flex items-center justify-center text-white text-xs font-bold">2</div>
                              <span>Organize Contacts</span>
                            </div>
                            <div className="ml-3 h-3 border-l-2 border-primary/40"></div>
                            <div className="flex items-center gap-2">
                              <div className="w-6 h-6 rounded-full bg-primary flex items-center justify-center text-white text-xs font-bold">3</div>
                              <span>Create & Test Campaign</span>
                            </div>
                            <div className="ml-3 h-3 border-l-2 border-primary/40"></div>
                            <div className="flex items-center gap-2">
                              <div className="w-6 h-6 rounded-full bg-primary flex items-center justify-center text-white text-xs font-bold">4</div>
                              <span>Send & Monitor</span>
                            </div>
                          </div>
                        </div>

                        {/* Checklist with Descriptions */}
                        <div className="space-y-3 max-h-72 overflow-y-auto">
                          {tutorialItems.map((item, idx) => (
                            <div key={item.key} className="rounded-lg border border-border p-3 hover:bg-muted/50 transition-colors">
                              <div className="flex items-start gap-3">
                                <input
                                  type="checkbox"
                                  checked={!!tutorialStatus[item.key]}
                                  onChange={() => toggleTutorialStep(item.key)}
                                  className="h-4 w-4 accent-primary mt-0.5 flex-shrink-0"
                                />
                                <div className="flex-1 min-w-0">
                                  <div className="flex items-center gap-2">
                                    <span className={`text-sm font-medium ${tutorialStatus[item.key] ? "line-through text-muted-foreground" : "text-foreground"}`}>
                                      Step {idx + 1}: {item.label}
                                    </span>
                                  </div>
                                  <p className="text-xs text-muted-foreground mt-1">{item.description}</p>
                                </div>
                                {item.href && (
                                  <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => (window.location.href = item.href!)}
                                    className="flex-shrink-0"
                                  >
                                    Go →
                                  </Button>
                                )}
                              </div>
                            </div>
                          ))}
                        </div>

                        {/* Sample template */}
                        <div className="rounded-lg border border-border/50 bg-muted/30 p-3">
                          <p className="text-xs font-semibold text-foreground uppercase">Quick Template</p>
                          <p className="text-xs text-muted-foreground mt-1">Copy and customize this starting point:</p>
                          <div className="mt-2 rounded bg-card border border-border p-2 text-xs font-mono text-foreground leading-relaxed">
                            Hello {'{first_name}'}, just a reminder: your appointment is on {'{date}'} at {'{time}'}. Reply STOP to unsubscribe.
                          </div>
                        </div>
                      </div>
                      <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={resetTutorial}>Reset</Button>
                        <Button type="button" variant="secondary" onClick={markAllTutorial}>Mark all done</Button>
                        <Button variant="default">Close</Button>
                      </DialogFooter>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>

              <div className="flex items-start gap-4 p-4 rounded-lg bg-muted/50">
                <Shield className="h-5 w-5 text-green-500 mt-1 flex-shrink-0" />
                <div>
                  <h4 className="font-medium text-foreground">
                    Compliance Resources
                  </h4>
                  <p className="text-sm text-muted-foreground mt-1">
                    Understand GDPR and best practices for compliance
                  </p>
                  <Dialog open={gdprDialogOpen} onOpenChange={setGdprDialogOpen}>
                    <DialogTrigger asChild>
                      <Button variant="outline" size="sm" className="mt-2">
                        Learn More
                      </Button>
                    </DialogTrigger>
                    <DialogContent>
                      <DialogHeader>
                        <DialogTitle>GDPR Compliance Overview</DialogTitle>
                        <DialogDescription>
                          Key principles and how we help you comply.
                        </DialogDescription>
                      </DialogHeader>
                      <div className="space-y-3 text-sm">
                        <ul className="list-disc list-inside space-y-2">
                          <li>Lawful basis: Obtain consent or use legitimate interest.</li>
                          <li>Transparency: Clearly state messaging purpose and frequency.</li>
                          <li>Data minimization: Only store data necessary for messaging.</li>
                          <li>Opt-out: Honor STOP/UNSUBSCRIBE automatically and promptly.</li>
                          <li>Retention: Define and adhere to data retention policies.</li>
                          <li>Security: Protect data in transit and at rest.</li>
                        </ul>
                        <p className="text-muted-foreground">
                          Your account processes opt-out requests automatically within 24 hours.
                        </p>
                      </div>
                      <DialogFooter>
                        <Button variant="default" onClick={() => setGdprDialogOpen(false)}>Close</Button>
                      </DialogFooter>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>
            </div>
          </div>
        </TabsContent>

        {/* Compliance Tab */}
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
                  <p className="font-medium text-foreground">
                    GDPR Compliance
                  </p>
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
                  Your account is configured to comply with GDPR and local
                  privacy regulations. All opt-out requests are processed
                  automatically within 24 hours.
                </p>
              </div>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </DashboardLayout>
  );
}
