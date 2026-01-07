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
}

interface ParsedContact {
  name?: string;
  phone?: string;
  email?: string;
  group?: string;
}

type ColumnMapping = {
  name: string;
  phone: string;
  email: string;
  group: string;
};

export function ContactImportModal({ open, onOpenChange, onSuccess }: ContactImportModalProps) {
  const [step, setStep] = useState<"upload" | "mapping" | "preview" | "importing">("upload");
  const [file, setFile] = useState<File | null>(null);
  const [csvHeaders, setCsvHeaders] = useState<string[]>([]);
  const [csvData, setCsvData] = useState<string[][]>([]);
  const [columnMapping, setColumnMapping] = useState<ColumnMapping>({
    name: "",
    phone: "",
    email: "",
    group: "",
  });
  const [skipFirstRow, setSkipFirstRow] = useState(true);
  const [skipDuplicates, setSkipDuplicates] = useState(true);
  const [parsedContacts, setParsedContacts] = useState<ParsedContact[]>([]);

  const resetState = () => {
    setStep("upload");
    setFile(null);
    setCsvHeaders([]);
    setCsvData([]);
    setColumnMapping({ name: "", phone: "", email: "", group: "" });
    setParsedContacts([]);
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
      const autoMapping: ColumnMapping = { name: "", phone: "", email: "", group: "" };
      headers.forEach((header) => {
        const lowerHeader = header.toLowerCase();
        if (lowerHeader.includes("name") && !autoMapping.name) autoMapping.name = header;
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
    const contacts: ParsedContact[] = csvData.map((row) => {
      const contact: ParsedContact = {};
      if (columnMapping.name) {
        const idx = csvHeaders.indexOf(columnMapping.name);
        if (idx >= 0) contact.name = row[idx];
      }
      if (columnMapping.phone) {
        const idx = csvHeaders.indexOf(columnMapping.phone);
        if (idx >= 0) contact.phone = row[idx];
      }
      if (columnMapping.email) {
        const idx = csvHeaders.indexOf(columnMapping.email);
        if (idx >= 0) contact.email = row[idx];
      }
      if (columnMapping.group) {
        const idx = csvHeaders.indexOf(columnMapping.group);
        if (idx >= 0) contact.group = row[idx];
      }
      return contact;
    });
    
    setParsedContacts(contacts);
    setStep("preview");
  };

  const handleImport = async () => {
    setStep("importing");
    try {
      // Convert parsed contacts to FormData for upload
      const formData = new FormData();
      if (file) {
        formData.append('file', file);
      }
      formData.append('has_header', skipFirstRow ? 'true' : 'false');
      
      const response = await importContacts(formData);
      if (response.success) {
        toast({
          title: "Import successful",
          description: `${response.data?.imported} contacts imported, ${response.data?.duplicatesSkipped || 0} duplicates skipped.`,
        });
        onSuccess?.();
        handleClose();
      }
    } catch (error) {
      toast({
        title: "Import failed",
        description: "Please check your file and try again.",
        variant: "destructive",
      });
      setStep("preview");
    }
  };

  const validContacts = parsedContacts.filter((c) => c.phone || c.email);

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
          <div
            className={cn(
              "flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-12 transition-colors",
              "border-border hover:border-primary hover:bg-primary/5"
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
                <Select value={columnMapping.name} onValueChange={(v) => setColumnMapping({ ...columnMapping, name: v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Phone Column *</Label>
                <Select value={columnMapping.phone} onValueChange={(v) => setColumnMapping({ ...columnMapping, phone: v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Email Column</Label>
                <Select value={columnMapping.email} onValueChange={(v) => setColumnMapping({ ...columnMapping, email: v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Group Column</Label>
                <Select value={columnMapping.group} onValueChange={(v) => setColumnMapping({ ...columnMapping, group: v })}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select column" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">— None —</SelectItem>
                    {csvHeaders.map((h) => (
                      <SelectItem key={h} value={h}>{h}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
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
            <div className="flex items-center gap-4">
              <div className="flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2">
                <CheckCircle className="h-4 w-4 text-success" />
                <span className="text-sm font-medium text-success">
                  {validContacts.length} valid contacts
                </span>
              </div>
              {parsedContacts.length - validContacts.length > 0 && (
                <div className="flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2">
                  <AlertCircle className="h-4 w-4 text-warning" />
                  <span className="text-sm font-medium text-warning">
                    {parsedContacts.length - validContacts.length} invalid (missing phone/email)
                  </span>
                </div>
              )}
            </div>

            <div className="max-h-64 overflow-auto rounded-lg border border-border">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-muted">
                  <tr>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground">Name</th>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground">Phone</th>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground">Email</th>
                    <th className="px-4 py-2 text-left font-medium text-muted-foreground">Group</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {validContacts.slice(0, 10).map((contact, i) => (
                    <tr key={i}>
                      <td className="px-4 py-2 text-foreground">{contact.name || "—"}</td>
                      <td className="px-4 py-2 text-foreground">{contact.phone || "—"}</td>
                      <td className="px-4 py-2 text-foreground">{contact.email || "—"}</td>
                      <td className="px-4 py-2 text-foreground">{contact.group || "—"}</td>
                    </tr>
                  ))}
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
