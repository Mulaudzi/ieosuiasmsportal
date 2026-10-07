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

export function prepareContactSheet(input: unknown[][]): {headers:string[];data:string[][]} {
  const rows=input.map(row=>row.map(value=>value==null?'':value instanceof Date?value.toISOString():String(value).trim())).filter(row=>row.some(Boolean));
  if(rows.length<2)throw new Error('Include column headings in the first row and at least one contact underneath.');
  const headers=rows[0];
  if(headers.length>30)throw new Error('Use no more than 30 columns in the contact sheet.');
  if(headers.some(h=>!h))throw new Error('Every used column needs a heading. Remove empty columns between your contact fields.');
  if(new Set(headers.map(h=>h.toLowerCase())).size!==headers.length)throw new Error('Column headings must be unique. Rename repeated headings.');
  if(rows.length>10001)throw new Error('Import at most 10,000 contacts at a time. Split this file into smaller files.');
  const data=rows.slice(1).map(row=>{
    if(row.length>headers.length&&row.slice(headers.length).some(Boolean))throw new Error('A row contains data beyond the headings. Check the worksheet or CSV commas.');
    return headers.map((_,i)=>row[i]??'');
  });
  return {headers,data};
}

export function contactSheetCsv(headers:string[],data:string[][]): string {
  return [headers,...data].map(row=>row.map(cell=>'"'+cell.replace(/"/g,'""')+'"').join(',')).join('\r\n');
}
