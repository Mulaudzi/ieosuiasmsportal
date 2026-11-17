@echo off
setlocal
echo Correct the undeployed WhatsApp App Secret - local encrypted settings only
echo Copy App Secret from Meta App settings, Basic. Do not use the WhatsApp access token.
echo Previous encrypted settings will be preserved. Nothing is deployed or sent.
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0prepare-whatsapp-webhook.ps1" -ReplaceAppSecret
if errorlevel 1 echo Correction did not finish. Review the message above.
pause
