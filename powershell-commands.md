# PowerShell commands - woo-mailerlite-product-cust-upoad

Run these **manually** in PowerShell to save Cursor tokens. Open the project root (or `scripts\` subfolder) before running relative paths.

**Maintained (C15):** any new or materially changed `.ps1` / root `.cmd` must update this file in the same commit.

**Generated:** 2026-07-27 10:15 - Regenerate: `development-control\scripts\Update-ProjectPowerShellCommands.ps1 -ProjectPath '...'`

## Git (remote - pause OneDrive)

| Command | What it does |
|---------|--------------|
| `cd "c:\Users\LeeCarter\OneDrive - Chameleon Codewing Ltd\development\development-control\scripts"` | Change to shared Git helper folder |
| `.\onedrive-dev-sync.ps1 -AroundGit push -u origin HEAD` | Stop OneDrive, push, resume OneDrive |
| `.\onedrive-dev-sync.ps1 -AroundGit pull --ff-only` | Stop OneDrive, pull, resume |
| `.\onedrive-dev-sync.ps1 -Status` | Show OneDrive running state |

Local Git (`status`, `commit`, `diff`) does **not** need the helper.

## Project scripts

*No project `.ps1` / root `.cmd` files found (excluding `.cursor/hooks`, `archive/`).*
