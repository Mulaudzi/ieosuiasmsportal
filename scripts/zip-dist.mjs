import { existsSync } from 'node:fs';
import { rm } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { join, resolve } from 'node:path';

const rootDir = resolve(process.cwd());
const distDir = join(rootDir, 'dist');
const apiDir = join(rootDir, 'api');
const zipPath = join(rootDir, 'dist.zip');

if (!existsSync(distDir)) {
  console.error('dist folder not found. Run the frontend build before zipping.');
  process.exit(1);
}

if (!existsSync(apiDir)) {
  console.error('api folder not found. Cannot package backend files.');
  process.exit(1);
}

if (existsSync(zipPath)) {
  await rm(zipPath, { force: true });
}

const run = (command, args, options = {}) => new Promise((resolveRun, rejectRun) => {
  const child = spawn(command, args, {
    cwd: options.cwd ?? rootDir,
    stdio: 'inherit',
    shell: false,
  });

  child.on('error', rejectRun);
  child.on('exit', (code) => {
    if (code === 0) {
      resolveRun();
      return;
    }

    rejectRun(new Error(`${command} exited with code ${code ?? 'unknown'}`));
  });
});

try {
  if (process.platform === 'win32') {
    await run('powershell.exe', [
      '-NoProfile',
      '-Command',
      "Compress-Archive -Path 'api','dist/*' -DestinationPath 'dist.zip' -Force",
    ], { cwd: rootDir });
  } else {
    await run('zip', ['-rq', zipPath, 'api'], { cwd: rootDir });
    await run('zip', ['-rq', '-g', zipPath, '.'], { cwd: distDir });
  }

  console.log(`Created ${zipPath}`);
} catch (error) {
  console.error('Failed to create dist.zip');
  console.error(error instanceof Error ? error.message : error);
  process.exit(1);
}