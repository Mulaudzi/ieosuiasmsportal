import { useState, useEffect, useCallback, useRef } from "react";
import { Link, useLocation } from "react-router-dom";
import { DashboardLayout } from "@/components/layout/DashboardLayout";
import { PageLoading } from "@/components/ui/page-loading";
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
  Copy,
  ArrowRightLeft,
  Merge,
  Send,
  FolderOpen,
  ChevronDown,
  Square,
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
import { GroupTransferModal } from "@/components/contacts/GroupTransferModal";
import { AddContactModal } from "@/components/contacts/AddContactModal";
import { EditContactModal } from "@/components/contacts/EditContactModal";
import { CreateGroupModal } from "@/components/contacts/CreateGroupModal";
import { ContactGroupSetup } from '@/components/contacts/ContactGroupSetup';
import { EditGroupModal } from "@/components/contacts/EditGroupModal";
import { ContactSummaryCards } from "@/components/contacts/ContactSummaryCards";
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
  is_virtual?: boolean;
}

export default function Contacts() {
  const location = useLocation();
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [groupsLoaded, setGroupsLoaded] = useState(false);
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
  const [groupsToDelete, setGroupsToDelete] = useState<Group[]>([]);
  const [selectedGroupsForDelete, setSelectedGroupsForDelete] = useState<Set<string>>(new Set());
  const [deleteContactsWithGroups, setDeleteContactsWithGroups] = useState(false);
  const [deletingGroups, setDeletingGroups] = useState(false);
  const [transfer, setTransfer] = useState<{mode: 'copy' | 'move' | 'merge'; sourceGroupId?: string; wholeGroup?: boolean; contactIds?: string[]} | null>(null);
  const [totalContacts, setTotalContacts] = useState(0);
  const requestGeneration = useRef(0);
  const [pagination, setPagination] = useState({ page: 1, total: 0, limit: 50 });
  const [sortOrder, setSortOrder] = useState("newest");
  const [optedOutCount, setOptedOutCount] = useState(0);
  const [recentlyAdded, setRecentlyAdded] = useState<number | null>(null);
  const [summaryLoaded, setSummaryLoaded] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  const loadData = useCallback(async () => {
    const generation = ++requestGeneration.current;
    setLoading(true);
    setLoadError(null);
    try {
      const [contactsRes, groupsRes] = await Promise.all([
        getContacts({ 
          group_id: selectedGroup !== "all" ? selectedGroup : undefined,
          search: searchQuery || undefined,
          page: pagination.page,
          per_page: pagination.limit,
          sort: sortOrder,
        }),
        getContactGroups()
      ]);
      if (generation !== requestGeneration.current) return;
      
     // ---------- CONTACTS ----------
if (contactsRes?.success) {
  setSummaryLoaded(true);
  const recent = contactsRes.meta?.recently_added_total;
  setRecentlyAdded(recent !== undefined && Number.isFinite(Number(recent)) ? Number(recent) : null);
  setOptedOutCount(Number((contactsRes.meta as { opted_out_total?: number | string } | undefined)?.opted_out_total ?? 0));
  const contactsData = Array.isArray(contactsRes.data)
    ? contactsRes.data as Contact[]
    : [];

  setContacts(
    contactsData.map((c: Contact) => ({
      ...c,
      id: String(c.id),
      group_id: c.group_id ? String(c.group_id) : "",
      group_name: c.group_name ?? "",
    }))
  );

  setPagination(prev => ({
    ...prev,
    total: parseInt(String(contactsRes.meta?.total ?? contactsData.length), 10)
  }));
}

// ---------- GROUPS ----------
if (groupsRes?.success) {
  setGroupsLoaded(true);
  setTotalContacts(Number(groupsRes.total_contacts ?? groupsRes.data?.total_contacts ?? 0));
  const groupsData = Array.isArray(groupsRes.groups)
    ? (groupsRes.groups as Group[])
    : [];

  setGroups(
    groupsData.map((g: Group) => ({
      ...g,
      id: String(g.id),
      contact_count: parseInt(String(g.contact_count ?? 0), 10)
    }))
  );
}

    } catch (error) {
      if (generation === requestGeneration.current) {setLoadError(error instanceof Error ? error.message : 'Contacts could not be loaded.');handleApiError(error);}
    } finally {
      if (generation === requestGeneration.current) setLoading(false);
    }
  }, [selectedGroup, searchQuery, pagination.page, pagination.limit, sortOrder]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => { setSelectedContacts([]); }, [selectedGroup, searchQuery, pagination.page, pagination.limit, sortOrder]);

  const selectGroup = (id: string) => {
    setSelectedContacts([]);
    setSelectedGroup(id);
    setPagination(previous => ({ ...previous, page: 1 }));
  };
  const realGroups = groups.filter(group => !group.is_virtual && /^[1-9]\d*$/.test(group.id));
  const currentGroup = realGroups.find(group => group.id === selectedGroup);
  const currentGroupName = currentGroup?.name ?? (selectedGroup === 'uncategorized' ? 'Uncategorized' : selectedGroup === 'opted-out' ? 'Opted Out' : 'All Contacts');

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
      await exportContacts(selectedGroup !== "all" ? selectedGroup : undefined);
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

  const handleDeleteGroupConfirm = (group: Group, multiSelect: boolean = false) => {
    if (multiSelect && selectedGroupsForDelete.has(group.id)) {
      const newSelected = new Set(selectedGroupsForDelete);
      newSelected.delete(group.id);
      setSelectedGroupsForDelete(newSelected);
      if (newSelected.size === 0) {
        setDeleteGroupDialogOpen(false);
      }
    } else if (multiSelect) {
      const newSelected = new Set(selectedGroupsForDelete);
      newSelected.add(group.id);
      setSelectedGroupsForDelete(newSelected);
      setGroupsToDelete(groups.filter(g => newSelected.has(g.id)));
      setDeleteGroupDialogOpen(true);
    } else {
      setGroupsToDelete([group]);
      setSelectedGroupsForDelete(new Set([group.id]));
      setDeleteGroupDialogOpen(true);
    }
  };

  const handleDeleteGroups = async () => {
    if (groupsToDelete.length === 0) return;
    setDeletingGroups(true);
    
    const previousGroups = groups;
    const previousSelectedGroup = selectedGroup;
    const idsToDelete = Array.from(selectedGroupsForDelete);
    
    try {
      // Optimistic update
      setGroups(groups.filter(g => !selectedGroupsForDelete.has(g.id)));
      if (selectedGroupsForDelete.has(selectedGroup)) {
        setSelectedGroup("all");
      }
      
      // Delete groups sequentially
      let failed = 0;
      for (const id of idsToDelete) {
        try {
          await deleteContactGroup(id);
        } catch (error) {
          failed++;
        }
      }
      
      const successful = idsToDelete.length - failed;
      if (successful > 0) {
        toast({
          title: `${successful} group${successful !== 1 ? 's' : ''} deleted`,
          description: deleteContactsWithGroups 
            ? `${successful} group${successful !== 1 ? 's' : ''} and their contacts removed.`
            : `${successful} group${successful !== 1 ? 's' : ''} removed.`,
        });
      }
      if (failed > 0) {
        toast({
          title: `${failed} deletion${failed !== 1 ? 's' : ''} failed`,
          description: "Some groups could not be deleted. Please try again.",
          variant: "destructive",
        });
      }
    } catch (error) {
      setGroups(previousGroups);
      setSelectedGroup(previousSelectedGroup);
      handleApiError(error);
    } finally {
      setDeletingGroups(false);
      setDeleteGroupDialogOpen(false);
      setSelectedGroupsForDelete(new Set());
      setGroupsToDelete([]);
      setDeleteContactsWithGroups(false);
    }
  };

  const formatDate = (dateString: string) => {
    try {
      return format(new Date(dateString), "MMM d, yyyy");
    } catch {
      return dateString;
    }
  };

  return (
    <>
      {importModalOpen && <ContactImportModal open onOpenChange={(open) => { setImportModalOpen(open); if (!open) loadData(); }} groups={groups} defaultGroupId={currentGroup?.id} />}
      <AddContactModal open={addContactModalOpen} defaultGroupId={currentGroup?.id} onOpenChange={(open) => { setAddContactModalOpen(open); if (!open) loadData(); }} />
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
      {transfer && <GroupTransferModal mode={transfer.mode} sourceGroupId={transfer.sourceGroupId} wholeGroup={transfer.wholeGroup} groups={realGroups} contactIds={transfer.contactIds ?? selectedContacts}
        onClose={() => setTransfer(null)} onSuccess={targetId => { setSelectedContacts([]); selectGroup(targetId); if (selectedGroup === targetId) loadData(); }} />}
      <DashboardLayout
        title="Contacts"
        subtitle="Manage your contact lists and groups."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline"><Link to="/sms-campaigns/new"><Send className="h-4 w-4"/>Send SMS</Link></Button>
            <Button aria-label="Refresh contacts" variant="outline" onClick={loadData} disabled={loading}>
              <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} />Refresh
            </Button>
            <Button variant="outline" className="gap-2" onClick={() => setImportModalOpen(true)} disabled={loading}>
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
        <div className="mx-auto max-w-[1500px] space-y-5">
        <ContactSummaryCards total={groupsLoaded ? totalContacts : null} groups={groupsLoaded ? realGroups.length : null} optedOut={summaryLoaded ? optedOutCount : null} recentlyAdded={recentlyAdded}/>
        <div className="grid items-start gap-5 lg:grid-cols-[240px_minmax(0,1fr)] 2xl:grid-cols-[280px_minmax(0,1fr)]">
          {/* Groups Sidebar */}
          <aside className="min-w-0" aria-label="Contact groups">
            <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
              <div className="mb-4 flex items-center justify-between">
                <h3 className="font-semibold text-foreground">Groups</h3>
                <Button aria-label="Create group" size="sm" onClick={() => setCreateGroupModalOpen(true)}>
                  <Plus className="mr-1 h-4 w-4" />Create group
                </Button>
              </div>

              <div className="max-h-[28rem] space-y-1 overflow-y-auto">
                <button
                  onClick={() => selectGroup("all")}
                  className={cn(
                    "flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors",
                    selectedGroup === "all"
                      ? "bg-primary/10 font-semibold text-primary"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground"
                  )}
                >
                  <div className="flex items-center gap-2">
                    <Users className="h-4 w-4" />
                    <span>All Contacts</span>
                  </div>
                  <span className="text-xs opacity-80">{totalContacts.toLocaleString()}</span>
                </button>
                
                <button
                  onClick={() => selectGroup("opted-out")}
                  className={cn(
                    "flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors",
                    selectedGroup === "opted-out"
                      ? "bg-primary/10 font-semibold text-primary"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground"
                  )}
                >
                  <div className="flex items-center gap-2">
                    <Ban className="h-4 w-4" />
                    <span>Opted Out</span>
                  </div>
                  <span className="text-xs opacity-80">{optedOutCount}</span>
                </button>

                {groups.map((group) => (
                  <div
                    key={group.id}
                    className={cn(
                      "group/item flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors",
                      selectedGroup === group.id
                        ? "bg-primary/10 font-semibold text-primary"
                        : "text-muted-foreground hover:bg-muted hover:text-foreground"
                    )}
                  >
                    <button
                      onClick={() => selectGroup(group.id)}
                      className="flex min-w-0 flex-1 items-center gap-2 text-left"
                    >
                      <Users className="h-4 w-4" />
                      <span className="min-w-0 break-words">{group.name}</span>
                    </button>
                    <div className="flex items-center gap-1">
                      <span className="text-xs opacity-80">{(group.contact_count || 0).toLocaleString()}</span>
                      {!group.is_virtual && <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon"
                            className={cn(
                              "h-8 w-8 opacity-100",
                              selectedGroup === group.id ? "hover:bg-primary-foreground/20" : ""
                            )}
                            onClick={(e) => e.stopPropagation()}
                            aria-label={`Actions for ${group.name}`}
                          >
                            <MoreVertical className="h-3 w-3 text-muted-foreground" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="bg-popover">
                          <DropdownMenuItem onClick={() => setTransfer({mode: 'copy', sourceGroupId: group.id, wholeGroup: true})}><Copy className="mr-2 h-4 w-4" />Copy entire group</DropdownMenuItem>
                          <DropdownMenuItem onClick={() => setTransfer({mode: 'move', sourceGroupId: group.id, wholeGroup: true})}><ArrowRightLeft className="mr-2 h-4 w-4" />Move entire group</DropdownMenuItem>
                          <DropdownMenuItem disabled={realGroups.length < 2} onClick={() => setTransfer({mode: 'merge', sourceGroupId: group.id})}>
                            <Merge className="mr-2 h-4 w-4" />Merge into another group
                          </DropdownMenuItem>
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
                      </DropdownMenu>}
                    </div>
                  </div>
                ))}


              </div>
              {!realGroups.length && groupsLoaded && <div className="mt-4 border-t pt-5 text-center"><FolderOpen className="mx-auto h-8 w-8 text-muted-foreground/60" aria-hidden="true"/><h4 className="mt-3 text-sm font-semibold">No groups yet</h4><p className="mt-1 text-xs leading-relaxed text-muted-foreground">Create groups to organise your contacts and send SMS to the right people.</p></div>}
              <div className="mt-4 border-t pt-4 [&_button]:h-auto [&_button]:min-h-10 [&_button]:max-w-full [&_button]:whitespace-normal"><ContactGroupSetup groups={groups} loaded={groupsLoaded} autoPrompt={false} onRefresh={loadData} onReady={id => selectGroup(id)}/></div>
            </div>
          </aside>

          {/* Contacts List */}
          <section className="min-w-0 rounded-2xl border bg-card p-4 shadow-sm sm:p-5" aria-label="Contacts list">
            <div className="mb-4 space-y-1">
              <h2 className="font-semibold text-foreground">{currentGroupName}</h2>
              <p className="text-sm text-muted-foreground">{currentGroup ? `New contacts and imports default to ${currentGroup.name}. Select contacts below to copy or move them.` : 'Select the checkboxes next to contacts, then choose Add to group, Copy or Move. Groups make it easy to send an SMS to the right people.'}</p>
              {currentGroup && <div className="flex flex-wrap gap-2 pt-2"><Button asChild><Link to={`/sms-campaigns/new?group=${currentGroup.id}`}>Send SMS to this group</Link></Button><Button variant="outline" onClick={() => setTransfer({mode:'copy',sourceGroupId:currentGroup.id,wholeGroup:true})}>Copy entire group</Button><Button variant="outline" onClick={() => setTransfer({mode:'move',sourceGroupId:currentGroup.id,wholeGroup:true})}>Move entire group</Button></div>}
            </div>
            {Number(groups.find(group => group.id === 'uncategorized')?.contact_count) > 0 && (selectedGroup === 'all' || selectedGroup === 'uncategorized') && <section aria-label="Organise uncategorized contacts" className="mb-4 space-y-3 rounded-lg border border-primary/30 bg-primary/5 p-4"><h3 className="font-semibold">Add your uncategorized contacts to a group</h3><p className="text-sm">{groups.find(group => group.id === 'uncategorized')?.contact_count.toLocaleString()} contacts have no group. Choose an existing group or create one, such as Customers or Church members.</p><div className="flex flex-wrap gap-2"><Button className="h-auto min-h-10 whitespace-normal" onClick={() => setTransfer({mode:'copy',sourceGroupId:'uncategorized',wholeGroup:true})}>Add uncategorized contacts to a group</Button>{selectedGroup !== 'uncategorized' && <Button variant="outline" onClick={() => selectGroup('uncategorized')}>Choose individual contacts</Button>}</div><p className="text-xs text-muted-foreground">The first action includes all uncategorized contacts, including other pages. To organise only some contacts, choose them individually.</p></section>}
            {/* Filters */}
            <div className="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
              <div className="relative min-w-0 flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  aria-label="Search contacts" placeholder="Search contacts..."
                  value={searchQuery}
                  onChange={(e) => { setSearchQuery(e.target.value); setPagination(previous => ({...previous, page: 1})); }}
                  className="pl-10"
                />
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Select value={sortOrder} onValueChange={value => {setSortOrder(value);setPagination(previous => ({...previous,page:1}));}}>
                  <SelectTrigger aria-label="Sort contacts" className="w-[140px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="newest">Sort: Newest</SelectItem>
                    <SelectItem value="oldest">Sort: Oldest</SelectItem>
                    <SelectItem value="name">Sort: Name</SelectItem>
                  </SelectContent>
                </Select>
                <Select value={selectedGroup} onValueChange={selectGroup}><SelectTrigger aria-label="Filter contacts" className="w-[150px]"><SelectValue placeholder="Filter contacts"/></SelectTrigger><SelectContent><SelectItem value="all">Filter: All</SelectItem><SelectItem value="opted-out">Opted Out</SelectItem><SelectItem value="uncategorized">Uncategorized</SelectItem>{realGroups.map(group => <SelectItem key={group.id} value={group.id}>{group.name}</SelectItem>)}</SelectContent></Select>
                <DropdownMenu><DropdownMenuTrigger asChild><Button variant="outline" disabled={selectedContacts.length===0 || !!loadingAction} className="gap-2"><Square className="h-4 w-4" aria-hidden="true"/>Bulk actions<ChevronDown className="h-4 w-4"/></Button></DropdownMenuTrigger><DropdownMenuContent align="end"><DropdownMenuItem onClick={() => setTransfer({mode:'copy',sourceGroupId:selectedGroup==='uncategorized'?'uncategorized':currentGroup?.id})}><Copy className="mr-2 h-4 w-4"/>Add / copy to group</DropdownMenuItem><DropdownMenuItem onClick={() => setTransfer({mode:'move',sourceGroupId:selectedGroup==='uncategorized'?'uncategorized':currentGroup?.id})} disabled={!currentGroup && selectedGroup!=='uncategorized' && !realGroups.length}><ArrowRightLeft className="mr-2 h-4 w-4"/>Move to group</DropdownMenuItem><DropdownMenuItem onClick={() => void handleExport()}><Download className="mr-2 h-4 w-4"/>Export current contact list</DropdownMenuItem><DropdownMenuItem onClick={() => void handleBulkDelete()} className="text-destructive"><Trash2 className="mr-2 h-4 w-4"/>Delete selected</DropdownMenuItem></DropdownMenuContent></DropdownMenu>
              </div>
            </div>

            {/* Selected Actions */}
            {selectedContacts.length > 0 && (
              <div className="mb-4 flex flex-wrap items-center gap-4 rounded-lg bg-primary/10 p-3">
                <span className="text-sm font-medium text-primary">
                  {selectedContacts.length} selected on this page
                </span>
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" size="sm" onClick={() => setTransfer({mode: 'copy', sourceGroupId: selectedGroup === 'uncategorized' ? 'uncategorized' : currentGroup?.id})}>
                    <Copy className="mr-1 h-4 w-4" />{selectedGroup === 'uncategorized' || selectedGroup === 'all' ? 'Add to group' : 'Copy to Group'}
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => setTransfer({mode: 'move', sourceGroupId: selectedGroup === 'uncategorized' ? 'uncategorized' : currentGroup?.id})} disabled={!currentGroup && selectedGroup !== 'uncategorized' && !realGroups.length}>
                    <ArrowRightLeft className="mr-1 h-4 w-4" />Move to Group
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
                <PageLoading variant="table" label="Loading contacts" className="p-4" />
              ) : loadError ? (
                <div role="alert" className="flex min-h-64 flex-col items-center justify-center gap-3 p-6 text-center"><p className="font-semibold">Contacts could not be loaded</p><p className="max-w-md text-sm text-muted-foreground">{loadError}</p><Button variant="outline" onClick={() => void loadData()}><RefreshCw className="h-4 w-4"/>Try again</Button></div>
              ) : contacts.length === 0 ? (
                <div className="flex min-h-[350px] flex-col items-center justify-center px-5 py-10 text-center sm:min-h-[390px]">
                  <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-primary/5"><div className="rounded-2xl border border-primary/15 bg-primary/10 p-4 text-primary"><Users className="h-10 w-10" aria-hidden="true"/></div><Send className="absolute -right-2 top-0 h-7 w-7 -rotate-12 text-sky-400" aria-hidden="true"/></div>
                  <h3 className="mt-5 text-xl font-bold">{totalContacts===0 ? 'No contacts yet' : searchQuery ? 'No matching contacts' : currentGroup ? 'No contacts in this group' : 'No contacts match this filter'}</h3>
                  <p className="mt-2 max-w-lg text-sm leading-relaxed text-muted-foreground">{totalContacts===0 ? 'Add contacts to start building your audience and sending SMS campaigns.' : searchQuery ? 'Try another name, phone number or email, or change your filters.' : 'Add or import contacts, or choose another group to see your audience.'}</p>
                  <div className="mt-6 flex flex-wrap justify-center gap-2"><Button onClick={() => setAddContactModalOpen(true)}><UserPlus className="h-4 w-4"/>Add Contact</Button><Button variant="outline" onClick={() => setImportModalOpen(true)}><Upload className="h-4 w-4"/>Import Contacts</Button><Button variant="outline" onClick={() => setCreateGroupModalOpen(true)}><Plus className="h-4 w-4"/>Create Group</Button></div>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[780px] text-[13px]">
                    <thead>
                      <tr className="border-b border-border bg-muted/50">
                        <th className="px-3 py-3 text-left">
                          <Checkbox
                            aria-label="Select all contacts on this page"
                            checked={selectedContacts.length === contacts.length && contacts.length > 0 ? true : selectedContacts.length > 0 ? "indeterminate" : false}
                            onCheckedChange={toggleAll}
                          />
                        </th>
                        <th className="px-3 py-3 text-left text-sm font-medium text-muted-foreground">Name</th>
                        <th className="px-3 py-3 text-left text-sm font-medium text-muted-foreground">Phone number</th>
                        <th className="px-3 py-3 text-left text-sm font-medium text-muted-foreground">Groups</th>
                        <th className="px-3 py-3 text-left text-sm font-medium text-muted-foreground">Status</th>
                        <th className="px-3 py-3 text-left text-sm font-medium text-muted-foreground">Date added</th>
                        <th className="px-3 py-3 text-right text-sm font-medium text-muted-foreground">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {contacts.map((contact) => (
                        <tr key={contact.id} className="transition-colors hover:bg-muted/30">
                          <td className="px-3 py-3">
                            <Checkbox
                              aria-label={`Select ${contact.name || contact.phone}`}
                              checked={selectedContacts.includes(contact.id)}
                              onCheckedChange={() => toggleContact(contact.id)}
                            />
                          </td>
                          <td className="px-3 py-3">
                            <div className="flex items-center gap-3">
                              <div className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-sm font-medium text-primary">
                                {(contact.name || contact.phone || "?").split(" ").map((n) => n[0]).join("").slice(0, 2)}
                              </div>
                              <div>
                                <p className="font-medium text-foreground">{contact.name || "Unnamed contact"}</p>
                                <p className="max-w-[160px] truncate text-xs text-muted-foreground" title={contact.email}>{contact.email}</p>
                              </div>
                            </div>
                          </td>
                          <td className="px-3 py-3 text-foreground">{contact.phone}</td>
                          <td className="px-3 py-3">
                            {!contact.group_id && <Button variant="link" size="sm" onClick={() => setTransfer({mode:'copy',sourceGroupId:'uncategorized',contactIds:[contact.id]})}>Add to group</Button>}
                            <span className="inline-flex items-center rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground">
                              {contact.group_name || "No Group"}
                            </span>
                          </td>
                          <td className="px-3 py-3">
                            <span className={cn(
                              "status-badge",
                              contact.subscription_status === "subscribed" ? "status-delivered" : "status-failed"
                            )}>
                              {contact.subscription_status === "subscribed" ? "Active" : "Opted Out"}
                            </span>
                          </td>
                          <td className="whitespace-nowrap px-3 py-3 text-xs text-muted-foreground">{formatDate(contact.created_at)}</td>
                          <td className="px-3 py-3">
                            <div className="flex items-center justify-end gap-1">
                              <Button aria-label="Edit contact" variant="ghost" size="icon" onClick={() => handleEditContact(contact)}>
                                <Edit className="h-4 w-4" />
                              </Button>
                              <Button 
                                variant="ghost" 
                                size="icon" 
                                aria-label={`Delete ${contact.name || contact.phone}`}
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
          </section>
        </div>
        </div>
      </DashboardLayout>

      {/* Delete Group Confirmation Dialog */}
      <Dialog open={deleteGroupDialogOpen} onOpenChange={setDeleteGroupDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete {groupsToDelete.length === 1 ? 'Group' : 'Groups'}</DialogTitle>
            <DialogDescription>
              {groupsToDelete.length === 1 
                ? `You are about to delete "${groupsToDelete[0].name}".`
                : `You are about to delete ${groupsToDelete.length} groups.`
              }
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {groupsToDelete.length > 1 && (
              <div className="max-h-48 overflow-y-auto rounded-lg border border-border bg-muted/50 p-3">
                <p className="mb-2 text-sm font-medium">Groups to delete:</p>
                <ul className="space-y-1">
                  {groupsToDelete.map((g) => (
                    <li key={g.id} className="text-sm text-muted-foreground">
                      • {g.name} ({g.contact_count} contacts)
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="rounded-lg border border-border bg-muted/50 p-3">
              <p className="mb-3 text-sm font-medium">What would you like to do with the contacts in these groups?</p>
              <div className="space-y-2">
                <button
                  onClick={() => setDeleteContactsWithGroups(false)}
                  className={cn(
                    "flex w-full items-center gap-3 rounded-lg p-3 text-left transition-colors",
                    !deleteContactsWithGroups
                      ? "bg-primary/10 border border-primary"
                      : "border border-transparent hover:bg-muted"
                  )}
                >
                  <div className={cn(
                    "h-4 w-4 rounded border flex items-center justify-center",
                    !deleteContactsWithGroups ? "bg-primary border-primary" : "border-muted-foreground"
                  )}>
                    {!deleteContactsWithGroups && <div className="h-2 w-2 bg-white rounded-sm" />}
                  </div>
                  <div>
                    <p className="text-sm font-medium">Keep contacts</p>
                    <p className="text-xs text-muted-foreground">Contacts will remain in your database</p>
                  </div>
                </button>

                <button
                  onClick={() => setDeleteContactsWithGroups(true)}
                  className={cn(
                    "flex w-full items-center gap-3 rounded-lg p-3 text-left transition-colors",
                    deleteContactsWithGroups
                      ? "bg-destructive/10 border border-destructive"
                      : "border border-transparent hover:bg-muted"
                  )}
                >
                  <div className={cn(
                    "h-4 w-4 rounded border flex items-center justify-center",
                    deleteContactsWithGroups ? "bg-destructive border-destructive" : "border-muted-foreground"
                  )}>
                    {deleteContactsWithGroups && <div className="h-2 w-2 bg-white rounded-sm" />}
                  </div>
                  <div>
                    <p className="text-sm font-medium text-destructive">Delete contacts too</p>
                    <p className="text-xs text-muted-foreground">Contacts will be permanently removed</p>
                  </div>
                </button>
              </div>
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => setDeleteGroupDialogOpen(false)}
              disabled={deletingGroups}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDeleteGroups}
              disabled={deletingGroups}
            >
              {deletingGroups && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Delete {groupsToDelete.length === 1 ? 'Group' : `${groupsToDelete.length} Groups`}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
