import { useState, useEffect, useCallback } from "react";
import { useLocation } from "react-router-dom";
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
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
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
  RefreshCw,
  MoreVertical,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
} from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { getContacts, getContactGroups, deleteContacts, deleteContactGroup, exportContacts, handleApiError } from "@/lib/api";
import { ContactImportModal } from "@/components/contacts/ContactImportModal";
import { AddContactModal } from "@/components/contacts/AddContactModal";
import { EditContactModal } from "@/components/contacts/EditContactModal";
import { CreateGroupModal } from "@/components/contacts/CreateGroupModal";
import { EditGroupModal } from "@/components/contacts/EditGroupModal";
import { format } from "date-fns";

interface Contact {
  id: string;
  name: string;
  phone: string;
  email: string;
  group_id: string;
  group_name: string;
  subscription_status: "subscribed" | "unsubscribed";
  created_at: string;
}

interface Group {
  id: string;
  name: string;
  description?: string;
  contact_count: number;
}

export default function Contacts() {
  const location = useLocation();
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedGroup, setSelectedGroup] = useState("all");
  const [selectedContacts, setSelectedContacts] = useState<string[]>([]);
  const [loadingAction, setLoadingAction] = useState<string | null>(null);
  const [importModalOpen, setImportModalOpen] = useState(location.pathname === "/contacts/import");
  const [addContactModalOpen, setAddContactModalOpen] = useState(false);
  const [editContactModalOpen, setEditContactModalOpen] = useState(false);
  const [contactToEdit, setContactToEdit] = useState<Contact | null>(null);
  const [createGroupModalOpen, setCreateGroupModalOpen] = useState(false);
  const [editGroupModalOpen, setEditGroupModalOpen] = useState(false);
  const [groupToEdit, setGroupToEdit] = useState<Group | null>(null);
  const [deleteGroupDialogOpen, setDeleteGroupDialogOpen] = useState(false);
  const [groupToDelete, setGroupToDelete] = useState<Group | null>(null);
  const [deletingGroup, setDeletingGroup] = useState(false);
  const [pagination, setPagination] = useState({ page: 1, total: 0, limit: 50 });
  const [sortOrder, setSortOrder] = useState("newest");

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [contactsRes, groupsRes] = await Promise.all([
        getContacts({ 
          group: selectedGroup !== "all" ? selectedGroup : undefined,
          search: searchQuery || undefined,
          page: pagination.page,
          limit: pagination.limit
        }),
        getContactGroups()
      ]);
      
      if (contactsRes.success && contactsRes.data) {
        setContacts(contactsRes.data.contacts || []);
        setPagination(prev => ({ ...prev, total: contactsRes.data?.total || 0 }));
      }
      
      if (groupsRes.success && groupsRes.data) {
        // Handle response format: { groups: [...] }
        const groupsData = Array.isArray(groupsRes.data) ? groupsRes.data : (groupsRes.data.groups || []);
        setGroups(groupsData);
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoading(false);
    }
  }, [selectedGroup, searchQuery, pagination.page, pagination.limit]);

  useEffect(() => {
    loadData();
  }, [loadData]);

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

  const handleExport = async () => {
    setLoadingAction("export");
    try {
      exportContacts(selectedGroup !== "all" ? selectedGroup : undefined);
      toast({
        title: "Export started",
        description: "Your contacts export is being prepared.",
      });
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoadingAction(null);
    }
  };

  const handleAddToGroup = async () => {
    if (selectedContacts.length === 0) return;
    setLoadingAction("addToGroup");
    // TODO: Implement add to group API
    setTimeout(() => {
      toast({
        title: "Contacts added to group",
        description: `${selectedContacts.length} contacts added.`,
      });
      setSelectedContacts([]);
      setLoadingAction(null);
    }, 1000);
  };

  const handleBulkDelete = async () => {
    if (selectedContacts.length === 0) return;
    setLoadingAction("bulkDelete");
    try {
      const response = await deleteContacts(selectedContacts);
      if (response.success) {
        toast({
          title: "Contacts deleted",
          description: `${response.data?.deleted || selectedContacts.length} contacts removed.`,
        });
        setSelectedContacts([]);
        loadData();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoadingAction(null);
    }
  };

  const handleEditContact = (contact: Contact) => {
    setContactToEdit(contact);
    setEditContactModalOpen(true);
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
        loadData();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setLoadingAction(null);
    }
  };

  const handleEditGroup = (group: Group) => {
    setGroupToEdit(group);
    setEditGroupModalOpen(true);
  };

  const handleDeleteGroupConfirm = (group: Group) => {
    setGroupToDelete(group);
    setDeleteGroupDialogOpen(true);
  };

  const handleDeleteGroup = async () => {
    if (!groupToDelete) return;
    setDeletingGroup(true);
    try {
      const response = await deleteContactGroup(groupToDelete.id);
      if (response.success) {
        toast({
          title: "Group deleted",
          description: `"${groupToDelete.name}" has been removed.`,
        });
        if (selectedGroup === groupToDelete.id) {
          setSelectedGroup("all");
        }
        loadData();
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setDeletingGroup(false);
      setDeleteGroupDialogOpen(false);
      setGroupToDelete(null);
    }
  };

  const formatDate = (dateString: string) => {
    try {
      return format(new Date(dateString), "MMM d, yyyy");
    } catch {
      return dateString;
    }
  };

  const totalContacts = groups.reduce((acc, g) => acc + (g.contact_count || 0), 0);
  const optedOutCount = contacts.filter(c => c.subscription_status === "unsubscribed").length;

  return (
    <>
      <ContactImportModal open={importModalOpen} onOpenChange={(open) => { setImportModalOpen(open); if (!open) loadData(); }} />
      <AddContactModal open={addContactModalOpen} onOpenChange={(open) => { setAddContactModalOpen(open); if (!open) loadData(); }} />
      <EditContactModal 
        open={editContactModalOpen} 
        onOpenChange={(open) => { setEditContactModalOpen(open); if (!open) loadData(); }} 
        contact={contactToEdit}
        onSuccess={loadData}
      />
      <CreateGroupModal open={createGroupModalOpen} onOpenChange={(open) => { setCreateGroupModalOpen(open); if (!open) loadData(); }} />
      <EditGroupModal 
        open={editGroupModalOpen} 
        onOpenChange={(open) => { setEditGroupModalOpen(open); if (!open) loadData(); }} 
        group={groupToEdit}
        onSuccess={loadData}
      />
      <DashboardLayout
        title="Contacts"
        subtitle="Manage your contact lists and groups"
        actions={
          <div className="flex gap-3">
            <Button variant="outline" size="icon" onClick={loadData} disabled={loading}>
              <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />
            </Button>
            <Button variant="outline" className="gap-2" onClick={() => setImportModalOpen(true)}>
              <Upload className="h-4 w-4" />
              Import
            </Button>
            <Button variant="outline" className="gap-2" onClick={handleExport} disabled={loadingAction === "export"}>
              {loadingAction === "export" ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
              Export
            </Button>
            <Button className="gap-2" onClick={() => setAddContactModalOpen(true)}>
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
                <Button variant="ghost" size="icon" onClick={() => setCreateGroupModalOpen(true)}>
                  <Plus className="h-4 w-4" />
                </Button>
              </div>

              <div className="space-y-1">
                <button
                  onClick={() => setSelectedGroup("all")}
                  className={cn(
                    "flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors",
                    selectedGroup === "all"
                      ? "bg-primary text-primary-foreground"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground"
                  )}
                >
                  <div className="flex items-center gap-2">
                    <Users className="h-4 w-4" />
                    <span>All Contacts</span>
                  </div>
                  <span className="text-xs opacity-80">{totalContacts.toLocaleString()}</span>
                </button>
                
                {groups.map((group) => (
                  <div
                    key={group.id}
                    className={cn(
                      "group/item flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors",
                      selectedGroup === group.id
                        ? "bg-primary text-primary-foreground"
                        : "text-muted-foreground hover:bg-muted hover:text-foreground"
                    )}
                  >
                    <button
                      onClick={() => setSelectedGroup(group.id)}
                      className="flex flex-1 items-center gap-2"
                    >
                      <Users className="h-4 w-4" />
                      <span>{group.name}</span>
                    </button>
                    <div className="flex items-center gap-1">
                      <span className="text-xs opacity-80">{(group.contact_count || 0).toLocaleString()}</span>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon"
                            className={cn(
                              "h-6 w-6 opacity-0 group-hover/item:opacity-100",
                              selectedGroup === group.id ? "hover:bg-primary-foreground/20" : ""
                            )}
                            onClick={(e) => e.stopPropagation()}
                          >
                            <MoreVertical className="h-3 w-3" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="bg-popover">
                          <DropdownMenuItem onClick={() => handleEditGroup(group)}>
                            <Edit className="mr-2 h-4 w-4" />
                            Edit
                          </DropdownMenuItem>
                          <DropdownMenuItem 
                            onClick={() => handleDeleteGroupConfirm(group)}
                            className="text-destructive focus:text-destructive"
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            Delete
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>
                ))}

                <button
                  onClick={() => setSelectedGroup("opted-out")}
                  className={cn(
                    "flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors",
                    selectedGroup === "opted-out"
                      ? "bg-primary text-primary-foreground"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground"
                  )}
                >
                  <div className="flex items-center gap-2">
                    <Ban className="h-4 w-4" />
                    <span>Opted Out</span>
                  </div>
                  <span className="text-xs opacity-80">{optedOutCount}</span>
                </button>
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
                <Select value={sortOrder} onValueChange={setSortOrder}>
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
              {loading ? (
                <div className="flex h-64 items-center justify-center">
                  <Loader2 className="h-8 w-8 animate-spin text-primary" />
                </div>
              ) : contacts.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12">
                  <Users className="h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-lg font-medium text-foreground">No contacts found</p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {searchQuery ? "Try adjusting your search" : "Add contacts to get started"}
                  </p>
                  {!searchQuery && (
                    <Button onClick={() => setAddContactModalOpen(true)} className="mt-4 gap-2">
                      <UserPlus className="h-4 w-4" />
                      Add Contact
                    </Button>
                  )}
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b border-border bg-muted/50">
                        <th className="px-4 py-4 text-left">
                          <Checkbox
                            checked={selectedContacts.length === contacts.length && contacts.length > 0}
                            onCheckedChange={toggleAll}
                          />
                        </th>
                        <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">Contact</th>
                        <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">Phone</th>
                        <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">Group</th>
                        <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">Status</th>
                        <th className="px-4 py-4 text-left text-sm font-medium text-muted-foreground">Added</th>
                        <th className="px-4 py-4 text-right text-sm font-medium text-muted-foreground">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {contacts.map((contact) => (
                        <tr key={contact.id} className="transition-colors hover:bg-muted/30">
                          <td className="px-4 py-4">
                            <Checkbox
                              checked={selectedContacts.includes(contact.id)}
                              onCheckedChange={() => toggleContact(contact.id)}
                            />
                          </td>
                          <td className="px-4 py-4">
                            <div className="flex items-center gap-3">
                              <div className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-sm font-medium text-primary">
                                {contact.name.split(" ").map((n) => n[0]).join("").slice(0, 2)}
                              </div>
                              <div>
                                <p className="font-medium text-foreground">{contact.name}</p>
                                <p className="text-sm text-muted-foreground">{contact.email}</p>
                              </div>
                            </div>
                          </td>
                          <td className="px-4 py-4 text-foreground">{contact.phone}</td>
                          <td className="px-4 py-4">
                            <span className="inline-flex items-center rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground">
                              {contact.group_name || "No Group"}
                            </span>
                          </td>
                          <td className="px-4 py-4">
                            <span className={cn(
                              "status-badge",
                              contact.subscription_status === "subscribed" ? "status-delivered" : "status-failed"
                            )}>
                              {contact.subscription_status === "subscribed" ? "Active" : "Opted Out"}
                            </span>
                          </td>
                          <td className="px-4 py-4 text-muted-foreground">{formatDate(contact.created_at)}</td>
                          <td className="px-4 py-4">
                            <div className="flex items-center justify-end gap-1">
                              <Button variant="ghost" size="icon" onClick={() => handleEditContact(contact)}>
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
              )}
            </div>

            {/* Pagination Controls */}
            {!loading && pagination.total > 0 && (
              <div className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-4">
                  <p className="text-sm text-muted-foreground">
                    Showing {contacts.length > 0 ? ((pagination.page - 1) * pagination.limit) + 1 : 0}-{Math.min(pagination.page * pagination.limit, pagination.total)} of {pagination.total.toLocaleString()} contacts
                  </p>
                  <div className="flex items-center gap-2">
                    <span className="text-sm text-muted-foreground">Per page:</span>
                    <Select 
                      value={pagination.limit.toString()} 
                      onValueChange={(value) => setPagination(prev => ({ ...prev, limit: parseInt(value), page: 1 }))}
                    >
                      <SelectTrigger className="w-20 h-8">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="25">25</SelectItem>
                        <SelectItem value="50">50</SelectItem>
                        <SelectItem value="100">100</SelectItem>
                        <SelectItem value="200">200</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                
                <div className="flex items-center gap-1">
                  {/* First page */}
                  <Button 
                    variant="outline" 
                    size="icon"
                    className="h-8 w-8"
                    disabled={pagination.page <= 1}
                    onClick={() => setPagination(prev => ({ ...prev, page: 1 }))}
                  >
                    <ChevronsLeft className="h-4 w-4" />
                  </Button>
                  
                  {/* Previous page */}
                  <Button 
                    variant="outline" 
                    size="icon"
                    className="h-8 w-8"
                    disabled={pagination.page <= 1}
                    onClick={() => setPagination(prev => ({ ...prev, page: prev.page - 1 }))}
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  
                  {/* Page numbers */}
                  {(() => {
                    const totalPages = Math.ceil(pagination.total / pagination.limit);
                    const currentPage = pagination.page;
                    const pages: (number | string)[] = [];
                    
                    if (totalPages <= 7) {
                      for (let i = 1; i <= totalPages; i++) pages.push(i);
                    } else {
                      pages.push(1);
                      if (currentPage > 3) pages.push('...');
                      for (let i = Math.max(2, currentPage - 1); i <= Math.min(totalPages - 1, currentPage + 1); i++) {
                        pages.push(i);
                      }
                      if (currentPage < totalPages - 2) pages.push('...');
                      pages.push(totalPages);
                    }
                    
                    return pages.map((page, idx) => (
                      typeof page === 'number' ? (
                        <Button
                          key={idx}
                          variant={page === currentPage ? "default" : "outline"}
                          size="icon"
                          className="h-8 w-8"
                          onClick={() => setPagination(prev => ({ ...prev, page }))}
                        >
                          {page}
                        </Button>
                      ) : (
                        <span key={idx} className="px-2 text-muted-foreground">...</span>
                      )
                    ));
                  })()}
                  
                  {/* Next page */}
                  <Button 
                    variant="outline" 
                    size="icon"
                    className="h-8 w-8"
                    disabled={pagination.page * pagination.limit >= pagination.total}
                    onClick={() => setPagination(prev => ({ ...prev, page: prev.page + 1 }))}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                  
                  {/* Last page */}
                  <Button 
                    variant="outline" 
                    size="icon"
                    className="h-8 w-8"
                    disabled={pagination.page * pagination.limit >= pagination.total}
                    onClick={() => setPagination(prev => ({ ...prev, page: Math.ceil(pagination.total / pagination.limit) }))}
                  >
                    <ChevronsRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </div>
        </div>
      </DashboardLayout>

      {/* Delete Group Confirmation Dialog */}
      <AlertDialog open={deleteGroupDialogOpen} onOpenChange={setDeleteGroupDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Group</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{groupToDelete?.name}"? This will remove the group but not the contacts in it.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction 
              onClick={handleDeleteGroup} 
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deletingGroup}
            >
              {deletingGroup && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}