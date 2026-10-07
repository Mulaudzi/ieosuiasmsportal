import { useState, useCallback, useRef } from "react";
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
import { parseContactCsv, prepareContactSheet, contactSheetCsv, ImportSheet } from "@/lib/contact-import-file";
import { importContacts } from "@/lib/api";
import { contactImportResult, ContactImportResult } from "@/lib/contact-import-result";
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
  const [step, setStep] = useState<"upload" | "mapping" | "preview" | "importing" | "result" | "reading" | "sheet">("upload");
  const [result,setResult]=useState<ContactImportResult|null>(null);
  const readGeneration=useRef(0);
  const [sheets,setSheets]=useState<ImportSheet[]>([]);
  const [sheetIndex,setSheetIndex]=useState("0");
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
  const skipFirstRow = true;
  const [skipDuplicates, setSkipDuplicates] = useState(true);
  const [selectedGroupId, setSelectedGroupId] = useState<string>("");  // Existing group to assign contacts to
  const [parsedContacts, setParsedContacts] = useState<ParsedContact[]>([]);

  const resetState = () => {
    readGeneration.current++;
    setSheets([]);setSheetIndex("0");
    setStep("upload");
    setResult(null);
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

  const applySheet=(rows:unknown[][])=>{
    const {headers,data}=prepareContactSheet(rows);
    setCsvHeaders(headers);setCsvData(data);
    const mapping:ColumnMapping={name:"",surname:"",phone:"",email:"",group:""};
    headers.forEach(header=>{const h=header.toLowerCase();
      if(/surname|last.?name/.test(h))mapping.surname=header;
      else if(h.includes("name")&&!mapping.name)mapping.name=header;
      if(/phone|mobile|cell|contact/.test(h)&&!mapping.phone)mapping.phone=header;
      if(h.includes("email"))mapping.email=header;
    });
    setColumnMapping(mapping);setParsedContacts([]);setStep("mapping");
  };

  const handleFileChange = useCallback(async (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile=e.target.files?.[0];if(!selectedFile)return;
    e.target.value="";
    const generation=++readGeneration.current;
    if(!/\.(csv|xlsx)$/i.test(selectedFile.name)){toast({title:"Unsupported file type",description:"Upload CSV or Excel .xlsx. For older .xls files, use Excel → Save As → Excel Workbook (.xlsx).",variant:"destructive"});return;}
    if(selectedFile.size>5*1024*1024){toast({title:"File too large",description:"Use a file under 5 MB, with at most 10,000 contacts per sheet.",variant:"destructive"});return;}
    setFile(selectedFile);setStep("reading");
    try{
      if(/\.csv$/i.test(selectedFile.name)){
        const rows=parseContactCsv(await selectedFile.text());
        if(generation===readGeneration.current)applySheet(rows);
      }else{
        const buffer=await selectedFile.arrayBuffer();
        if(generation!==readGeneration.current)return;
        const workbook=await new Promise<ImportSheet[]>((resolve,reject)=>{
          const worker=new Worker(new URL("../../lib/contact-excel.worker.ts",import.meta.url),{type:"module"});
          const timeout=window.setTimeout(()=>{worker.terminate();reject(new Error("Workbook took too long to read. Remove unused sheets and try a smaller file."));},15000);
          const done=()=>{window.clearTimeout(timeout);worker.terminate();};
          worker.onmessage=e=>{done();if(e.data.error)reject(new Error("Unable to read this workbook. Remove password protection, save as .xlsx and try again."));else resolve(e.data.sheets);};
          worker.onerror=()=>{done();reject(new Error("Unable to read Excel. Please try a smaller .xlsx file or CSV."));};
          worker.postMessage(buffer,[buffer]);
        });
        if(generation!==readGeneration.current)return;
        if(!workbook.length)throw new Error("The workbook contains no worksheets.");
        setSheets(workbook);setSheetIndex("0");setStep("sheet");
      }
    }catch(error){if(generation!==readGeneration.current)return;setStep("upload");setFile(null);toast({title:"Cannot read contact file",description:error instanceof Error?error.message:"Check the file and try again.",variant:"destructive"});}
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
      const upload=new File([contactSheetCsv(csvHeaders,csvData)],'contacts.csv',{type:'text/csv'});
      if(upload.size>5*1024*1024)throw new Error('The selected sheet exceeds 5 MB as contact data. Split it into smaller files.');
      formData.append('file', upload);
      formData.append('column_mapping', JSON.stringify({
        name: columnMapping.name,
        surname: columnMapping.surname,
        phone: columnMapping.phone,
        email: columnMapping.email,
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
        const counts=contactImportResult(response);
        setResult(counts);setStep("result");
        toast({
          title: "Import complete",
          description: `${counts.imported} contacts imported, ${counts.duplicates} duplicates skipped, ${counts.failed} rows failed.`,
        });
        onSuccess?.();

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
      <DialogContent className="max-w-2xl max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Import Contacts</DialogTitle>
          <DialogDescription>
            {step === "upload" && "Upload an Excel (.xlsx) or CSV file to import contacts"}
            {step === "mapping" && "Map your file columns to contact fields"}
            {step === "preview" && "Review contacts before importing"}
            {step === "importing" && "Importing your contacts..."}
            {step === "result" && "Import results from the server"}
            {step === "reading" && "Reading your file securely in this browser…"}
            {step === "sheet" && "Choose the worksheet containing your contacts"}
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
                  <h4 className="font-semibold text-blue-900 mb-2">Get your import right the first time</h4>
                  <a className="text-sm underline" href="/contact-import-template.csv" download>Download a blank CSV template (opens in Excel)</a>
                  <div className="text-sm text-blue-800 space-y-2">
                    <p>Upload Excel (.xlsx) or CSV. Maximum 5 MB and 10,000 contacts per sheet.</p><p><strong>Required:</strong> Phone. Map your own headings in the next step.</p>
                    <p><strong>Optional:</strong> Name, Surname and Email.</p><ol className="list-decimal pl-5 space-y-1"><li>Put unique headings in row 1 and one contact per row. Do not use title rows, merged cells or formulas.</li><li>In Excel, format Phone as Text before entering numbers. Use +27… or a full local number such as 0631540696. Formatting after a leading zero was lost will not restore it.</li><li>Save as .xlsx. Upload, choose the contact worksheet, map your headings, and select an existing group if needed.</li><li>Check the preview before importing. Keep duplicate skipping enabled and read the final imported/skipped/failed counts.</li></ol>
                    <p className="mt-3"><strong>Example CSV:</strong></p>
                    <code className="block bg-white p-2 rounded border border-blue-200 font-mono text-xs mt-1">
                      Full Name,Phone,Email<br/>
                      John Doe,+27791234567,john@example.com<br/>
                      Jane Smith,+27987654321,jane@example.com<br/>
                      ,+27555111111,bob@example.com
                    </code>
                    <div className="mt-3 space-y-1 text-xs">
                      <p>✓ Phone: Required - must include country code (e.g., +27, +1, +44)</p>
                      <p>✓ Full Name: Optional - can be left empty</p>
                      <p>✓ Email: Optional. A phone number is always required.</p>
                      <p>Choose an existing group in the next step; no Group column is required.</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              className={cn(
                "flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-12 transition-colors",
                "relative border-border hover:border-primary hover:bg-primary/5 cursor-pointer"
              )}
              onDragOver={(e) => e.preventDefault()}
              onDrop={handleFileDrop}
            >
              <Upload className="h-12 w-12 text-muted-foreground" />
              <p className="mt-4 text-lg font-medium text-foreground">
                Drag & drop your Excel or CSV file here
              </p>
              <p className="mt-1 text-sm text-muted-foreground">or click to browse</p>
              <input
                type="file"
                accept=".csv,.xlsx"
                onChange={handleFileChange}
                className="absolute inset-0 cursor-pointer opacity-0"
              />
              <Button variant="outline" className="mt-4">
                Select File
              </Button>
            </div>
          </div>
        )}

        {step==="reading"&&<div className="p-8 flex gap-3"><Loader2 className="animate-spin"/>Reading file…</div>}
        {step==="sheet"&&<div className="space-y-4"><Label>Contact worksheet</Label><Select value={sheetIndex} onValueChange={setSheetIndex}><SelectTrigger><SelectValue/></SelectTrigger><SelectContent>{sheets.map((s,i)=><SelectItem key={i} value={String(i)}>{s.sheet}</SelectItem>)}</SelectContent></Select><p className="text-sm">Only this worksheet will be imported. Row 1 must contain your column headings.</p><Button onClick={()=>{try{applySheet(sheets[Number(sheetIndex)].data);}catch(e){toast({title:"Check this worksheet",description:e instanceof Error?e.message:"Invalid sheet",variant:"destructive"});}}}>Use this worksheet</Button></div>}
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


            </div>

            <div className="space-y-3 pt-4 border-t">
              <Label>Assign to Existing Group (Optional)</Label>
              <Select value={selectedGroupId} onValueChange={setSelectedGroupId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a group..." />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__empty__">— No group —</SelectItem>
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
                Assign new contacts to this existing group. CSV Group columns are ignored. Create groups in Contacts before importing.
              </p>
            </div>

            <div className="space-y-3">
              <div className="flex items-center gap-2">
                <Checkbox
                  id="skipFirst"
                  checked={skipFirstRow}
                  disabled
                />
                <Label htmlFor="skipFirst">First row must contain column headings</Label>
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

        {step==="result"&&result&&<div role="status" className="space-y-3 rounded-lg border p-5"><p className="text-lg font-semibold">{result.imported} contacts imported</p><p>{result.duplicates} duplicate contacts found and skipped.</p><p>{result.failed} rows could not be imported.</p>{result.duplicates>0&&<p className="text-sm text-muted-foreground">Existing contacts were kept. Duplicate rows were not added again.</p>}</div>}
        <DialogFooter>
          {step==="result"&&<Button onClick={handleClose}>Done</Button>}
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
              <Button onClick={proceedToPreview} disabled={!columnMapping.phone}>
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
