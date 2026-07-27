@echo off
REM Flag starter uplift due when workspace opens / session starts (C16).
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0flag-starter-uplift.ps1"
exit /b %ERRORLEVEL%
