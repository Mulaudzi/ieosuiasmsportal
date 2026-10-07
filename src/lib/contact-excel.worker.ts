import readExcelFile from 'read-excel-file/universal';
self.onmessage=async(event:MessageEvent<ArrayBuffer>)=>{
  try{
    const sheets=await readExcelFile(event.data,{parseNumber:value=>value});
    if(sheets.length>30||sheets.reduce((n,s)=>n+s.data.length,0)>30000)throw new Error('Workbook is too large. Keep only the contact sheets and try again.');
    self.postMessage({sheets});
  }catch(e){self.postMessage({error:e instanceof Error?e.message:'Unable to read workbook'});}
};
