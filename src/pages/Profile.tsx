import { useState, useEffect, useRef } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAuth } from "@/hooks/useAuth";
import { toast } from "@/hooks/use-toast";
import { saveSettings, updateProfile, uploadBranding, handleApiError, getCurrentUser } from "@/lib/api";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2, Mail, Phone, Calendar, Shield, CheckCircle2, AlertCircle, User, Key, Save, Camera, RotateCcw, Upload, X, Building, Bell } from "lucide-react";
import { format } from "date-fns";
import { AvatarUploadModal } from "@/components/profile/AvatarUploadModal";
import { resetOnboarding } from "@/components/onboarding/OnboardingTrigger";
import { resetDashboardTutorial } from "@/components/dashboard/FeatureTooltip";

const API_URL = import.meta.env.VITE_API_URL || "https://sms.ieosuia.com/api";

const profileSchema = z.object({
  firstName: z.string().min(1, "First name is required"),
  lastName: z.string().min(1, "Last name is required"),
  email: z.string().email("Invalid email address").readonly(),
  phone: z.string().optional().nullable(),
});

type ProfileFormData = z.infer<typeof profileSchema>;

const organizationSchema = z.object({
  orgName: z.string().min(1, "Organization name is required"),
  industry: z.string().optional(),
  address: z.string().optional(),
  website: z.string().url("Invalid URL").optional().or(z.literal("")),
});

type OrganizationFormData = z.infer<typeof organizationSchema>;

export default function Profile() {
  const { user, updateUser, isEmailVerified, resendVerification, token } = useAuth();
  const [savingSection, setSavingSection] = useState<string | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [isAvatarModalOpen, setIsAvatarModalOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const logoInputRef = useRef<HTMLInputElement>(null);
  const { updateUser: updateAuthUser } = useAuth();

  const profileForm = useForm<ProfileFormData>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      firstName: "",
      lastName: "",
      email: "",
      phone: "",
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

  const [passwordForm, setPasswordForm] = useState({
    currentPassword: "",
    newPassword: "",
    confirmPassword: "",
  });

  // Load current user data on mount
  useEffect(() => {
    const loadUserData = async () => {
      try {
        setIsLoading(true);
        const response = await getCurrentUser();
        
        if (response.success && response.data?.user) {
          const userData = response.data.user;
          
          // Parse name into first and last name
          const nameParts = (userData.name || "").trim().split(/\s+/);
          const firstName = nameParts[0] || "";
          const lastName = nameParts.slice(1).join(" ") || "";
          
          profileForm.reset({
            firstName: firstName || "",
            lastName: lastName || "",
            email: userData.email || "",
            phone: userData.phone || "",
          });
        }
      } catch (error) {
        console.error("Failed to load user data:", error);
        toast({
          title: "Failed to load profile",
          description: "Could not fetch your profile data.",
          variant: "destructive",
        });
      } finally {
        setIsLoading(false);
      }
    };

    loadUserData();
  }, []);

  const handleAvatarUpload = async (imageData: string) => {
    try {
      const response = await fetch(`${API_URL}/auth/avatar`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ avatar: imageData }),
      });

      const data = await response.json();

      if (data.success) {
        await updateUser({
          avatar_url: data.avatar_url || (data.user?.avatar_url),
          name: data.user?.name,
          phone: data.user?.phone,
        });
        toast({
          title: "Avatar updated",
          description: "Your profile photo has been updated successfully.",
        });
      } else {
        throw new Error(data.message || "Failed to upload avatar");
      }
    } catch (error) {
      console.error("Avatar upload error:", error);
      toast({
        title: "Upload failed",
        description: error instanceof Error ? error.message : "Failed to upload avatar.",
        variant: "destructive",
      });
    }
  };

  const handleProfileSubmit = async (data: ProfileFormData) => {
    setSavingSection("profile");
    try {
      const fullName = `${data.firstName} ${data.lastName}`;
      
      const response = await updateProfile({
        name: fullName,
        phone: data.phone,
      });
      
      if (response.success) {
        await updateAuthUser({
          name: fullName,
          phone: data.phone,
        });
        
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

  const handleChangePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (passwordForm.newPassword !== passwordForm.confirmPassword) {
      toast({
        title: "Passwords don't match",
        description: "Please make sure your new passwords match.",
        variant: "destructive",
      });
      return;
    }
    
    if (passwordForm.newPassword.length < 8) {
      toast({
        title: "Password too short",
        description: "New password must be at least 8 characters.",
        variant: "destructive",
      });
      return;
    }
    
    setSavingSection("password");
    
    try {
      const result = await updateUser({
        current_password: passwordForm.currentPassword,
        password: passwordForm.newPassword,
        password_confirmation: passwordForm.confirmPassword,
      });
      
      if (result.success) {
        toast({
          title: "Password changed",
          description: "Your password has been changed successfully.",
        });
        setPasswordForm({
          currentPassword: "",
          newPassword: "",
          confirmPassword: "",
        });
      } else {
        toast({
          title: "Password change failed",
          description: result.error || "Failed to change password.",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Something went wrong. Please try again.",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
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

  const handleResendVerification = async () => {
    setSavingSection("verify");
    try {
      const result = await resendVerification();
      if (result.success) {
        toast({
          title: "Verification email sent",
          description: "Please check your inbox.",
        });
      } else {
        toast({
          title: "Failed to send",
          description: result.error || "Please try again.",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Something went wrong.",
        variant: "destructive",
      });
    } finally {
      setSavingSection(null);
    }
  };

  if (!user) {
    return (
      <DashboardLayout title="Profile" subtitle="Manage your account settings">
        <div className="flex items-center justify-center h-64">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      </DashboardLayout>
    );
  }

  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map(n => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  return (
    <DashboardLayout
      title="Profile"
      subtitle="Manage your account settings and preferences"
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
          <TabsTrigger value="security" className="gap-2">
            <Shield className="h-4 w-4" />
            Security
          </TabsTrigger>
          <TabsTrigger value="notifications" className="gap-2">
            <Bell className="h-4 w-4" />
            Notifications
          </TabsTrigger>
          <TabsTrigger value="started" className="gap-2">
            <RotateCcw className="h-4 w-4" />
            Get Started
          </TabsTrigger>
        </TabsList>

        {/* Profile Tab */}
        <TabsContent value="profile">
          <div className="space-y-6">
            <div className="grid gap-6 lg:grid-cols-3">
              {/* Profile Overview Card */}
              <div className="rounded-xl border border-border bg-card p-6 lg:col-span-1">
                <div className="text-center space-y-4">
                  <div className="relative mx-auto">
                    <div className="flex h-24 w-24 items-center justify-center rounded-full bg-primary/10 text-2xl font-bold text-primary mx-auto">
                      {getInitials(user.name || 'U')}
                    </div>
                    <Button
                      variant="secondary"
                      size="icon"
                      className="absolute -bottom-1 -right-1 h-8 w-8 rounded-full shadow-md"
                      onClick={() => setIsAvatarModalOpen(true)}
                    >
                      <Camera className="h-4 w-4" />
                    </Button>
                  </div>
                  <div>
                    <h3 className="font-semibold">{user.name}</h3>
                    <p className="text-sm text-muted-foreground">{user.email}</p>
                  </div>

                  <div className="flex items-center justify-center pt-2">
                    {isEmailVerified ? (
                      <div className="flex items-center gap-2 text-success text-sm">
                        <CheckCircle2 className="h-4 w-4" />
                        Email Verified
                      </div>
                    ) : (
                      <div className="flex items-center gap-2 text-destructive text-sm">
                        <AlertCircle className="h-4 w-4" />
                        Email Not Verified
                      </div>
                    )}
                  </div>

                  {!isEmailVerified && (
                    <Button 
                      variant="outline" 
                      size="sm" 
                      className="w-full"
                      onClick={handleResendVerification}
                      disabled={savingSection === "verify"}
                    >
                      {savingSection === "verify" ? (
                        <>
                          <Loader2 className="h-4 w-4 animate-spin mr-2" />
                          Sending...
                        </>
                      ) : (
                        <>
                          <Mail className="h-4 w-4 mr-2" />
                          Resend Verification
                        </>
                      )}
                    </Button>
                  )}

                  <div className="border-t pt-4 space-y-3 text-sm">
                    <div className="flex items-center gap-3 text-muted-foreground">
                      <Mail className="h-4 w-4" />
                      <span>{user.email}</span>
                    </div>
                    {user.phone && (
                      <div className="flex items-center gap-3 text-muted-foreground">
                        <Phone className="h-4 w-4" />
                        <span>{user.phone}</span>
                      </div>
                    )}
                    <div className="flex items-center gap-3 text-muted-foreground">
                      <Shield className="h-4 w-4" />
                      <span className="capitalize">{user.account_type || 'Standard'} Account</span>
                    </div>
                    <div className="flex items-center gap-3 text-muted-foreground">
                      <Calendar className="h-4 w-4" />
                      <span>Joined {user.created_at ? format(new Date(user.created_at), 'MMM d, yyyy') : 'Recently'}</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Edit Profile Form */}
              <div className="lg:col-span-2">
                <form onSubmit={profileForm.handleSubmit(handleProfileSubmit)}>
                  <div className="rounded-xl border border-border bg-card p-6">
                    <h3 className="text-lg font-semibold text-foreground">
                      Edit Profile
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Update your personal information
                    </p>

                    <div className="mt-6 space-y-4">
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
                          disabled
                          className="mt-1.5"
                        />
                        <p className="mt-1 text-xs text-muted-foreground">
                          Contact support to change your email address
                        </p>
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
              </div>
            </div>
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

        {/* Security Tab */}
        <TabsContent value="security">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-lg font-semibold text-foreground">
              Change Password
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Update your password to keep your account secure
            </p>

            <form onSubmit={handleChangePassword} className="mt-6 space-y-4 max-w-md">
              <div>
                <Label htmlFor="currentPassword">Current Password</Label>
                <Input
                  id="currentPassword"
                  type="password"
                  value={passwordForm.currentPassword}
                  onChange={(e) => setPasswordForm({ ...passwordForm, currentPassword: e.target.value })}
                  placeholder="Enter current password"
                  disabled={savingSection === "password"}
                  className="mt-1.5"
                />
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label htmlFor="newPassword">New Password</Label>
                  <Input
                    id="newPassword"
                    type="password"
                    value={passwordForm.newPassword}
                    onChange={(e) => setPasswordForm({ ...passwordForm, newPassword: e.target.value })}
                    placeholder="Min. 8 characters"
                    disabled={savingSection === "password"}
                    className="mt-1.5"
                  />
                </div>
                <div>
                  <Label htmlFor="confirmPassword">Confirm New Password</Label>
                  <Input
                    id="confirmPassword"
                    type="password"
                    value={passwordForm.confirmPassword}
                    onChange={(e) => setPasswordForm({ ...passwordForm, confirmPassword: e.target.value })}
                    placeholder="Confirm new password"
                    disabled={savingSection === "password"}
                    className="mt-1.5"
                  />
                </div>
              </div>
              <Button 
                type="submit" 
                variant="outline" 
                disabled={savingSection === "password" || !passwordForm.currentPassword || !passwordForm.newPassword}
              >
                {savingSection === "password" ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                    Updating...
                  </>
                ) : (
                  <>
                    <Key className="h-4 w-4 mr-2" />
                    Change Password
                  </>
                )}
              </Button>
            </form>
          </div>
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

              <Button onClick={() => {}} disabled={savingSection === "notifications"}>
                {savingSection === "notifications" && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
                Save Preferences
              </Button>
            </div>
          </div>
        </TabsContent>

        {/* Get Started Tab */}
        <TabsContent value="started">
          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="text-lg font-semibold text-foreground">
              Getting Started Guide
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Restart the onboarding walkthrough to learn about features
            </p>

            <div className="mt-6 flex items-center justify-between max-w-lg">
              <div>
                <p className="text-sm text-muted-foreground">
                  Need a refresher? Restart the onboarding flow to walk through setting up campaigns step by step.
                </p>
              </div>
              <Button 
                variant="outline" 
                onClick={() => {
                  resetOnboarding();
                  resetDashboardTutorial();
                  toast({
                    title: "Guides reset",
                    description: "The onboarding flow and dashboard tutorial will show on your next Dashboard visit.",
                  });
                }}
                className="shrink-0 ml-4"
              >
                <RotateCcw className="h-4 w-4 mr-2" />
                Restart Guides
              </Button>
            </div>
          </div>
        </TabsContent>
      </Tabs>

      <AvatarUploadModal
        open={isAvatarModalOpen}
        onClose={() => setIsAvatarModalOpen(false)}
        onUpload={handleAvatarUpload}
      />
    </DashboardLayout>
  );
}