export interface ContactImportResult { imported: number; duplicates: number; failed: number; existing_added_to_group?: number; corrected?: number; rejected?: number }

export function contactImportResult(response: unknown): ContactImportResult {
  const envelope=response as {data?:unknown};
  const data=(envelope?.data??response) as Record<string,unknown>;
  const count=(key:string)=>{
    const raw=data?.[key];
    const value=typeof raw==='number'?raw:typeof raw==='string'&&raw.trim()!==''?Number(raw):NaN;
    if(!Number.isSafeInteger(value)||value<0)throw new Error('The import completed, but its counts were unavailable. Check Contacts before importing again.');
    return value;
  };
  return {imported:count('imported'),duplicates:count('duplicates'),failed:count('failed'),
    ...(data?.existing_added_to_group !== undefined ? {existing_added_to_group:count('existing_added_to_group')} : {}),
    ...(data?.corrected !== undefined ? {corrected:count('corrected')} : {}),
    ...(data?.rejected !== undefined ? {rejected:count('rejected')} : {})};
}
