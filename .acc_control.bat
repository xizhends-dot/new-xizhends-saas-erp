@echo off
setlocal
set "LAUNCH_PS1=%~dp0.acc_control.launch.ps1"

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%LAUNCH_PS1%"
if errorlevel 1 pause
