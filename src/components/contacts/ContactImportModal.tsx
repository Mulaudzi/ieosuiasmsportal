import { useState, useCallback } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Upload, FileText, AlertCircle, CheckCircle, Loader2, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { importContacts } from "@/lib/api";
import { toast } from "@/hooks/use-toast";

interface ContactImportModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
  groups?: Array<{ id: string | number; name: string; is_virtual?: boolean }>;
}

interface ParsedContact {
  name?: string;
  phone?: string;
  email?: string;
  group?: string;
}

type ColumnMapping = {
  name: string;  // Can be full name or first name
  surname?: string;  // Optional separate surname column
  phone: string;
  email: string;
  group: string;
};

export function ContactImportModal({ open, onOpenChange, onSuccess, groups = [] }: ContactImportModalProps) {
  const [step, setStep] = useState<"upload" | "mapping" | "preview" | "importing">("upload");
  const [file, setFile] = useState<File | null>(null);
  const [csvHeaders, setCsvHeaders] = useState<string[]>([]);
  const [csvData, setCsvData] = useState<string[][]>([]);
  const [columnMapping, setColumnMapping] = useState<ColumnMapping>({
    name: "",
    surname: "",
    phone: "",
    email: "",
    group: "",
  });
  const [skipFirstRow, setSkipFirstRow] = useState(true);
  const [skipDuplicates, setSkipDuplicates] = useState(true);
  const [selectedGroupId, setSelectedGroupId] = useState<string>("");  // Existing group to assign contacts to
  const [parsedContacts, setParsedContacts] = useState<ParsedContact[]>([]);

  const resetState = () => {
    setStep("upload");
    setFile(null);
    setCsvHeaders([]);
    setCsvData([]);
    setColumnMapping({ name: "", surname: "", phone: "", email: "", group: "" });
    setParsedContacts([]);
    setSelectedGroupId("");
  };

  const handleClose = () => {
    resetState();
    onOpenChange(false);
  };

  const parseCSV = (text: string): { headers: string[]; data: string[][] } => {
    const lines = text.split("\n").filter((line) => line.trim());
    const headers = lines[0].split(",").map((h) => h.trim().replace(/"/g, ""));
    const data = lines.slice(1).map((line) => 
      line.split(",").map((cell) => cell.trim().replace(/"/g, ""))
    );
    return { headers, data };
  };

  const handleFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0];
    if (!selectedFile) return;

    if (!selectedFile.name.endsWith(".csv")) {
      toast({
        title: "Invalid file type",
        description: "Please upload a CSV file.",
        variant: "destructive",
      });
      return;
    }

    setFile(selectedFile);
    
    const reader = new FileReader();
    reader.onload = (event) => {
      const text = event.target?.result as string;
      const { headers, data } = parseCSV(text);
      setCsvHeaders(headers);
      setCsvData(data);
      
      // Auto-map common column names
      const autoMapping: ColumnMapping = { name: "", surname: "", phone: "", email: "", group: "" };
      headers.forEach((header) => {
        const lowerHeader = header.toLowerCase();
        if ((lowerHeader.includes("surname") || lowerHeader.includes("last_name") || lowerHeader.includes("lastname")) && !autoMapping.surname) {
          autoMapping.surname = header;
        } else if ((lowerHeader.includes("name") || lowerHeader.includes("first_name") || lowerHeader.includes("firstname")) && !autoMapping.name) {
          autoMapping.name = header;
        }
        if ((lowerHeader.includes("phone") || lowerHeader.includes("mobile") || lowerHeader.includes("cell")) && !autoMapping.phone) autoMapping.phone = header;
        if (lowerHeader.includes("email") && !autoMapping.email) autoMapping.email = header;
        if ((lowerHeader.includes("group") || lowerHeader.includes("category")) && !autoMapping.group) autoMapping.group = header;
      });
      setColumnMapping(autoMapping);
      setStep("mapping");
    };
    reader.readAsText(selectedFile);
  }, []);

  const handleFileDrop = useCallback((e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    const droppedFile = e.dataTransfer.files[0];
    if (droppedFile) {
      const fakeEvent = { target: { files: [droppedFile] } } as unknown as React.ChangeEvent<HTMLInputElement>;
      handleFileChange(fakeEvent);
    }
  }, [handleFileChange]);

  const proceedToPreview = () => {
    // Validate that phone column is selected (phone is required for all contacts)
    if (!columnMapping.phone) {
      toast({
        title: "Phone column required",
        description: "Please select which column contains the phone number.",
        variant: "destructive",
      });
      return;
    }

    const contacts: ParsedContact[] = csvData.map((row) => {
      const contact: ParsedContact = {};
      
      // Handle name mapping - can be full name or first name
      let fullName = "";
      if (columnMapping.name) {
        const idx = csvHeaders.indexOf(columnMapping.name);
        if (idx >= 0) fullName = row[idx]?.trim() || "";
      }
      
      // Handle surname mapping - can be combined with name
      if (columnMapping.surname) {
        const idx = csvHeaders.indexOf(columnMapping.surname);
        const surname = row[idx]?.trim() || "";
        if (fullName && surname) {
          contact.name = fullName + " " + surname;
        } else if (fullName) {
          contact.name = fullName;
        } else if (surname) {
          contact.name = surname;
        }
      } else if (fullName) {
        contact.name = fullName;
      }
      
      if (columnMapping.phone) {
        const idx = csvHeaders.indexOf(columnMapping.phone);
        if (idx >= 0) contact.phone = row[idx]?.trim();
      }
      if (columnMapping.email) {
        const idx = csvHeaders.indexOf(columnMapping.email);
        if (idx >= 0) contact.email = row[idx]?.trim();
      }
      if (columnMapping.group) {
        const idx = csvHeaders.indexOf(columnMapping.group);
        if (idx >= 0) contact.group = row[idx]?.trim();
      }
      return contact;
    });
    
    setParsedContacts(contacts);
    setStep("preview");
  };

  const handleImport = async () => {
    if (!file) {
      toast({
        title: "No file selected",
        description: "Please select a file to import.",
        variant: "destructive",
      });
      return;
    }
    
    setStep("importing");
    try {
      // Create FormData for upload with column mappings
      const formData = new FormData();
      formData.append('file', file);
      formData.append('column_mapping', JSON.stringify({
        name: columnMapping.name,
        surname: columnMapping.surname,
        phone: columnMapping.phone,
        email: columnMapping.email,
        group: columnMapping.group,
      }));
      formData.append('skip_duplicates', skipDuplicates.toString());
      if (selectedGroupId) {
        formData.append('group_id', selectedGroupId);
      }
      
      const response = await importContacts(formData);
      
      if (!response) {
        throw new Error("No response from server");
      }
      
      if (response.success) {
        toast({
          title: "Import successful",
          description: `${response.data?.imported || 0} contacts imported${response.data?.duplicates ? `, ${response.data.duplicates} duplicates skipped` : ''}${response.data?.failed ? `, ${response.data.failed} failed` : ''}.`,
        });
        onSuccess?.();
        handleClose();
      } else {
        throw new Error(response.error || response.message || "Import failed");
      }
    } catch (error) {
      console.error("Import error:", error);
      const errorMessage = 
        error instanceof Error 
          ? error.message 
          : error instanceof TypeError && error.message?.includes("fetch")
          ? "Network error - unable to connect to the server. Check your internet connection or contact support."
          : "Please check your file and try again.";
      toast({
        title: "Import failed",
        description: errorMessage,
        variant: "destructive",
      });
      setStep("preview");
    }
  };

  const validContacts = parsedContacts.filter((c) => {
    // Phone is required (mandatory field)
    if (!c.phone) return false;
    // Name and email are optional
    return true;
  });

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Contacts</DialogTitle>
          <DialogDescription>
            {step === "upload" && "Upload a CSV file to import contacts"}
            {step === "mapping" && "Map your CSV columns to contact fields"}
            {step === "preview" && "Review contacts before importing"}
            {step === "importing" && "Importing your contacts..."}
          </DialogDescription>
        </DialogHeader>

        {/* Upload Step */}
        {step === "upload" && (
          <div className="space-y-6">
            {/* CSV Format Instructions */}
            <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
              <div className="mb-3 flex items-start gap-3">
                <AlertCircle className="h-5 w-5 text-blue-600 mt-0.5 flex-shrink-0" />
                <div>
                  <h4 className="font-semibold text-blue-900 mb-2">CSV Format Guide</h4>
                  <div className="text-sm text-blue-800 space-y-2">
                    <p><strong>Required:</strong> Phone</p>
                    <p><strong>Optional:</strong> Full Name, Email, Group</p>
                    <p className="mt-3"><strong>Example CSV:</strong></p>
                    <code className="block bg-white p-2 rounded border border-blue-200 font-mono text-xs mt-1">
                      Full Name,Phone,Email,Group<br/>
                      John Doe,+27791234567,john@example.com,VIP<br/>
                      Jane Smith,+27987654321,jane@example.com,Customers<br/>
                      ,+27555111111,bob@example.com,
                    </code>
                    <div className="mt-3 space-y-1 text-xs">
                      <p>✓ Phone: Required - must include country code (e.g., +27, +1, +44)</p>
                      <p>✓ Full Name: Optional - can be left empty</p>
                      <p>✓ Email: Optional - at least phone OR email recommended</p>
                      <p>✓ Group: Optional - contact will have no group if left empty</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              className={cn(
                "flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-12 transition-colors",
                "border-border hover:border-primary hover:bg-primary/5 cursor-pointer"
              )}
              onDragOver={(e) => e.preventDefault()}
              onDrop={handleFileDrop}
            >
              <Upload className="h-12 w-12 text-muted-foreground" />
              <p className="mt-4 text-lg font-medium text-foreground">
                Drag & drop your CSV file here
              </p>
              <p className="mt-1 text-sm text-muted-foreground">or click to browse</p>
              <input
                type="file"
                accept=".csv"
                onChange={handleFileChange}
                className="absolute inset-0 cursor-pointer opacity-0"
              />
              <Button variant="outline" className="mt-4">
                Select File
              </Button>
            </div>
          </div>
        )}

        {/* Mapping Step */}
        {step === "mapping" && (
          <div className="space-y-6">
            <div className="flex items-center gap-3 rounded-lg bg-muted/50 p-3">
              <FileText className="h-5 w-5 text-primary" />
              <div className="flex-1">
                <p className="font-medium text-foreground">{file?.name}</p>
                <p className="text-sm text-muted-foreground">
                  {csvData.length} rows found
                </p>
              </div>
              <Button variant="ghost" size="icon" onClick={resetState}>
                <X className="h-4 w-4" />
              </Button>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>Name Column</Label>
                <Select value={columnMapping.name || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, name: v === "__none__" ? "" : v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">First name or full name</p>
              </div>

              <div className="space-y-2">
                <Label>Surname Column</Label>
                <Select value={columnMapping.surname || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, surname: v === "__none__" ? "" : v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">Optional - combine with Name</p>
              </div>

              <div className="space-y-2">
                <Label>Phone Column *</Label>
                <Select value={columnMapping.phone || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, phone: v === "__none__" ? "" : v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Email Column</Label>
                <Select value={columnMapping.email || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, email: v === "__none__" ? "" : v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Group Column</Label>
                <Select value={columnMapping.group || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, group: v === "__none__" ? "" : v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">From CSV columns for dynamic group assignment</p>
              </div>
            </div>

            <div className="space-y-3 pt-4 border-t">
              <Label>Assign to Existing Group (Optional)</Label>
              <Select value={selectedGroupId} onValueChange={setSelectedGroupId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a group..." />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__empty__">— No group (or use CSV column) —</SelectItem>
                  {groups
                    .filter(g => !g.is_virtual)  // Filter out virtual groups
                    .map((group) => (
                      <SelectItem key={String(group.id)} value={String(group.id)}>
                        {group.name}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Assign all imported contacts to this group. If you also select a Group Column above, the CSV column will take priority.
              </p>
            </div>

            <div className="space-y-3">
              <div className="flex items-center gap-2">
                <Checkbox
                  id="skipFirst"
                  checked={skipFirstRow}
                  onCheckedChange={(c) => setSkipFirstRow(!!c)}
                />
                <Label htmlFor="skipFirst">First row contains headers</Label>
              </div>
              <div className="flex items-center gap-2">
                <Checkbox
                  id="skipDupes"
                  checked={skipDuplicates}
                  onCheckedChange={(c) => setSkipDuplicates(!!c)}
                />
                <Label htmlFor="skipDupes">Skip duplicate phone numbers</Label>
              </div>
            </div>
          </div>
        )}

        {/* Preview Step */}
        {step === "preview" && (
          <div className="space-y-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
              <div className="flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2">
                <CheckCircle className="h-4 w-4 text-success" />
                <span className="text-sm font-medium text-success">
                  {validContacts.length} valid contacts
                </span>
              </div>
              {parsedContacts.length - validContacts.length > 0 && (
                <div className="flex items-center gap-2 rounded-lg bg-destructive/10 px-3 py-2">
                  <AlertCircle className="h-4 w-4 text-destructive" />
                  <span className="text-sm font-medium text-destructive">
                    {parsedContacts.length - validContacts.length} invalid rows
                  </span>
                </div>
              )}
            </div>

            {/* Validation message */}
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
              <p className="text-xs text-amber-800">
                <strong>Validation Rules:</strong> Full Name required • Phone or Email required • Names must include both first and last name
              </p>
            </div>

            <div className="max-h-64 overflow-auto rounded-lg border border-border">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-muted">
                  <tr>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground w-8">✓</th>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground">Name</th>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground">Phone</th>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground">Email</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {parsedContacts.slice(0, 10).map((contact, i) => {
                    const isValid = validContacts.includes(contact);
                    return (
                      <tr key={i} className={isValid ? "" : "bg-destructive/5"}>
                        <td className="px-4 py-2">
                          {isValid ? (
                            <CheckCircle className="h-4 w-4 text-success" />
                          ) : (
                            <X className="h-4 w-4 text-destructive" />
                          )}
                        </td>
                        <td className={cn("px-4 py-2", isValid ? "text-foreground" : "text-destructive")}>
                          {contact.name || "—"}
                        </td>
                        <td className="px-4 py-2 text-foreground">{contact.phone || "—"}</td>
                        <td className="px-4 py-2 text-foreground">{contact.email || "—"}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {validContacts.length > 10 && (
              <p className="text-sm text-muted-foreground">
                Showing first 10 of {validContacts.length} contacts
              </p>
            )}
          </div>
        )}

        {/* Importing Step */}
        {step === "importing" && (
          <div className="flex flex-col items-center justify-center py-12">
            <Loader2 className="h-12 w-12 animate-spin text-primary" />
            <p className="mt-4 text-lg font-medium text-foreground">Importing contacts...</p>
            <p className="text-sm text-muted-foreground">This may take a moment</p>
          </div>
        )}

        <DialogFooter>
          {step === "upload" && (
            <Button variant="outline" onClick={handleClose}>
              Cancel
            </Button>
          )}
          {step === "mapping" && (
            <>
              <Button variant="outline" onClick={resetState}>
                Back
              </Button>
              <Button onClick={proceedToPreview} disabled={!columnMapping.phone && !columnMapping.email}>
                Continue
              </Button>
            </>
          )}
          {step === "preview" && (
            <>
              <Button variant="outline" onClick={() => setStep("mapping")}>
                Back
              </Button>
              <Button onClick={handleImport} disabled={validContacts.length === 0}>
                Import {validContacts.length} Contacts
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
