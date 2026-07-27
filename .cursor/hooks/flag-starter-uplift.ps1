#Requires -Version 5.1
<#
.SYNOPSIS
  Cursor hook: flag starter uplift only when Development Control has changed (C16 delta).

.DESCRIPTION
  If .cursor/starter-state.json has started=true, compare recorded lastDevControlHead
  to current development-control git HEAD. Only write gitignored .cursor/starter-uplift-due
  when missing/different (or HEAD unreadable — fail open to flag once).
#>
$ErrorActionPreference = 'Continue'

$raw = ''
try { $raw = [Console]::In.ReadToEnd() } catch { $raw = '' }

$hookEvent = 'unknown'
$root = $null
if (-not [string]::IsNullOrWhiteSpace($raw)) {
    try {
        $payload = $raw | ConvertFrom-Json
        if ($payload.hook_event_name) { $hookEvent = [string]$payload.hook_event_name }
        if ($payload.workspace_roots -and $payload.workspace_roots.Count -gt 0) {
            $root = [string]$payload.workspace_roots[0]
        }
    } catch { }
}
if (-not $root -and $env:CURSOR_PROJECT_DIR -and (Test-Path -LiteralPath $env:CURSOR_PROJECT_DIR)) {
    $root = $env:CURSOR_PROJECT_DIR
}
if (-not $root) { $root = (Get-Location).Path }

function Get-DevControlRoot {
    param($ProjectRoot, $State)
    if ($State.devRoot) {
        $candidate = Join-Path ([string]$State.devRoot) 'development-control'
        if (Test-Path -LiteralPath $candidate) { return $candidate }
    }
    $dir = $ProjectRoot
    for ($i = 0; $i -lt 6; $i++) {
        $sib = Join-Path $dir 'development-control'
        if (Test-Path -LiteralPath $sib) { return $sib }
        $parent = Split-Path -Parent $dir
        if (-not $parent -or $parent -eq $dir) { break }
        $dir = $parent
    }
    return $null
}

function Get-GitHead {
    param([string]$RepoPath)
    try {
        $gitDirFile = Join-Path $RepoPath '.git'
        if (-not (Test-Path -LiteralPath $gitDirFile)) { return $null }
        Push-Location -LiteralPath $RepoPath
        $h = (& git rev-parse HEAD 2>$null)
        Pop-Location
        if ($h) { return $h.Trim() }
    } catch {
        try { Pop-Location } catch { }
    }
    return $null
}

$cursorDir = Join-Path $root '.cursor'
$statePath = Join-Path $cursorDir 'starter-state.json'
$flagPath = Join-Path $cursorDir 'starter-uplift-due'
$flagged = $false
$reason = ''

if (Test-Path -LiteralPath $statePath) {
    try {
        $state = Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($state.started -eq $true) {
            # Product projects track development-control HEAD. The control-plane repo
            # itself must not flag on every local commit — skip auto-flag there.
            $isControlPlane = $false
            if ($state.projectName -eq 'development-control') { $isControlPlane = $true }
            if ((Split-Path -Leaf $root) -eq 'development-control') { $isControlPlane = $true }

            if ($isControlPlane) {
                if (Test-Path -LiteralPath $flagPath) {
                    Remove-Item -LiteralPath $flagPath -Force -ErrorAction SilentlyContinue
                }
            } else {
            $dcRoot = Get-DevControlRoot -ProjectRoot $root -State $state
            $currentHead = $null
            if ($dcRoot) { $currentHead = Get-GitHead -RepoPath $dcRoot }
            $recorded = $null
            if ($state.lastDevControlHead) { $recorded = [string]$state.lastDevControlHead }

            $needs = $false
            if (-not $recorded) {
                $needs = $true
                $reason = 'no lastDevControlHead recorded'
            } elseif (-not $currentHead) {
                $needs = $true
                $reason = 'could not read development-control HEAD'
            } elseif ($recorded -ne $currentHead) {
                $needs = $true
                $reason = "development-control HEAD changed ($recorded -> $currentHead)"
            }

            if ($needs) {
                if (-not (Test-Path -LiteralPath $cursorDir)) {
                    New-Item -ItemType Directory -Force -Path $cursorDir | Out-Null
                }
                $stamp = (Get-Date).ToUniversalTime().ToString('o')
                $body = @(
                    'upliftDue=true'
                    "flaggedAt=$stamp"
                    "reason=$reason"
                    "currentDevControlHead=$currentHead"
                    "recordedDevControlHead=$recorded"
                ) -join "`r`n"
                [System.IO.File]::WriteAllText($flagPath, $body + "`r`n")
                $flagged = $true
            } elseif (Test-Path -LiteralPath $flagPath) {
                Remove-Item -LiteralPath $flagPath -Force -ErrorAction SilentlyContinue
            }
            } # end product-project branch
        }
    } catch {
        $flagged = $false
    }
}

if ($hookEvent -eq 'sessionStart' -and $flagged) {
    $context = "C16 delta: starter uplift due ($reason). Run development/development-control/new-project-start.md adopt/uplift FIRST (audit deltas only). Update lastDevControlHead; delete .cursor/starter-uplift-due when done."
    @{ additional_context = $context } | ConvertTo-Json -Compress
} else {
    '{}'
}
exit 0
