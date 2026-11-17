@echo off
setlocal
echo WhatsApp production preparation - masked access token entry
echo This saves a separate encrypted candidate. It does not deploy or send messages.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0prepare-whatsapp-production.ps1"
if errorlevel 1 echo Preparation did not finish. Review the message above.
pause
