export interface ImportSheet { sheet: string; data: unknown[][] }

export function parseContactCsv(text: string): string[][] {
  text=text.replace(/^\uFEFF/,'');
  const rows:string[][]=[];let row:string[]=[];let cell='';let quoted=false;
  for(let i=0;i<text.length;i++){
    const c=text[i];
    if(c==='"'){
      if(quoted&&text[i+1]==='"'){cell+='"';i++;}
      else if(quoted||cell==='')quoted=!quoted;
      else throw new Error('Invalid CSV quoting. Save the file as CSV UTF-8 (comma delimited).');
    }else if(c===','&&!quoted){row.push(cell);cell='';}
    else if((c==='\n'||c==='\r')&&!quoted){if(c==='\r'&&text[i+1]==='\n')i++;row.push(cell);rows.push(row);row=[];cell='';}
    else cell+=c;
  }
  if(quoted)throw new Error('The CSV has an unclosed quotation mark. Check the file and save it again.');
  if(cell||row.length){row.push(cell);rows.push(row);}
  return rows;
}

export function contactSheetHasHeaders(input: unknown[][]): boolean {
  const first = input.find(row => row.some(value => value != null && String(value).trim() !== ''));
  return !first?.some(value => /^\+?\d{9,13}$/.test(String(value ?? '').replace(/[\s().-]/g, '')));
}

export function prepareContactSheet(input: unknown[][], hasHeaders = true): {headers:string[];data:string[][];rowNumbers:number[]} {
  const populated=input.map((row,index)=>({number:index+1,cells:row.map(value=>value==null?'':value instanceof Date?value.toISOString():String(value).trim())})).filter(row=>row.cells.some(Boolean));
  const rows=populated.map(row=>row.cells);
  if(rows.length<(hasHeaders?2:1))throw new Error('Include at least one contact. If your file has no headings, turn off the headings option.');
  const headers=hasHeaders?rows[0]:Array.from({length:Math.max(...rows.map(row=>row.length))},(_,i)=>`Column ${i+1}`);
  if(headers.length>30)throw new Error('Use no more than 30 columns in the contact sheet.');
  if(headers.some(h=>!h))throw new Error('Every used column needs a heading. Remove empty columns between your contact fields.');
  if(new Set(headers.map(h=>h.toLowerCase())).size!==headers.length)throw new Error('Column headings must be unique. Rename repeated headings.');
  if(rows.length-(hasHeaders?1:0)>10000)throw new Error('Import at most 10,000 contacts at a time. Split this file into smaller files.');
  const data=rows.slice(hasHeaders?1:0).map(row=>{
    if(row.length>headers.length&&row.slice(headers.length).some(Boolean))throw new Error('A row contains data beyond the headings. Check the worksheet or CSV commas.');
    return headers.map((_,i)=>row[i]??'');
  });
  return {headers,data,rowNumbers:populated.slice(hasHeaders?1:0).map(row=>row.number)};
}

export function contactSheetCsv(headers:string[],data:string[][]): string {
  return [headers,...data].map(row=>row.map(cell=>'"'+cell.replace(/"/g,'""')+'"').join(',')).join('\r\n');
}
