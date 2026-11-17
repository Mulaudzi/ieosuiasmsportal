// Preserve the existing private-workbook CLI argument order.
const workbook=process.argv[3];
if(!workbook)throw new Error('Pass the private workbook path.');
process.argv[3]=process.argv[4]??'.sms-audit.local/import-usability-20261029/workbook-browser';
process.argv[4]=workbook;
await import('./test-contact-import-usability-browser.mjs');
