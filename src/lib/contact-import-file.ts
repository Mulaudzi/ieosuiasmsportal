export interface ImportSheet { sheet: string; data: unknown[][] }

export type ContactHeaderField = 'name' | 'surname' | 'phone' | 'email';
export function contactHeaderField(header: string): ContactHeaderField | null {
  const key=header.toLowerCase().replace(/\s*\(\d+\)$/,'').replace(/[^a-z0-9]/g,'');
  if(['surname','lastname','familyname'].includes(key))return 'surname';
  if(['name','firstname','fullname','contactname'].includes(key))return 'name';
  if(['phone','phonenumber','phonenumbers','phoneno','mobile','mobilenumber','mobileno','mobilephone','cell','cellphone','cellnumber','cellno','contact','contacts','contactnumber','contactnumbers','contactno','telephone','tel','whatsapp','whatsappnumber'].includes(key))return 'phone';
  if(['email','emailaddress'].includes(key))return 'email';
  return null;
}

const sheetCell=(value:unknown):string=>value==null?'':value instanceof Date?value.toISOString():String(value).trim();
const phoneLike=(value:unknown)=>{
  const raw=sheetCell(value).replace(/[０-９٠-٩۰-۹]/g,char=>String(char.charCodeAt(0)-(char>='０'?0xFF10:char>='۰'?0x06F0:0x0660)))
    .replace(/[\u200B-\u200F\u2060\uFEFF]/g,'').replace(/＋/g,'+').replace(/[‐‑‒–—−]/g,'-')
    .replace(/^['’]/,'').replace(/^="([+0-9\s().-]+)"$/,'$1')
    .replace(/^(?:tel(?:ephone)?|phone|mobile|cell(?:phone)?|contact|whatsapp)\s*:\s*/i,'').replace(/\s/g,'');
  return /^(?:\+?\d{5,15}|\+?27)$/.test(raw.replace(/[().-]/g,''))||/^\d+(?:\.\d+)?[eE][+-]?\d{1,2}$/.test(raw);
};
const contactValueLike=(value:unknown)=>phoneLike(value)||/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(sheetCell(value));

/** High-confidence contact headings may follow report titles or blank rows. */
export function contactSheetHeaderRow(input:unknown[][]):number|null {
  const first=input.find(row=>row.some(value=>sheetCell(value)!==''));
  if(first?.some(contactValueLike))return null; // Never discard a headerless first contact.
  let best:number|null=null,score=0;
  input.slice(0,50).forEach((row,index)=>{
    const fields=row.map(value=>contactHeaderField(sheetCell(value)));
    if((!fields.includes('phone')&&!fields.includes('email'))||row.some(contactValueLike))return;
    const candidate=new Set(fields.filter(Boolean)).size;
    if(candidate>score){score=candidate;best=index+1;}
  });
  return best;
}

export function parseContactCsv(text: string): string[][] {
  text=text.replace(/^\uFEFF/,'');
  const declared=text.match(/^sep=([,;\t|])\r?\n/i);
  let separator=declared?.[1];
  if(declared)text='\n'+text.slice(declared[0].length);
  if(!separator){
    const counts=new Map([',',';','\t','|'].map(char=>[char,0]));let inQuote=false,line=0;
    for(let i=0;i<text.length&&line<30;i++){
      if(text[i]==='"'){if(inQuote&&text[i+1]==='"'){i++;continue;}inQuote=!inQuote;}
      else if(!inQuote){if(text[i]==='\n')line++;if(counts.has(text[i]))counts.set(text[i],counts.get(text[i])!+1);}
    }
    separator=[...counts].sort((a,b)=>b[1]-a[1])[0][0];
  }
  const rows:string[][]=[];let row:string[]=[];let cell='';let quoted=false;
  for(let i=0;i<text.length;i++){
    const c=text[i];
    if(c==='"'){
      if(quoted&&text[i+1]==='"'){cell+='"';i++;}
      else if(quoted||cell==='')quoted=!quoted;
      else throw new Error('Invalid CSV quoting. Save the file as CSV UTF-8 (comma delimited).');
    }else if(c===separator&&!quoted){row.push(cell);cell='';}
    else if((c==='\n'||c==='\r')&&!quoted){if(c==='\r'&&text[i+1]==='\n')i++;row.push(cell);rows.push(row);row=[];cell='';}
    else cell+=c;
  }
  if(quoted)throw new Error('The CSV has an unclosed quotation mark. Check the file and save it again.');
  if(cell||row.length){row.push(cell);rows.push(row);}
  return rows;
}

/** Excel exports differ by locale and operating system; decoding is file-based. */
export function decodeContactCsv(buffer:ArrayBuffer):string {
  const bytes=new Uint8Array(buffer);
  if(bytes[0]===0xFF&&bytes[1]===0xFE)return new TextDecoder('utf-16le').decode(buffer);
  if(bytes[0]===0xFE&&bytes[1]===0xFF)return new TextDecoder('utf-16be').decode(buffer);
  try{return new TextDecoder('utf-8',{fatal:true}).decode(buffer);}catch{return new TextDecoder('windows-1252').decode(buffer);}
}

export function contactSheetHasHeaders(input: unknown[][]): boolean {
  const first=input.find(row=>row.some(value=>sheetCell(value)!==''));
  return !first?.some(contactValueLike);
}

export interface PreparedContactSheet {
  headers:string[];data:string[][];rowNumbers:number[];
  headerRowNumber:number|null;skippedLeadingRows:number;skippedHeaderRows:number[];
  ignoredEmptyColumns:number[];renamedColumns:string[];
}

export function prepareContactSheet(input:unknown[][],hasHeaders=true,headingRow?:number):PreparedContactSheet {
  const populated=input.map((row,index)=>({number:index+1,cells:row.map(sheetCell)})).filter(row=>row.cells.some(Boolean));
  if(!populated.length)throw new Error('Include at least one contact. If your file has no headings, turn off the headings option.');
  const headerRowNumber=hasHeaders?(headingRow??contactSheetHeaderRow(input)??populated[0].number):null;
  const headerRow=hasHeaders?populated.find(row=>row.number===headerRowNumber):null;
  if(hasHeaders&&!headerRow)throw new Error('Choose a non-empty worksheet row for the column headings.');
  const selected=populated.filter(row=>headerRowNumber===null||row.number>=headerRowNumber);
  const width=Math.max(...selected.map(row=>row.cells.length));
  // Ignore genuinely empty columns only. Unlabelled populated columns remain mappable.
  const columns=Array.from({length:width},(_,i)=>i).filter(i=>selected.some(row=>Boolean(row.cells[i])));
  if(columns.length>30)throw new Error('Use no more than 30 populated columns in the contact sheet.');
  const ignoredEmptyColumns=Array.from({length:width},(_,i)=>i+1).filter(i=>!columns.includes(i-1));
  const renamedColumns:string[]=[];const used=new Set<string>();
  const headers=columns.map(i=>{
    const original=headerRow?.cells[i]??'';const base=original||`Column ${i+1}`;
    let label=base,suffix=2;
    while(used.has(label.toLowerCase()))label=`${base} (${suffix++})`;
    used.add(label.toLowerCase());if(hasHeaders&&label!==original)renamedColumns.push(label);
    return label;
  });
  const headerSignature=headerRow?columns.map(i=>(headerRow.cells[i]??'').toLowerCase()):[];
  const skippedHeaderRows:number[]=[];
  const contacts=selected.filter(row=>{
    if(row.number===headerRowNumber)return false;
    if(hasHeaders&&headerSignature.some(Boolean)&&columns.every((col,i)=>(row.cells[col]??'').toLowerCase()===headerSignature[i])){
      skippedHeaderRows.push(row.number);return false;
    }
    return true;
  });
  if(!contacts.length)throw new Error('Include at least one contact below the headings. If your file has no headings, turn off the headings option.');
  if(contacts.length>10000)throw new Error('Import at most 10,000 contacts at a time. Split this file into smaller files.');
  return {headers,data:contacts.map(row=>columns.map(i=>row.cells[i]??'')),rowNumbers:contacts.map(row=>row.number),headerRowNumber,skippedLeadingRows:headerRowNumber===null?0:headerRowNumber-1,skippedHeaderRows,ignoredEmptyColumns,renamedColumns};
}

export function contactSheetCsv(headers:string[],data:string[][]): string {
  return [headers,...data].map(row=>row.map(cell=>'"'+cell.replace(/"/g,'""')+'"').join(',')).join('\r\n');
}
