"""Local readiness suite. Never loads production env, sends mail, migrates live DB or deploys."""
import argparse
import hashlib
import json
import shutil
import subprocess
import sys
import time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'.sms-audit.local/email-ses-readiness-20261030'

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--mysql',action='store_true',help='Start and remove a fresh loopback-only MariaDB fixture on port 33161');args=parser.parse_args()
    php=shutil.which('php');node=shutil.which('node');powershell=shutil.which('powershell.exe')
    if not php or not node:raise RuntimeError('Local PHP and Node are required')
    commands=[[php,'api/tests/run-email-ses.php'],[php,'api/tests/run-email-readmodel.php'],[php,'api/tests/run-email-preferences-http.php'],[php,'api/tests/run-unit.php'],[php,'api/tests/run-provider-unit.php'],[php,'api/tests/run-credit-transfers.php'],[sys.executable,'scripts/test-email-ses-preparation.py'],[sys.executable,'scripts/test-amazon-ses-setup.py'],[node,'node_modules/typescript/bin/tsc','-p','tsconfig.app.json','--noEmit'],[node,'node_modules/vitest/vitest.mjs','run','src/pages/EmailCampaigns.test.tsx','src/components/wallet/CreditTransfers.test.tsx','--no-file-parallelism','--testTimeout=15000']]
    if args.mysql:
        if not powershell:raise RuntimeError('This isolated MySQL harness requires PowerShell')
        commands.append([powershell,'-NoProfile','-ExecutionPolicy','Bypass','-File','scripts/test-email-campaigns-mysql.ps1'])
    results=[];OUT.mkdir(parents=True,exist_ok=True);stamp=time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())
    report=OUT/('local-verification-'+stamp+'.json')
    for command in commands:
        print('Checking '+command[1],flush=True)
        start=time.monotonic();result=subprocess.run(command,cwd=ROOT,capture_output=True,text=True,timeout=240)
        results.append({'command':command[1:],'passed':result.returncode==0,'duration_seconds':round(time.monotonic()-start,2),'stdout':result.stdout,'stderr':result.stderr})
        report.write_text(json.dumps({'passed':len(results)==len(commands) and all(x['passed'] for x in results),'completed_commands':len(results),'planned_commands':len(commands),'at_utc':stamp,'tests':results,'production_changes':False,'cloud_changes':False,'emails_sent':False,'credits_moved':False},indent=2),encoding='utf-8')
        if result.returncode:print('FAILED: '+command[1]+'; inspect private verification receipt.');return 1
    print('All '+str(len(commands))+' local command suites passed. Receipt: '+str(report.relative_to(ROOT)),flush=True)
    return 0
if __name__=='__main__':raise SystemExit(main())
