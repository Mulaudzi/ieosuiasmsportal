import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "@/hooks/use-toast";
import { Loader2, Shield, UserPlus, Key, AlertTriangle } from "lucide-react";
import { api } from "@/lib/api";

export default function AdminSetup() {
  const [isLoading, setIsLoading] = useState(false);
  const [createForm, setCreateForm] = useState({
    email: "",
    password: "",
    confirmPassword: "",
    name: "",
    setupKey: "",
  });
  const [updateForm, setUpdateForm] = useState({
    email: "",
    currentPassword: "",
    newPassword: "",
    confirmNewPassword: "",
    setupKey: "",
  });

  const handleCreateAdmin = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!createForm.email || !createForm.password || !createForm.name || !createForm.setupKey) {
      toast({
        title: "Missing fields",
        description: "Please fill in all required fields.",
        variant: "destructive",
      });
      return;
    }

    if (createForm.password.length < 12) {
      toast({
        title: "Weak password",
        description: "Password must be at least 12 characters long.",
        variant: "destructive",
      });
      return;
    }

    if (createForm.password !== createForm.confirmPassword) {
      toast({
        title: "Password mismatch",
        description: "Passwords do not match.",
        variant: "destructive",
      });
      return;
    }

    setIsLoading(true);
    try {
      await api.post("/admin-users/create", {
        email: createForm.email,
        password: createForm.password,
        name: createForm.name,
        setup_key: createForm.setupKey,
      });

      toast({
        title: "Admin created",
        description: "New admin user has been created successfully.",
      });

      setCreateForm({
        email: "",
        password: "",
        confirmPassword: "",
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

    if (!updateForm.email || !updateForm.currentPassword || !updateForm.newPassword || !updateForm.setupKey) {
      toast({
        title: "Missing fields",
        description: "Please fill in all required fields.",
        variant: "destructive",
      });
      return;
    }

    if (updateForm.newPassword.length < 12) {
      toast({
        title: "Weak password",
        description: "Password must be at least 12 characters long.",
        variant: "destructive",
      });
      return;
    }

    if (updateForm.newPassword !== updateForm.confirmNewPassword) {
      toast({
        title: "Password mismatch",
        description: "New passwords do not match.",
        variant: "destructive",
      });
      return;
    }

    setIsLoading(true);
    try {
      await api.post("/admin-users/update-password", {
        email: updateForm.email,
        current_password: updateForm.currentPassword,
        new_password: updateForm.newPassword,
        setup_key: updateForm.setupKey,
      });

      toast({
        title: "Password updated",
        description: "Admin password has been updated successfully.",
      });

      setUpdateForm({
        email: "",
        currentPassword: "",
        newPassword: "",
        confirmNewPassword: "",
        setupKey: "",
      });
    } catch (error: any) {
      toast({
        title: "Error",
        description: error.message || "Failed to update password.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-background to-muted/30 flex items-center justify-center p-4">
      <Card className="w-full max-w-lg">
        <CardHeader className="text-center">
          <div className="mx-auto w-12 h-12 bg-destructive/10 rounded-full flex items-center justify-center mb-4">
            <Shield className="h-6 w-6 text-destructive" />
          </div>
          <CardTitle className="text-2xl">Admin Setup</CardTitle>
          <CardDescription>
            Temporary page for admin user management. Remove after setup.
          </CardDescription>
          <div className="mt-4 p-3 bg-warning/10 border border-warning/20 rounded-lg flex items-start gap-2">
            <AlertTriangle className="h-5 w-5 text-warning shrink-0 mt-0.5" />
            <p className="text-sm text-muted-foreground text-left">
              <strong>Security Warning:</strong> This page should be removed or protected after initial setup. 
              The setup key is required for all operations.
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
                Update Password
              </TabsTrigger>
            </TabsList>

            <TabsContent value="create" className="mt-6">
              <form onSubmit={handleCreateAdmin} className="space-y-4">
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

                <div className="space-y-2">
                  <Label htmlFor="create-password">Password (min 12 characters)</Label>
                  <Input
                    id="create-password"
                    type="password"
                    placeholder="••••••••••••"
                    value={createForm.password}
                    onChange={(e) => setCreateForm({ ...createForm, password: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="create-confirm">Confirm Password</Label>
                  <Input
                    id="create-confirm"
                    type="password"
                    placeholder="••••••••••••"
                    value={createForm.confirmPassword}
                    onChange={(e) => setCreateForm({ ...createForm, confirmPassword: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
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

                <div className="space-y-2">
                  <Label htmlFor="update-current">Current Password</Label>
                  <Input
                    id="update-current"
                    type="password"
                    placeholder="••••••••••••"
                    value={updateForm.currentPassword}
                    onChange={(e) => setUpdateForm({ ...updateForm, currentPassword: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="update-new">New Password (min 12 characters)</Label>
                  <Input
                    id="update-new"
                    type="password"
                    placeholder="••••••••••••"
                    value={updateForm.newPassword}
                    onChange={(e) => setUpdateForm({ ...updateForm, newPassword: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="update-confirm">Confirm New Password</Label>
                  <Input
                    id="update-confirm"
                    type="password"
                    placeholder="••••••••••••"
                    value={updateForm.confirmNewPassword}
                    onChange={(e) => setUpdateForm({ ...updateForm, confirmNewPassword: e.target.value })}
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
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
                      Update Password
                    </>
                  )}
                </Button>
              </form>
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>
    </div>
  );
}
