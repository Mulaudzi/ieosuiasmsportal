import {execFileSync} from 'node:child_process';

const staged = process.argv.includes('--staged');
const names = execFileSync('git', staged
  ? ['diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z']
  : ['ls-files', '-z']).toString().split('\0').filter(Boolean);
let errors = 0;
const scan = [];
for (const name of names) {
  const template = /\.(?:example|sample)$/.test(name);
  const forbidden = /(?:^|\/)(?:\.env(?:\..*)?|\.private|\.local-secrets|[^/]*credentials[^/]*\.(?:html|json|md|txt)|ftp-deploy\.env|\.deploy\.env|private\.local\.php)(?:$|\/)|\.(?:key|p12|pfx|keystore|jks)$/i;
  if (!template && forbidden.test(name)) {
    console.error(`${name}: private configuration must stay outside Git`);
    errors++;
    continue;
  }
  if (template || name.endsWith('check-repository-secrets.mjs')) continue;
  if (/[\r\n]/.test(name)) {
    console.error('Unsupported newline in file path');
    errors++;
    continue;
  }
  scan.push(name);
}
for (let start = 0; start < scan.length; start += 64) {
  const chunk = scan.slice(start, start + 64);
  const dataSet = execFileSync('git', ['cat-file', '--batch'], {
    input: chunk.map(name => staged ? `:${name}\n` : `HEAD:${name}\n`).join(''),
    maxBuffer: 256 * 1024 * 1024,
  });
  let offset = 0;
  for (const name of chunk) {
    const end = dataSet.indexOf(10, offset);
    const header = dataSet.subarray(offset, end).toString().split(' ');
    if (header[1] !== 'blob') throw new Error(`Cannot read staged blob: ${name}`);
    const size = Number(header[2]);
    const data = dataSet.subarray(end + 1, end + 1 + size);
    offset = end + 2 + size;
    if (data.includes(0)) continue;
  const patterns = [
    /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
    /\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|AKIA[A-Z0-9]{16})\b/,
  ];
    if (patterns.some(pattern => pattern.test(data.toString()))) {
      console.error(`${name}: potential private key or service token; value withheld`);
      errors++;
    }
  }
}
if (errors) process.exitCode = 1;
else console.log(`Credential guard passed for ${names.length} ${staged ? 'staged' : 'tracked'} paths.`);
