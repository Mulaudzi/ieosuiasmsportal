import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "@/hooks/use-toast";
import { Loader2, Shield, UserPlus, Key, AlertTriangle, ArrowLeft } from "lucide-react";
import { api } from "@/lib/api";

export default function AdminSetup() {
  const navigate = useNavigate();
  const [isLoading, setIsLoading] = useState(false);
  const [createForm, setCreateForm] = useState({
    email: "",
    password_1: "",
    confirmPassword_1: "",
    password_2: "",
    confirmPassword_2: "",
    password_3: "",
    confirmPassword_3: "",
    name: "",
    setupKey: "",
  });
  const [updateForm, setUpdateForm] = useState({
    email: "",
    currentPassword_1: "",
    currentPassword_2: "",
    currentPassword_3: "",
    newPassword_1: "",
    confirmNewPassword_1: "",
    newPassword_2: "",
    confirmNewPassword_2: "",
    newPassword_3: "",
    confirmNewPassword_3: "",
    setupKey: "",
  });

  const handleCreateAdmin = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!createForm.email || !createForm.password_1 || !createForm.password_2 || 
        !createForm.password_3 || !createForm.name || !createForm.setupKey) {
      toast({
        title: "Missing fields",
        description: "Please fill in all required fields.",
        variant: "destructive",
      });
      return;
    }

    // Validate all passwords
    const passwords = [
      { pwd: createForm.password_1, confirm: createForm.confirmPassword_1, num: 1 },
      { pwd: createForm.password_2, confirm: createForm.confirmPassword_2, num: 2 },
      { pwd: createForm.password_3, confirm: createForm.confirmPassword_3, num: 3 },
    ];

    for (const { pwd, confirm, num } of passwords) {
      if (pwd.length < 12) {
        toast({
          title: `Weak password ${num}`,
          description: `Password ${num} must be at least 12 characters long.`,
          variant: "destructive",
        });
        return;
      }
      if (pwd !== confirm) {
        toast({
          title: `Password ${num} mismatch`,
          description: `Password ${num} and confirmation do not match.`,
          variant: "destructive",
        });
        return;
      }
    }

    setIsLoading(true);
    try {
      await api.post("/admin-users/create", {
        email: createForm.email,
        password_1: createForm.password_1,
        password_2: createForm.password_2,
        password_3: createForm.password_3,
        name: createForm.name,
        setup_key: createForm.setupKey,
      });

      toast({
        title: "Admin created",
        description: "New admin user has been created successfully with 3 passwords.",
      });

      setCreateForm({
        email: "",
        password_1: "",
        confirmPassword_1: "",
        password_2: "",
        confirmPassword_2: "",
        password_3: "",
        confirmPassword_3: "",
        name: "",
        setupKey: "",
      });
    } catch (error: any) {
      toast({
        title: "Error",
        description: error.message || "Failed to create admin user.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleUpdatePassword = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!updateForm.email || !updateForm.currentPassword_1 || !updateForm.currentPassword_2 ||
        !updateForm.currentPassword_3 || !updateForm.newPassword_1 || !updateForm.newPassword_2 ||
        !updateForm.newPassword_3 || !updateForm.setupKey) {
      toast({
        title: "Missing fields",
        description: "Please fill in all required fields.",
        variant: "destructive",
      });
      return;
    }

    // Validate all new passwords
    const passwords = [
      { pwd: updateForm.newPassword_1, confirm: updateForm.confirmNewPassword_1, num: 1 },
      { pwd: updateForm.newPassword_2, confirm: updateForm.confirmNewPassword_2, num: 2 },
      { pwd: updateForm.newPassword_3, confirm: updateForm.confirmNewPassword_3, num: 3 },
    ];

    for (const { pwd, confirm, num } of passwords) {
      if (pwd.length < 12) {
        toast({
          title: `Weak password ${num}`,
          description: `New password ${num} must be at least 12 characters long.`,
          variant: "destructive",
        });
        return;
      }
      if (pwd !== confirm) {
        toast({
          title: `Password ${num} mismatch`,
          description: `New password ${num} and confirmation do not match.`,
          variant: "destructive",
        });
        return;
      }
    }

    setIsLoading(true);
    try {
      await api.post("/admin-users/update-password", {
        email: updateForm.email,
        current_password_1: updateForm.currentPassword_1,
        current_password_2: updateForm.currentPassword_2,
        current_password_3: updateForm.currentPassword_3,
        new_password_1: updateForm.newPassword_1,
        new_password_2: updateForm.newPassword_2,
        new_password_3: updateForm.newPassword_3,
        setup_key: updateForm.setupKey,
      });

      toast({
        title: "Passwords updated",
        description: "All 3 admin passwords have been updated successfully.",
      });

      setUpdateForm({
        email: "",
        currentPassword_1: "",
        currentPassword_2: "",
        currentPassword_3: "",
        newPassword_1: "",
        confirmNewPassword_1: "",
        newPassword_2: "",
        confirmNewPassword_2: "",
        newPassword_3: "",
        confirmNewPassword_3: "",
        setupKey: "",
      });
    } catch (error: any) {
      toast({
        title: "Error",
        description: error.message || "Failed to update passwords.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-background to-muted/30 p-4">
      <div className="mb-4">
        <Button 
          variant="ghost" 
          size="sm" 
          onClick={() => navigate(-1)}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          Back
        </Button>
      </div>
      <div className="flex items-center justify-center">
      <Card className="w-full max-w-2xl">
        <CardHeader className="text-center">
          <div className="mx-auto w-12 h-12 bg-destructive/10 rounded-full flex items-center justify-center mb-4">
            <Shield className="h-6 w-6 text-destructive" />
          </div>
          <CardTitle className="text-2xl">Admin Setup</CardTitle>
          <CardDescription>
            Create or update admin users with 3-password security
          </CardDescription>
          <div className="mt-4 p-3 bg-warning/10 border border-warning/20 rounded-lg flex items-start gap-2">
            <AlertTriangle className="h-5 w-5 text-warning shrink-0 mt-0.5" />
            <p className="text-sm text-muted-foreground text-left">
              <strong>Security:</strong> Admin accounts require 3 separate passwords for login.
              Each password must be at least 12 characters. The setup key is required for all operations.
            </p>
          </div>
        </CardHeader>
        <CardContent>
          <Tabs defaultValue="create" className="w-full">
            <TabsList className="grid w-full grid-cols-2">
              <TabsTrigger value="create" className="gap-2">
                <UserPlus className="h-4 w-4" />
                Create Admin
              </TabsTrigger>
              <TabsTrigger value="update" className="gap-2">
                <Key className="h-4 w-4" />
                Update Passwords
              </TabsTrigger>
            </TabsList>

            <TabsContent value="create" className="mt-6">
              <form onSubmit={handleCreateAdmin} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="create-name">Full Name</Label>
                    <Input
                      id="create-name"
                      placeholder="System Administrator"
                      value={createForm.name}
                      onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
                      disabled={isLoading}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="create-email">Email Address</Label>
                    <Input
                      id="create-email"
                      type="email"
                      placeholder="admin@example.com"
                      value={createForm.email}
                      onChange={(e) => setCreateForm({ ...createForm, email: e.target.value })}
                      disabled={isLoading}
                    />
                  </div>
                </div>

                <div className="border-t pt-4">
                  <h4 className="font-medium mb-3 flex items-center gap-2">
                    <Shield className="h-4 w-4" />
                    3-Password Security Setup
                  </h4>
                  
                  {[1, 2, 3].map((num) => (
                    <div key={num} className="grid grid-cols-2 gap-4 mb-4">
                      <div className="space-y-2">
                        <Label htmlFor={`create-password-${num}`}>Password {num}</Label>
                        <Input
                          id={`create-password-${num}`}
                          type="password"
                          placeholder="Min 12 characters"
                          value={createForm[`password_${num}` as keyof typeof createForm]}
                          onChange={(e) => setCreateForm({ ...createForm, [`password_${num}`]: e.target.value })}
                          disabled={isLoading}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor={`create-confirm-${num}`}>Confirm Password {num}</Label>
                        <Input
                          id={`create-confirm-${num}`}
                          type="password"
                          placeholder="Confirm password"
                          value={createForm[`confirmPassword_${num}` as keyof typeof createForm]}
                          onChange={(e) => setCreateForm({ ...createForm, [`confirmPassword_${num}`]: e.target.value })}
                          disabled={isLoading}
                        />
                      </div>
                    </div>
                  ))}
                </div>

                <div className="space-y-2 border-t pt-4">
                  <Label htmlFor="create-setup-key">Setup Key</Label>
                  <Input
                    id="create-setup-key"
                    type="password"
                    placeholder="Enter setup key"
                    value={createForm.setupKey}
                    onChange={(e) => setCreateForm({ ...createForm, setupKey: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <Button type="submit" className="w-full" disabled={isLoading}>
                  {isLoading ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      Creating...
                    </>
                  ) : (
                    <>
                      <UserPlus className="mr-2 h-4 w-4" />
                      Create Admin User
                    </>
                  )}
                </Button>
              </form>
            </TabsContent>

            <TabsContent value="update" className="mt-6">
              <form onSubmit={handleUpdatePassword} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="update-email">Admin Email</Label>
                  <Input
                    id="update-email"
                    type="email"
                    placeholder="admin@example.com"
                    value={updateForm.email}
                    onChange={(e) => setUpdateForm({ ...updateForm, email: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <div className="border-t pt-4">
                  <h4 className="font-medium mb-3">Current Passwords</h4>
                  <div className="grid grid-cols-3 gap-4">
                    {[1, 2, 3].map((num) => (
                      <div key={num} className="space-y-2">
                        <Label htmlFor={`update-current-${num}`}>Current #{num}</Label>
                        <Input
                          id={`update-current-${num}`}
                          type="password"
                          placeholder="••••••••"
                          value={updateForm[`currentPassword_${num}` as keyof typeof updateForm]}
                          onChange={(e) => setUpdateForm({ ...updateForm, [`currentPassword_${num}`]: e.target.value })}
                          disabled={isLoading}
                        />
                      </div>
                    ))}
                  </div>
                </div>

                <div className="border-t pt-4">
                  <h4 className="font-medium mb-3">New Passwords</h4>
                  {[1, 2, 3].map((num) => (
                    <div key={num} className="grid grid-cols-2 gap-4 mb-4">
                      <div className="space-y-2">
                        <Label htmlFor={`update-new-${num}`}>New Password {num}</Label>
                        <Input
                          id={`update-new-${num}`}
                          type="password"
                          placeholder="Min 12 characters"
                          value={updateForm[`newPassword_${num}` as keyof typeof updateForm]}
                          onChange={(e) => setUpdateForm({ ...updateForm, [`newPassword_${num}`]: e.target.value })}
                          disabled={isLoading}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor={`update-confirm-${num}`}>Confirm New #{num}</Label>
                        <Input
                          id={`update-confirm-${num}`}
                          type="password"
                          placeholder="Confirm"
                          value={updateForm[`confirmNewPassword_${num}` as keyof typeof updateForm]}
                          onChange={(e) => setUpdateForm({ ...updateForm, [`confirmNewPassword_${num}`]: e.target.value })}
                          disabled={isLoading}
                        />
                      </div>
                    </div>
                  ))}
                </div>

                <div className="space-y-2 border-t pt-4">
                  <Label htmlFor="update-setup-key">Setup Key</Label>
                  <Input
                    id="update-setup-key"
                    type="password"
                    placeholder="Enter setup key"
                    value={updateForm.setupKey}
                    onChange={(e) => setUpdateForm({ ...updateForm, setupKey: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <Button type="submit" className="w-full" disabled={isLoading}>
                  {isLoading ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      Updating...
                    </>
                  ) : (
                    <>
                      <Key className="mr-2 h-4 w-4" />
                      Update All Passwords
                    </>
                  )}
                </Button>
              </form>
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>
      </div>
    </div>
  );
}
