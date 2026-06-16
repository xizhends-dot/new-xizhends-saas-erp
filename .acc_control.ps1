$ErrorActionPreference = 'Stop'

$projectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$codexExe = 'C:\Users\27520\AppData\Local\OpenAI\Codex\bin\3f4fb8cdd344abc7\codex.exe'
$logPath = Join-Path $projectDir '.acc_control.last.log'

if (-not $env:WT_SESSION) {
    $env:WT_SESSION = [guid]::NewGuid().ToString()
}

if (-not $env:WT_PROFILE_ID) {
    $env:WT_PROFILE_ID = [guid]::NewGuid().ToString()
}

$env:TERM = 'xterm-256color'
$env:COLORTERM = 'truecolor'
$env:TERM_PROGRAM = 'Windows_Terminal'
$env:TERM_PROGRAM_VERSION = '1.0'
$env:FORCE_COLOR = '1'
$env:CLICOLOR_FORCE = '1'
Remove-Item Env:NO_COLOR -ErrorAction SilentlyContinue

Set-Location -LiteralPath $projectDir
"[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Starting Codex. WT_SESSION=$env:WT_SESSION WT_PROFILE_ID=$env:WT_PROFILE_ID TERM=$env:TERM COLORTERM=$env:COLORTERM TERM_PROGRAM=$env:TERM_PROGRAM" | Add-Content -LiteralPath $logPath -Encoding UTF8
& $codexExe -a never -s danger-full-access resume
