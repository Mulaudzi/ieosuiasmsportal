import { useState } from "react";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Plus,
  Search,
  Upload,
  Download,
  Users,
  Edit,
  Trash2,
  UserPlus,
  Ban,
  Loader2,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { deleteContacts, addContactsToGroup, exportContacts, importContacts } from "@/lib/api";

interface Contact {
  id: string;
  name: string;
  phone: string;
  email: string;
  group: string;
  status: "active" | "optedOut";
  createdAt: string;
}

const contacts: Contact[] = [
  {
    id: "1",
    name: "John Smith",
    phone: "+1 (555) 123-4567",
    email: "john.smith@email.com",
    group: "Customers",
    status: "active",
    createdAt: "Jan 5, 2026",
  },
  {
    id: "2",
    name: "Sarah Johnson",
    phone: "+1 (555) 234-5678",
    email: "sarah.j@company.com",
    group: "VIP",
    status: "active",
    createdAt: "Jan 4, 2026",
  },
  {
    id: "3",
    name: "Mike Wilson",
    phone: "+1 (555) 345-6789",
    email: "mike.w@business.com",
    group: "Leads",
    status: "active",
    createdAt: "Jan 3, 2026",
  },
  {
    id: "4",
    name: "Emily Davis",
    phone: "+1 (555) 456-7890",
    email: "emily.d@email.com",
    group: "Customers",
    status: "optedOut",
    createdAt: "Jan 2, 2026",
  },
  {
    id: "5",
    name: "David Brown",
    phone: "+1 (555) 567-8901",
    email: "david.b@corp.com",
    group: "Customers",
    status: "active",
    createdAt: "Jan 1, 2026",
  },
  {
    id: "6",
    name: "Lisa Anderson",
    phone: "+1 (555) 678-9012",
    email: "lisa.a@business.com",
    group: "VIP",
    status: "active",
    createdAt: "Dec 31, 2025",
  },
];

const groups = [
  { name: "All Contacts", count: 12450 },
  { name: "Customers", count: 8200 },
  { name: "Leads", count: 3500 },
  { name: "VIP", count: 750 },
  { name: "Opted Out", count: 320 },
];

export default function Contacts() {
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedGroup, setSelectedGroup] = useState("all");
  const [selectedContacts, setSelectedContacts] = useState<string[]>([]);
  const [loadingAction, setLoadingAction] = useState<string | null>(null);

  const toggleContact = (id: string) => {
    setSelectedContacts((prev) =>
      prev.includes(id) ? prev.filter((c) => c !== id) : [...prev, id]
    );
  };

  const toggleAll = () => {
    if (selectedContacts.length === contacts.length) {
      setSelectedContacts([]);
    } else {
      setSelectedContacts(contacts.map((c) => c.id));
    }
  };

  const handleImport = async () => {
    setLoadingAction("import");
    try {
      const response = await importContacts([]);
      if (response.success) {
        toast({
          title: "Contacts imported",
          description: `${response.data?.imported} contacts imported, ${response.data?.duplicatesRemoved} duplicates removed.`,
        });
      }
    } catch (error) {
      toast({
        title: "Import failed",
        description: "Please check your file and try again.",
        variant: "destructive",
      });
    } finally {
      setLoadingAction(null);
    }
  };

  const handleExport = async () => {
    setLoadingAction("export");
    try {
      const response = await exportContacts(selectedContacts.length > 0 ? selectedContacts : undefined);
      if (response.success) {
        toast({
          title: "Export ready",
          description: "Your contacts have been exported.",
        });
      }
    } catch (error) {
      toast({
        title: "Export failed",
        description: "Please try again.",
        variant: "destructive",
      });
    } finally {
      setLoadingAction(null);
    }
  };

  const handleAddContact = () => {
    toast({
      title: "Add Contact",
      description: "Contact form would open here.",
    });
  };

  const handleAddToGroup = async () => {
    if (selectedContacts.length === 0) return;
    setLoadingAction("addToGroup");
    try {
      const response = await addContactsToGroup(selectedContacts, "Customers");
      if (response.success) {
        toast({
          title: "Contacts added to group",
          description: `${selectedContacts.length} contacts added.`,
        });
        setSelectedContacts([]);
      }
    } catch (error) {
      toast({
        title: "Action failed",
        description: "Please try again.",
        variant: "destructive",
      });
    } finally {
      setLoadingAction(null);
    }
  };

  const handleBulkDelete = async () => {
    if (selectedContacts.length === 0) return;
    setLoadingAction("bulkDelete");
    try {
      const response = await deleteContacts(selectedContacts);
      if (response.success) {
        toast({
          title: "Contacts deleted",
          description: `${response.data?.deleted} contacts removed.`,
        });
        setSelectedContacts([]);
      }
    } catch (error) {
      toast({
        title: "Delete failed",
        description: "Please try again.",
        variant: "destructive",
      });
    } finally {
      setLoadingAction(null);
    }
  };

  const handleEditContact = (id: string) => {
    toast({
      title: "Edit Contact",
      description: `Editing contact ${id}`,
    });
  };

  const handleDeleteContact = async (id: string) => {
    setLoadingAction(`delete-${id}`);
    try {
      const response = await deleteContacts([id]);
      if (response.success) {
        toast({
          title: "Contact deleted",
          description: "The contact has been removed.",
        });
      }
    } catch (error) {
      toast({
        title: "Delete failed",
        description: "Please try again.",
        variant: "destructive",
      });
    } finally {
      setLoadingAction(null);
    }
  };

  const handleCreateGroup = () => {
    toast({
      title: "Create Group",
      description: "Group creation form would open here.",
    });
  };

  return (
    <DashboardLayout
      title="Contacts"
      subtitle="Manage your contact lists and groups"
      actions={
        <div className="flex gap-3">
          <Button variant="outline" className="gap-2" onClick={handleImport} disabled={loadingAction === "import"}>
            {loadingAction === "import" ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
            Import
          </Button>
          <Button variant="outline" className="gap-2" onClick={handleExport} disabled={loadingAction === "export"}>
            {loadingAction === "export" ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
            Export
          </Button>
          <Button className="gap-2" onClick={handleAddContact}>
            <UserPlus className="h-4 w-4" />
            Add Contact
          </Button>
        </div>
      }
    >
      <div className="grid gap-6 lg:grid-cols-4">
        {/* Groups Sidebar */}
        <div className="lg:col-span-1">
          <div className="rounded-xl border border-border bg-card p-4">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="font-semibold text-foreground">Groups</h3>
              <Button variant="ghost" size="icon" onClick={handleCreateGroup}>
                <Plus className="h-4 w-4" />
              </Button>
            </div>

            <div className="space-y-1">
              {groups.map((group) => (
                <button
                  key={group.name}
                  onClick={() =>
                    setSelectedGroup(group.name.toLowerCase().replace(" ", "-"))
                  }
                  className={cn(
                    "flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors",
                    selectedGroup === group.name.toLowerCase().replace(" ", "-")
                      ? "bg-primary text-primary-foreground"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground"
                  )}
                >
                  <div className="flex items-center gap-2">
                    {group.name === "Opted Out" ? (
                      <Ban className="h-4 w-4" />
                    ) : (
                      <Users className="h-4 w-4" />
                    )}
                    <span>{group.name}</span>
                  </div>
                  <span className="text-xs opacity-80">
                    {group.count.toLocaleString()}
                  </span>
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* Contacts List */}
        <div className="lg:col-span-3">
          {/* Filters */}
          <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="relative flex-1 max-w-md">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search contacts..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-10"
              />
            </div>
            <div className="flex items-center gap-3">
              <Select defaultValue="newest">
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="newest">Newest</SelectItem>
                  <SelectItem value="oldest">Oldest</SelectItem>
                  <SelectItem value="name">Name</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {/* Selected Actions */}
          {selectedContacts.length > 0 && (
            <div className="mb-4 flex items-center gap-4 rounded-lg bg-primary/10 p-3">
              <span className="text-sm font-medium text-primary">
                {selectedContacts.length} selected
              </span>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={handleAddToGroup} disabled={loadingAction === "addToGroup"}>
                  {loadingAction === "addToGroup" ? <Loader2 className="h-4 w-4 animate-spin mr-1" /> : null}
                  Add to Group
                </Button>
                <Button variant="outline" size="sm" onClick={handleExport} disabled={loadingAction === "export"}>
                  Export
                </Button>
                <Button variant="destructive" size="sm" onClick={handleBulkDelete} disabled={loadingAction === "bulkDelete"}>
                  {loadingAction === "bulkDelete" ? <Loader2 className="h-4 w-4 animate-spin mr-1" /> : null}
                  Delete
                </Button>
              </div>
            </div>
          )}

          {/* Contacts Table */}
          <div className="rounded-xl border border-border bg-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border bg-muted/50">
                    <th className="px-4 py-4 text-left">
                      <Checkbox
                        checked={selectedContacts.length === contacts.length}
                        onCheckedChange={toggleAll}
                      />
                    </th>
                    <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">
                      Contact
                    </th>
                    <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">
                      Phone
                    </th>
                    <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">
                      Group
                    </th>
                    <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">
                      Status
                    </th>
                    <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">
                      Added
                    </th>
                    <th className="px-4 py-4 text-right text-sm font-medium text-muted-foreground">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {contacts.map((contact) => (
                    <tr
                      key={contact.id}
                      className="transition-colors hover:bg-muted/30"
                    >
                      <td className="px-4 py-4">
                        <Checkbox
                          checked={selectedContacts.includes(contact.id)}
                          onCheckedChange={() => toggleContact(contact.id)}
                        />
                      </td>
                      <td className="px-4 py-4">
                        <div className="flex items-center gap-3">
                          <div className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-sm font-medium text-primary">
                            {contact.name
                              .split(" ")
                              .map((n) => n[0])
                              .join("")}
                          </div>
                          <div>
                            <p className="font-medium text-foreground">
                              {contact.name}
                            </p>
                            <p className="text-sm text-muted-foreground">
                              {contact.email}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-4 text-foreground">
                        {contact.phone}
                      </td>
                      <td className="px-4 py-4">
                        <span className="inline-flex items-center rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground">
                          {contact.group}
                        </span>
                      </td>
                      <td className="px-4 py-4">
                        <span
                          className={cn(
                            "status-badge",
                            contact.status === "active"
                              ? "status-delivered"
                              : "status-failed"
                          )}
                        >
                          {contact.status === "active" ? "Active" : "Opted Out"}
                        </span>
                      </td>
                      <td className="px-4 py-4 text-muted-foreground">
                        {contact.createdAt}
                      </td>
                      <td className="px-4 py-4">
                        <div className="flex items-center justify-end gap-1">
                          <Button variant="ghost" size="icon" onClick={() => handleEditContact(contact.id)}>
                            <Edit className="h-4 w-4" />
                          </Button>
                          <Button 
                            variant="ghost" 
                            size="icon" 
                            onClick={() => handleDeleteContact(contact.id)}
                            disabled={loadingAction === `delete-${contact.id}`}
                          >
                            {loadingAction === `delete-${contact.id}` ? (
                              <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                              <Trash2 className="h-4 w-4 text-destructive" />
                            )}
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Pagination */}
          <div className="mt-4 flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              Showing 1-6 of 12,450 contacts
            </p>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" disabled>
                Previous
              </Button>
              <Button variant="outline" size="sm">
                Next
              </Button>
            </div>
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
