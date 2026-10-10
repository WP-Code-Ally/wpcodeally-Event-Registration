<#
.SYNOPSIS
  Deploy wpcodeally-event-registration to Cloudways staging via PuTTY pscp.

.PARAMETER CredsFile
  Path to cloudways SSH creds file (default: New Site Staging / onenonlyedm).
#>
[CmdletBinding()]
param(
    [string] $CredsFile = ""
)

$ErrorActionPreference = "Stop"
$DeveloperDir = $PSScriptRoot
$PluginRoot   = (Resolve-Path (Join-Path $DeveloperDir "..")).Path
$pluginFolderName = Split-Path -Leaf $PluginRoot

if (-not $CredsFile) {
    $guess = Join-Path $DeveloperDir "..\..\..\Creds\cloudways-ssh-staging onenonlyedm.com.txt"
    $resolved = Resolve-Path $guess -ErrorAction SilentlyContinue
    if ($resolved) {
        $CredsFile = $resolved.Path
    }
}

if (-not $CredsFile -or -not (Test-Path -LiteralPath $CredsFile)) {
    Write-Error "Creds file not found. Pass -CredsFile path to the Cloudways staging creds file."
}

$raw = Get-Content -LiteralPath $CredsFile -Raw

function Get-CredsLine {
    param([string] $Pattern)
    $m = [regex]::Match($raw, $Pattern, [System.Text.RegularExpressions.RegexOptions]::Multiline)
    if (-not $m.Success) { return $null }
    return $m.Groups[1].Value.Trim()
}

$hostIp   = Get-CredsLine "Host/IP:\s*(.+)"
$user     = Get-CredsLine "SSH Username:\s*(.+)"
$password = Get-CredsLine "SSH Password:\s*(.+)"
$pscpExe  = Get-CredsLine "SCP Exec:\s*(.+)"

if (-not $hostIp -or -not $user -or -not $password) {
    Write-Error "Could not parse Host/IP, SSH Username, or SSH Password from creds file."
}
if (-not $pscpExe -or -not (Test-Path -LiteralPath $pscpExe)) {
    Write-Error "SCP Exec path missing or not found: $pscpExe"
}

$hostKey = Get-CredsLine "PuTTY Host Key[^:]*:\s*(.+)"
$remoteTarget = "${user}@${hostIp}:public_html/wp-content/plugins/"

$stageParent = Join-Path $env:TEMP ("wpca-er-staging-" + [Guid]::NewGuid().ToString())
$stagePlugin = Join-Path $stageParent (Split-Path -Leaf $PluginRoot)
try {
    New-Item -ItemType Directory -Path $stagePlugin -Force | Out-Null
    $robocopyArgs = @(
        $PluginRoot,
        $stagePlugin,
        "/E",
        "/XD",
            ".git",
            ".cursor",
            ".cursor-temp-logs",
            "node_modules",
            "vendor",
            "Developer",
            ".github",
            "tests",
            ".vscode",
            ".idea",
        "/XF",
            ".gitignore",
            "composer.json",
            "composer.lock",
            "package.json",
            "package-lock.json",
            "yarn.lock",
            "pnpm-lock.yaml",
            "phpcs.xml",
            "phpcs.xml.dist",
            "phpunit.xml",
            "phpunit.xml.dist",
            ".php-cs-fixer.php",
            ".php-cs-fixer.dist.php",
            "phpstan.neon",
            "phpstan.neon.dist",
            ".editorconfig",
            ".DS_Store",
            "Thumbs.db",
        "/NFL", "/NDL", "/NJH", "/NJS"
    )
    & robocopy.exe @robocopyArgs
    if ($LASTEXITCODE -ge 8) {
        Write-Error "robocopy staging failed with exit code $LASTEXITCODE"
    }
} catch {
    if (Test-Path -LiteralPath $stageParent) {
        Remove-Item -LiteralPath $stageParent -Recurse -Force -ErrorAction SilentlyContinue
    }
    throw
}

$pscpArgs = @(
    "-batch",
    "-pw", $password
)
if ($hostKey) {
    $pscpArgs += @("-hostkey", $hostKey)
}
$pscpArgs += @(
    "-r",
    $stagePlugin,
    $remoteTarget
)

Write-Host "Deploying plugin folder to staging..."
Write-Host "  Local:  $PluginRoot"
Write-Host "  Staged: $stagePlugin"
Write-Host "  Remote: $remoteTarget"
Write-Host "  pscp:   $pscpExe"

& $pscpExe @pscpArgs
$pscpExit = $LASTEXITCODE
Remove-Item -LiteralPath $stageParent -Recurse -Force -ErrorAction SilentlyContinue
if ($pscpExit -ne 0) {
    exit $pscpExit
}

$plinkCandidates = @(
    (Get-CredsLine "Plink Exec[^:]*:\s*(.+)"),
    "C:\Program Files\PuTTY\plink.exe",
    "C:\Program Files (x86)\PuTTY\plink.exe"
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }
$plinkExe = $plinkCandidates | Select-Object -First 1
if ($plinkExe -and $hostKey) {
    $remoteRmGitignore = "rm -f public_html/wp-content/plugins/$pluginFolderName/.gitignore"
    Write-Host "  Remote cleanup: $remoteRmGitignore"
    & $plinkExe -ssh "${user}@${hostIp}" -hostkey $hostKey -pw $password -batch $remoteRmGitignore 2>&1 | Out-Null

    $remoteRmLegacy = "rm -rf public_html/wp-content/plugins/event-registration"
    Write-Host "  Remote cleanup: $remoteRmLegacy"
    & $plinkExe -ssh "${user}@${hostIp}" -hostkey $hostKey -pw $password -batch $remoteRmLegacy 2>&1 | Out-Null
}

Write-Host "Done."
