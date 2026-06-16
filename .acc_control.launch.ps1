$ErrorActionPreference = 'Stop'

$projectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$startPs1 = Join-Path $projectDir '.acc_control.ps1'
$logPath = Join-Path $projectDir '.acc_control.last.log'

$wtCommand = Get-Command wt.exe -ErrorAction SilentlyContinue
$wtExe = if ($wtCommand) { $wtCommand.Source } else { $null }

if (-not $wtExe) {
    $candidate = Join-Path $env:LOCALAPPDATA 'Microsoft\WindowsApps\wt.exe'
    if (Test-Path -LiteralPath $candidate -ErrorAction SilentlyContinue) {
        $wtExe = $candidate
    }
}

if (-not $wtExe) {
    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Windows Terminal wt.exe not found." | Set-Content -LiteralPath $logPath -Encoding UTF8
    throw 'Windows Terminal wt.exe was not found.'
}

$argumentList = @(
    '-d',
    ('"{0}"' -f $projectDir),
    'powershell.exe',
    '-NoExit',
    '-ExecutionPolicy',
    'Bypass',
    '-File',
    ('"{0}"' -f $startPs1)
) -join ' '

"[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Launching Windows Terminal: $wtExe $argumentList" | Set-Content -LiteralPath $logPath -Encoding UTF8
Start-Process -FilePath $wtExe -Verb RunAs -ArgumentList $argumentList
