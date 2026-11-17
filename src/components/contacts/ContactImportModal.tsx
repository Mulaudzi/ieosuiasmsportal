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
import { Upload, FileText, AlertCircle, CheckCircle, Loader2, X, ArrowLeft, ArrowRight, ArrowDown } from "lucide-react";
import { cn } from "@/lib/utils";
import { parseContactCsv, decodeContactCsv, prepareContactSheet, contactSheetCsv, contactSheetHasHeaders, contactHeaderField, ImportSheet } from "@/lib/contact-import-file";
import { reviewImportContact, importEmailValid, type ImportDecision } from "@/lib/contact-import-review";
import { ContactImportReview } from "./ContactImportReview";
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
  const [headerRowNumber,setHeaderRowNumber]=useState<number|null>(null);
  const [sheetNotes,setSheetNotes]=useState<string[]>([]);
  const [phoneDecisions, setPhoneDecisions] = useState<Record<number, ImportDecision>>({});
  const previewMapping=useRef('');
  const bodyRef=useRef<HTMLDivElement>(null),bodyContentRef=useRef<HTMLDivElement>(null);
  const [moreBelow,setMoreBelow]=useState(false);
  const [previewFilter,setPreviewFilter]=useState('all');
  useEffect(()=>{
    if(!open)return;
    const body=bodyRef.current;if(!body)return;body.scrollTop=0;
    const update=()=>setMoreBelow(body.scrollHeight-body.scrollTop-body.clientHeight>5);
    const observer=new ResizeObserver(update);observer.observe(body);if(bodyContentRef.current)observer.observe(bodyContentRef.current);
    body.addEventListener('scroll',update,{passive:true});update();
    return()=>{observer.disconnect();body.removeEventListener('scroll',update);};
  },[open,step]);
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
    setHeaderRowNumber(null);setSheetNotes([]);
    previewMapping.current='';setPreviewFilter('all');
    setColumnMapping({ name: "", surname: "", phone: "", email: "", group: "" });
    setParsedContacts([]);
  };

  const handleClose = () => {
    if (step === "importing") return;
    resetState();
    onOpenChange(false);
  };

  const applySheet=(rows:unknown[][], headersPresent = contactSheetHasHeaders(rows),headingRow?:number)=>{
    const prepared=prepareContactSheet(rows,headersPresent,headingRow);
    const {headers,data,rowNumbers:numbers}=prepared;
    setHeaderRowNumber(prepared.headerRowNumber);
    setSheetNotes([
      ...(prepared.skippedLeadingRows?[`Skipped ${prepared.skippedLeadingRows} introductory worksheet row(s); contacts start below heading row ${prepared.headerRowNumber}.`]:[]),
      ...(prepared.ignoredEmptyColumns.length?[`Ignored ${prepared.ignoredEmptyColumns.length} completely empty column(s).`]:[]),
      ...(prepared.renamedColumns.length?[`Assigned unique labels to blank or repeated headings: ${prepared.renamedColumns.join(', ')}. All populated columns are kept for mapping.`]:[]),
      ...(prepared.skippedHeaderRows.length?[`Skipped ${prepared.skippedHeaderRows.length} repeated heading row(s): ${prepared.skippedHeaderRows.join(', ')}.`]:[]),
    ]);
    setSourceRows(rows);setHasHeaders(headersPresent);setRowNumbers(numbers);setPhoneDecisions({});
    previewMapping.current='';
    setCsvHeaders(headers);setCsvData(data);
    const mapping:ColumnMapping={name:"",surname:"",phone:"",email:"",group:""};
    headers.forEach(header=>{
      const field=contactHeaderField(header);
      if(field&&!mapping[field])mapping[field]=header;
    });
    if (!headersPresent && headers.length === 1) {
      if(importEmailValid(data[0]?.[0]??''))mapping.email=headers[0];else mapping.phone=headers[0];
    }
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
        const rows=parseContactCsv(decodeContactCsv(await selectedFile.arrayBuffer()));
        if(generation===readGeneration.current)applySheet(rows);
      }else{
        const buffer=await selectedFile.arrayBuffer();
        if(generation!==readGeneration.current)return;
        const fallback=async()=>{
          const {default:readExcelFile}=await import('read-excel-file/universal');
          const result=await readExcelFile(buffer,{parseNumber:value=>value});
          if(result.length>30||result.reduce((total,sheet)=>total+sheet.data.length,0)>30000)throw new Error('Workbook is too large. Keep only the contact sheets and try again.');
          return result;
        };
        const workbook=typeof Worker==='undefined'?await fallback():await new Promise<ImportSheet[]>((resolve,reject)=>{
          let worker:Worker;
          try{worker=new Worker(new URL("../../lib/contact-excel.worker.ts",import.meta.url),{type:"module"});}catch{fallback().then(resolve,reject);return;}
          const timeout=window.setTimeout(()=>{worker.terminate();reject(new Error("Workbook took too long to read. Remove unused sheets and try a smaller file."));},45000);
          const done=()=>{window.clearTimeout(timeout);worker.terminate();};
          worker.onmessage=e=>{done();if(e.data.error)reject(new Error("Unable to read this workbook. Remove password protection, save as .xlsx and try again."));else resolve(e.data.sheets);};
          worker.onerror=event=>{event.preventDefault();done();fallback().then(resolve,reject);};
          worker.postMessage(buffer);
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
    if (!columnMapping.phone && !columnMapping.email) {
      toast({
        title: "Choose a contact column",
        description: "Select a phone column, an email column, or both.",
        variant: "destructive",
      });
      return;
    }
    if(columnMapping.phone&&columnMapping.phone===columnMapping.email){toast({title:'Check the column mapping',description:'Phone and email must use different columns.',variant:'destructive'});return;}

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
    
    const signature=JSON.stringify([columnMapping,csvHeaders,csvData]);
    if(previewMapping.current!==signature){setParsedContacts(contacts);setPhoneDecisions({});previewMapping.current=signature;setPreviewFilter('all');}
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
      formData.append('phone_review_version', '2');
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

  const previewContacts=useMemo(()=>parsedContacts.map((contact,index)=>({...contact,index,...reviewImportContact(contact,phoneDecisions[index])})),[parsedContacts,phoneDecisions]);
  const validContacts=previewContacts.filter(contact=>contact.status==='ready');
  const pendingReview=previewContacts.filter(contact=>contact.status==='review').length;
  const rejectedRows=previewContacts.filter(contact=>contact.status==='rejected').length;
  const skippedRows=previewContacts.filter(contact=>contact.status==='skipped').length;
  const automaticRows=previewContacts.filter(contact=>contact.automatic).length;
  const emailOnlyRows=validContacts.filter(contact=>contact.emailOnly).length;
  const filteredPreview=previewContacts.filter(contact=>previewFilter==='all'||contact.status===previewFilter);

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="flex max-w-3xl max-h-[92dvh] flex-col gap-0 overflow-hidden p-0">
        <DialogHeader className="shrink-0 border-b p-4 pr-10 text-left">
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
        <div ref={bodyRef} data-import-scroll className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4"><div ref={bodyContentRef} className="min-w-0 space-y-4">
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
                    <p>Upload Excel (.xlsx) or CSV. Maximum 5 MB and 10,000 contacts per sheet.</p><p><strong>Required:</strong> A phone number or an email. Rows with neither are skipped.</p>
                    <p><strong>Optional:</strong> Name and Surname.</p><ol className="list-decimal pl-5 space-y-1"><li>Use one contact per row. Title rows, empty columns and repeated headings are handled for you. Check the detected heading row when mapping. Formulas are never executed.</li><li>Complete South African mobile numbers are recognised with or without the leading zero, country code or formatting spaces. Safe formatting fixes are automatic; missing digits are never guessed.</li><li>Choose the worksheet and destination group, then review only contacts with genuinely incomplete or conflicting details. Email-only contacts can be saved but cannot receive SMS.</li><li>Matching saved phone numbers reuse the existing contact without changing its details or opt-out settings. Rows with neither phone nor email are skipped, not deleted from your saved contacts.</li></ol>
                    <p className="mt-3"><strong>Example CSV:</strong></p>
                    <code className="block bg-white p-2 rounded border border-blue-200 font-mono text-xs mt-1">
                      Full Name,Phone,Email<br/>
                      John Doe,+27791234567,john@example.com<br/>
                      Jane Smith,+27987654321,jane@example.com<br/>
                      ,+27555111111,bob@example.com
                    </code>
                    <div className="mt-3 space-y-1 text-xs">
                      <p>✓ Phone: Use a complete South African mobile number.</p>
                      <p>✓ Full Name: Optional - can be left empty</p>
                      <p>✓ Phone or email is required. Email-only contacts are not SMS recipients.</p>
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
        {step==="sheet"&&<div className="space-y-4"><Label>Contact worksheet</Label><Select value={sheetIndex} onValueChange={setSheetIndex}><SelectTrigger><SelectValue/></SelectTrigger><SelectContent>{sheets.map((s,i)=><SelectItem key={i} value={String(i)}>{s.sheet}</SelectItem>)}</SelectContent></Select><p className="text-sm">Only this worksheet will be imported. You can confirm whether it has column headings in the next step.</p></div>}
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
              <Button variant="ghost" size="icon" aria-label="Choose a different file" onClick={resetState}>
                <X className="h-4 w-4" />
              </Button>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="import-map-name">Name Column</Label>
                <Select value={columnMapping.name || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, name: v === "__none__" ? "" : v })}>
                  <SelectTrigger id="import-map-name">
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
                <Label htmlFor="import-map-surname">Surname Column</Label>
                <Select value={columnMapping.surname || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, surname: v === "__none__" ? "" : v })}>
                  <SelectTrigger id="import-map-surname">
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
                <Label htmlFor="import-map-phone">Phone Column</Label>
                <Select value={columnMapping.phone || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, phone: v === "__none__" ? "" : v })}>
                  <SelectTrigger id="import-map-phone">
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
                <Label htmlFor="import-map-email">Email Column</Label>
                <Select value={columnMapping.email || "__none__"} onValueChange={(v) => setColumnMapping({ ...columnMapping, email: v === "__none__" ? "" : v })}>
                  <SelectTrigger id="import-map-email">
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
                <Label htmlFor="skipFirst">File contains column headings</Label>
              </div>
              {hasHeaders&&<div className="space-y-2"><Label htmlFor="contact-heading-row">Column heading row</Label><Select value={String(headerRowNumber??1)} onValueChange={value=>{try{applySheet(sourceRows,true,Number(value));}catch(error){toast({title:'Check this heading row',description:error instanceof Error?error.message:'Choose another row.',variant:'destructive'});}}}><SelectTrigger id="contact-heading-row" className="min-w-0"><SelectValue/></SelectTrigger><SelectContent>{sourceRows.slice(0,50).map((row,index)=>({row,index})).filter(({row})=>row.some(value=>value!=null&&String(value).trim()!=='')).map(({row,index})=><SelectItem key={index+1} value={String(index+1)}>Row {index+1}: {row.filter(value=>value!=null&&String(value).trim()!=='').slice(0,3).map(String).join(' / ').slice(0,70)}</SelectItem>)}</SelectContent></Select></div>}
              <p className="text-xs text-muted-foreground">{hasHeaders ? `Worksheet row ${headerRowNumber} is used as headings, not a contact. Review this choice before continuing.` : "No headings: the first populated row is included as a contact. Map Column 1, Column 2 and so on above."}</p>
              {sheetNotes.length>0&&<div role="status" aria-label="Worksheet adjustments" className="rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground"><ul className="list-disc space-y-1 pl-4">{sheetNotes.map(note=><li key={note}>{note}</li>)}</ul><p className="mt-2">Original worksheet row numbers are preserved. Rows with neither phone nor email will be skipped automatically.</p></div>}
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
            <div role="status" aria-label="Import checks" className="rounded-lg border bg-muted/30 p-3 text-sm space-y-1"><p><strong>{validContacts.length} ready</strong> · {pendingReview} need review · {skippedRows} empty contact rows skipped</p><p className="text-xs text-muted-foreground">{automaticRows} safe number fixes applied · {emailOnlyRows} email-only contacts (not available for SMS) · {rejectedRows} skipped by your choice. A valid number format does not guarantee delivery.</p></div>
            <ContactImportReview contacts={parsedContacts} rowNumbers={rowNumbers} decisions={phoneDecisions} onChange={setPhoneDecisions} />
            <p className="text-xs text-muted-foreground">The server checks every row before saving. Existing contact details, group memberships and opt-outs stay unchanged. Email-only contacts cannot receive SMS.</p>

            <div><Label htmlFor="import-preview-filter">Contact list filter</Label><select id="import-preview-filter" className="mt-1 h-10 w-full rounded-md border bg-background px-2 text-sm" value={previewFilter} onChange={event=>setPreviewFilter(event.target.value)}><option value="all">All rows ({previewContacts.length})</option><option value="ready">Ready ({validContacts.length})</option><option value="review">Needs review ({pendingReview})</option><option value="skipped">No phone or email ({skippedRows})</option><option value="rejected">Skipped by choice ({rejectedRows})</option></select></div>
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
                  {filteredPreview.slice(0, 10).map((contact) => {
                    const isValid = contact.status==='ready';
                    return (
                      <tr key={contact.index} className={isValid ? "" : "bg-muted/30"}>
                        <td className="px-4 py-2">
                          {isValid ? (
                            <CheckCircle className="h-4 w-4 text-success" />
                          ) : (
                            <X className="h-4 w-4 text-muted-foreground" />
                          )}
                        </td>
                        <td className={cn("px-4 py-2", isValid ? "text-foreground" : "text-muted-foreground")}>
                          {contact.name || "—"}
                        </td>
                        <td className="px-4 py-2 text-foreground">{contact.status==='ready'?(contact.phone||'Email only'):contact.status==='review'?'Needs review':'Skipped'}</td>
                        <td className="px-4 py-2 text-foreground">{contact.email || "—"}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {filteredPreview.length > 10 && (
              <p className="text-sm text-muted-foreground">
                Showing first 10 of {filteredPreview.length} matching source rows
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
        {step==="result"&&result&&<p className="text-sm text-muted-foreground">{result.automatically_corrected ?? 0} safe number fixes applied; {result.skipped_empty ?? 0} rows without phone or email skipped; {result.email_only ?? 0} email-only rows processed; {result.rejected ?? 0} rows excluded by your choice.</p>}
        </div></div>
        <DialogFooter data-import-actions={moreBelow?'floating':'inline'} className="shrink-0 !flex-col gap-2 !space-x-0 border-t bg-background px-4 py-3 shadow-[0_-5px_16px_-12px_rgba(0,0,0,0.4)]">
          {moreBelow&&<button type="button" className="flex items-center gap-1 text-left text-xs text-muted-foreground" onClick={()=>bodyRef.current?.scrollBy({top:Math.max(200,bodyRef.current.clientHeight*.8),behavior:'smooth'})}><ArrowDown className="h-3 w-3 shrink-0"/>Scroll down to see more contact details</button>}
          <div className="flex w-full items-center justify-between gap-2">
          {step==="result"&&<Button onClick={handleClose}>Done</Button>}
          {step === "upload" && (
            <Button variant="outline" onClick={handleClose}>
              Cancel
            </Button>
          )}
          {step==='reading'&&<Button variant="outline" onClick={resetState}><ArrowLeft className="mr-1 h-4 w-4"/>Back</Button>}
          {step==='sheet'&&<><Button variant="outline" onClick={resetState}><ArrowLeft className="mr-1 h-4 w-4"/>Back</Button><Button onClick={()=>{try{applySheet(sheets[Number(sheetIndex)].data);}catch(error){toast({title:'Check this worksheet',description:error instanceof Error?error.message:'Invalid sheet',variant:'destructive'});}}}>Use this worksheet<ArrowRight className="ml-1 h-4 w-4"/></Button></>}
          {step === "mapping" && (
            <>
              <Button variant="outline" onClick={()=>sheets.length?setStep('sheet'):setStep('upload')}>
                <ArrowLeft className="mr-1 h-4 w-4"/>Back
              </Button>
              <Button onClick={proceedToPreview} disabled={!columnMapping.phone&&!columnMapping.email}>
                Continue<ArrowRight className="ml-1 h-4 w-4"/>
              </Button>
            </>
          )}
          {step === "preview" && (
            <>
              <Button variant="outline" onClick={() => setStep("mapping")}>
                <ArrowLeft className="mr-1 h-4 w-4"/>Back
              </Button>
              <Button className="h-auto min-h-10 min-w-0 max-w-full flex-1 whitespace-normal break-words sm:flex-initial" onClick={handleImport} disabled={creatingGroup || validContacts.length === 0 || destinationMissing || pendingReview > 0}>
                <Upload className="mr-1 h-4 w-4 shrink-0"/><span className="min-w-0 break-words">Import {validContacts.length} Contacts to {destination?.name ?? "All Contacts"}</span>
              </Button>
            </>
          )}
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
