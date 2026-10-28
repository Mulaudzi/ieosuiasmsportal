import { useState, useCallback, useRef, useEffect, useMemo } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from '@/components/ui/input';
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
import { parseContactCsv, prepareContactSheet, contactSheetCsv, contactSheetHasHeaders, ImportSheet } from "@/lib/contact-import-file";
import { analyzeSouthAfricanPhone, resolvedImportPhone, PhoneDecision } from "@/lib/contact-phone-review";
import { ContactPhoneReview } from "./ContactPhoneReview";
import { createContactGroup, handleApiError, importContacts } from "@/lib/api";
import { contactImportResult, ContactImportResult } from "@/lib/contact-import-result";
import { toast } from "@/hooks/use-toast";

interface ContactImportModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
  groups?: Array<{ id: string | number; name: string; is_virtual?: boolean }>;
  defaultGroupId?: string;
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

export function ContactImportModal({ open, onOpenChange, onSuccess, groups = [], defaultGroupId = "" }: ContactImportModalProps) {
  const [createdGroups,setCreatedGroups]=useState<Array<{id:string;name:string;is_virtual?:boolean}>>([]);
  const [newGroupName,setNewGroupName]=useState('');
  const [creatingGroup,setCreatingGroup]=useState(false);
  const realGroups = [...groups,...createdGroups].filter(group => !group.is_virtual && /^[1-9]\d*$/.test(String(group.id)));
  const defaultDestination = realGroups.some(group => String(group.id) === defaultGroupId) ? defaultGroupId : "";
  const [step, setStep] = useState<"upload" | "mapping" | "preview" | "importing" | "result" | "reading" | "sheet">("upload");
  const [result,setResult]=useState<ContactImportResult|null>(null);
  const readGeneration=useRef(0);
  const [sheets,setSheets]=useState<ImportSheet[]>([]);
  const [sheetIndex,setSheetIndex]=useState("0");
  const [file, setFile] = useState<File | null>(null);
  const [csvHeaders, setCsvHeaders] = useState<string[]>([]);
  const [csvData, setCsvData] = useState<string[][]>([]);
  const [sourceRows, setSourceRows] = useState<unknown[][]>([]);
  const [rowNumbers, setRowNumbers] = useState<number[]>([]);
  const [hasHeaders, setHasHeaders] = useState(true);
  const [phoneDecisions, setPhoneDecisions] = useState<Record<number, PhoneDecision>>({});
  const [columnMapping, setColumnMapping] = useState<ColumnMapping>({
    name: "",
    surname: "",
    phone: "",
    email: "",
    group: "",
  });
  const skipDuplicates = true;
  const [selectedGroupId, setSelectedGroupId] = useState<string>(defaultDestination);
  const wasOpen = useRef(false);
  useEffect(() => {
    if (open && !wasOpen.current) setSelectedGroupId(defaultDestination);
    wasOpen.current = open;
  }, [open, defaultDestination]);
  const destination = realGroups.find(group => String(group.id) === selectedGroupId);
  const destinationMissing = Boolean(selectedGroupId && !destination);
  const destinationName = destination?.name ?? (destinationMissing ? "Group no longer available" : "Uncategorized (no group)");
  const [parsedContacts, setParsedContacts] = useState<ParsedContact[]>([]);

  const resetState = () => {
    readGeneration.current++;
    setSheets([]);setSheetIndex("0");
    setStep("upload");
    setResult(null);
    setFile(null);
    setCsvHeaders([]);
    setCsvData([]);
    setSourceRows([]); setRowNumbers([]); setHasHeaders(true); setPhoneDecisions({});
    setColumnMapping({ name: "", surname: "", phone: "", email: "", group: "" });
    setParsedContacts([]);
  };

  const handleClose = () => {
    if (step === "importing") return;
    resetState();
    onOpenChange(false);
  };

  const applySheet=(rows:unknown[][], headersPresent = contactSheetHasHeaders(rows))=>{
    const {headers,data,rowNumbers: numbers}=prepareContactSheet(rows, headersPresent);
    setSourceRows(rows);setHasHeaders(headersPresent);setRowNumbers(numbers);setPhoneDecisions({});
    setCsvHeaders(headers);setCsvData(data);
    const mapping:ColumnMapping={name:"",surname:"",phone:"",email:"",group:""};
    headers.forEach(header=>{const h=header.toLowerCase();
      if(/surname|last.?name/.test(h))mapping.surname=header;
      else if(h.includes("name")&&!mapping.name)mapping.name=header;
      if(/phone|mobile|cell|contact/.test(h)&&!mapping.phone)mapping.phone=header;
      if(h.includes("email"))mapping.email=header;
    });
    if (!headersPresent && headers.length === 1) mapping.phone = headers[0];
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
    setPhoneDecisions({});
    setStep("preview");
  };

  const handleImport = async () => {
    if (!selectedGroupId && !window.confirm('These contacts have no destination group. Choose a group so they are easy to select when sending SMS. Import to Uncategorized anyway?')) return;
    if (destinationMissing || step === "importing" || pendingReview > 0 || validContacts.length === 0) return;
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
      formData.append('phone_review_version', '1');
      formData.append('phone_review_decisions', JSON.stringify(Object.entries(phoneDecisions).map(([index, decision]) => ({ row: Number(index) + 2, ...decision }))));
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
          description: `${counts.imported} new contacts imported to ${destinationName}; ${selectedGroupId ? `${counts.existing_added_to_group ?? 0} existing contacts added to the group; ` : ''}${counts.duplicates} duplicate rows avoided; ${counts.failed} rows failed.`,
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

  const reviewPhones = useMemo(() => parsedContacts.map(contact => contact.phone ?? ""), [parsedContacts]);
  const previewContacts = parsedContacts.map((contact, index) => ({ ...contact, phone: resolvedImportPhone(contact.phone ?? "", phoneDecisions[index]) ?? "" }));
  const validContacts = previewContacts.filter(contact => contact.phone);
  const pendingReview = reviewPhones.filter((phone, index) => !analyzeSouthAfricanPhone(phone).normalized && !phoneDecisions[index]).length;
  const rejectedRows = Object.values(phoneDecisions).filter(decision => decision.action === "reject").length;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-2xl max-h-[90dvh] grid-cols-1 overflow-y-auto">
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

        <div className="min-w-0 break-words rounded-lg border border-border bg-muted/40 p-3 space-y-2">
          <p className="text-sm font-medium" aria-live="polite">Import destination: {destinationName}</p>
          {!["importing", "result"].includes(step) && <>
            <Label htmlFor="import-destination">Destination group</Label>
            <Select value={selectedGroupId || "__empty__"} onValueChange={value => setSelectedGroupId(value === "__empty__" ? "" : value)}>
              <SelectTrigger id="import-destination"><SelectValue placeholder="Select a group" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="__empty__">Uncategorized (no group)</SelectItem>
                {realGroups.map(group => <SelectItem key={String(group.id)} value={String(group.id)}>{group.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </>}
          <p className="text-xs text-muted-foreground">{selectedGroupId
            ? "New contacts and existing matching phone numbers will be added to this group. Other group memberships and opt-out settings stay unchanged."
            : "New contacts will be saved without a group. Existing contacts keep their current groups."}</p>
          {destinationMissing && <p role="alert" className="text-sm text-destructive">Choose an available destination before importing.</p>}
          {!['importing','result','reading'].includes(step) && <div className="space-y-2"><Label htmlFor="import-new-group">Or create a group for this import</Label><div className="flex flex-wrap gap-2"><Input id="import-new-group" className="min-w-0 flex-1" placeholder="For example: Customers" value={newGroupName} maxLength={100} disabled={creatingGroup} onChange={e=>setNewGroupName(e.target.value)}/><Button variant="outline" disabled={creatingGroup || !newGroupName.trim()} onClick={async()=>{setCreatingGroup(true);try{const response=await createContactGroup(newGroupName.trim());const group=response.data?.group??(response as unknown as {group?:{id:string;name:string}}).group;if(!response.success || !group?.id)throw new Error('Unable to create group.');setCreatedGroups(previous=>[...previous,{id:String(group.id),name:group.name}]);setSelectedGroupId(String(group.id));setNewGroupName('');}catch(error){handleApiError(error);}finally{setCreatingGroup(false);}}}>Create group</Button></div><p className="text-xs text-muted-foreground">Your selected file and column choices will be kept.</p></div>}
        </div>

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
                    <p><strong>Optional:</strong> Name, Surname and Email.</p><ol className="list-decimal pl-5 space-y-1"><li>Use one contact per row, ideally with unique headings in row 1. If there are no headings, turn off the headings option when mapping. Do not use title rows, merged cells or formulas.</li><li>In Excel, format Phone as Text. Use 0821234567, 27821234567 or +27821234567. If a zero has already been lost, review and approve a proposed correction before importing.</li><li>Confirm the destination group above. Save as .xlsx, upload, choose the contact worksheet and map your columns.</li><li>Accept or reject every flagged number in the preview. Duplicate numbers reuse saved contacts without changing their details or opt-out settings.</li></ol>
                    <p className="mt-3"><strong>Example CSV:</strong></p>
                    <code className="block bg-white p-2 rounded border border-blue-200 font-mono text-xs mt-1">
                      Full Name,Phone,Email<br/>
                      John Doe,+27791234567,john@example.com<br/>
                      Jane Smith,+27987654321,jane@example.com<br/>
                      ,+27555111111,bob@example.com
                    </code>
                    <div className="mt-3 space-y-1 text-xs">
                      <p>✓ Phone: Required. Use a South African mobile number starting with 0, 27 or +27.</p>
                      <p>✓ Full Name: Optional - can be left empty</p>
                      <p>✓ Email: Optional. A phone number is always required.</p>
                      <p>Confirm the destination group above; no Group column is required.</p>
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
        {step==="sheet"&&<div className="space-y-4"><Label>Contact worksheet</Label><Select value={sheetIndex} onValueChange={setSheetIndex}><SelectTrigger><SelectValue/></SelectTrigger><SelectContent>{sheets.map((s,i)=><SelectItem key={i} value={String(i)}>{s.sheet}</SelectItem>)}</SelectContent></Select><p className="text-sm">Only this worksheet will be imported. You can confirm whether it has column headings in the next step.</p><Button onClick={()=>{try{applySheet(sheets[Number(sheetIndex)].data);}catch(e){toast({title:"Check this worksheet",description:e instanceof Error?e.message:"Invalid sheet",variant:"destructive"});}}}>Use this worksheet</Button></div>}
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

            <div className="space-y-3">
              <div className="flex items-center gap-2">
                <Checkbox
                  id="skipFirst"
                  checked={hasHeaders}
                  onCheckedChange={checked => { try { applySheet(sourceRows, checked === true); } catch (error) { toast({title:"Check the headings",description:error instanceof Error ? error.message : "Invalid headings",variant:"destructive"}); } }}
                />
                <Label htmlFor="skipFirst">First row contains column headings</Label>
              </div>
              <p className="text-xs text-muted-foreground">{hasHeaders ? "The first populated row is used as headings, not a contact." : "No headings: the first populated row is included as a contact. Map Column 1, Column 2 and so on above."}</p>
              <p className="break-all text-xs text-muted-foreground">First contact phone: {csvData[0]?.[csvHeaders.indexOf(columnMapping.phone)] || "Choose the phone column"}</p>
              <div className="flex items-center gap-2">
                <Checkbox
                  id="skipDupes"
                  checked={skipDuplicates}
                  disabled
                />
                <Label htmlFor="skipDupes">Prevent duplicate contacts by phone number</Label>
              </div>
            </div>
          </div>
        )}

        {/* Preview Step */}
        {step === "preview" && (
          <div className="min-w-0 space-y-4">
            <ContactPhoneReview phones={reviewPhones} rowNumbers={rowNumbers} decisions={phoneDecisions} onChange={setPhoneDecisions} />
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
              <div className="flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2">
                <CheckCircle className="h-4 w-4 text-success" />
                <span className="text-sm font-medium text-success">
                  {validContacts.length} rows ready to import
                </span>
              </div>
              {parsedContacts.length - validContacts.length > 0 && (
                <div className="flex items-center gap-2 rounded-lg bg-destructive/10 px-3 py-2">
                  <AlertCircle className="h-4 w-4 text-destructive" />
                  <span className="text-sm font-medium text-destructive">
                    {pendingReview} need review · {rejectedRows} rejected
                  </span>
                </div>
              )}
            </div>

            {/* Validation message */}
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
              <p className="text-xs text-amber-800">
                <strong>Validation:</strong> Names and emails are optional. The server rechecks every correction and duplicate before saving. Matching saved contacts are reused, not overwritten. Rejected rows are excluded.
              </p>
            </div>

            <div className="max-h-64 max-w-full overflow-auto rounded-lg border border-border">
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
                  {previewContacts.slice(0, 10).map((contact, i) => {
                    const isValid = Boolean(contact.phone);
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
                        <td className="px-4 py-2 text-foreground">{contact.phone || (phoneDecisions[i]?.action === "reject" ? "Rejected" : "Needs review")}</td>
                        <td className="px-4 py-2 text-foreground">{contact.email || "—"}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {previewContacts.length > 10 && (
              <p className="text-sm text-muted-foreground">
                Showing first 10 of {previewContacts.length} source rows
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

        {step==="result"&&result&&<div role="status" className="space-y-3 rounded-lg border p-5"><p className="text-lg font-semibold">{result.imported} new contacts imported</p>{selectedGroupId&&<p>{result.existing_added_to_group ?? 0} existing contacts added to {destinationName}.</p>}<p>{result.duplicates} duplicate rows found; no duplicate contacts created.</p><p>{result.failed} rows could not be imported.</p>{result.duplicates>0&&<p className="text-sm text-muted-foreground">Existing contact details, other group memberships and opt-out settings were kept.</p>}</div>}
        {step==="result"&&result&&<p className="text-sm text-muted-foreground">{result.corrected ?? 0} number corrections approved; {result.rejected ?? 0} rows excluded by your choice.</p>}
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
              <Button className="h-auto min-h-10 max-w-full whitespace-normal break-words" onClick={handleImport} disabled={creatingGroup || validContacts.length === 0 || destinationMissing || pendingReview > 0}>
                Import {validContacts.length} Contacts to {destination?.name ?? "All Contacts"}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
