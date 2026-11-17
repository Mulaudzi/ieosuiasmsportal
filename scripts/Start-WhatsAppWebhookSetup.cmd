@echo off
setlocal
echo WhatsApp webhook preparation - local secret entry only
echo This does not deploy, publish the Meta app, or send messages.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0prepare-whatsapp-webhook.ps1"
if errorlevel 1 echo Setup did not finish. Review the message above.
pause
