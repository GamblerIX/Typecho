[CmdletBinding()]
param(
    [Parameter(Position=0)]
    [string]$RootDir = (Get-Location).Path,
    [switch]$VerboseOutput
)

# Helpers
function Write-Info($msg) { Write-Host "[INFO] $msg" -ForegroundColor Cyan }
function Write-ErrorMsg($msg) { Write-Host "[ERROR] $msg" -ForegroundColor Red }
function Write-Success($msg) { Write-Host "[OK] $msg" -ForegroundColor Green }
function Test-Command($name) { $null -ne (Get-Command $name -ErrorAction SilentlyContinue) }

# Environment checks
if (-not (Test-Command 'node')) {
    Write-ErrorMsg "Node.js not found. Please install Node.js: https://nodejs.org/"
    exit 1
}

if (-not (Test-Command 'npx')) {
    Write-Info "npx not found, checking npm availability"
    if (-not (Test-Command 'npm')) {
        Write-ErrorMsg "npm/npx not found. Ensure Node.js installation includes npm."
        exit 1
    }
}

# Resolve npx executable path (Windows uses npx.cmd)
$NpxPath = $null
try { $NpxPath = (Get-Command npx -ErrorAction Stop).Source } catch { $NpxPath = $null }
if (-not $NpxPath) { $NpxPath = 'npx' }

# Prefer npx.cmd over npx.ps1 to avoid PowerShell wrapper quirks
if ($NpxPath -like '*.ps1') {
    $cmdCandidate = Join-Path (Split-Path $NpxPath -Parent) 'npx.cmd'
    if (Test-Path $cmdCandidate) { $NpxPath = $cmdCandidate }
}

# Note: skip pre-check of Prettier to avoid accidental package resolution issues
Write-Info "Using npx executable: $NpxPath"

Write-Info "Scanning directory: $RootDir"
try {
    $files = Get-ChildItem -Path $RootDir -Recurse -File -ErrorAction Stop |
        Where-Object { $_.Extension -in @('.js', '.css') }
} catch {
    Write-ErrorMsg "Directory traversal failed: $($_.Exception.Message)"
    exit 1
}

if ($files.Count -eq 0) {
    Write-Info "No .js or .css files found"
    exit 0
}

Write-Info "Found $($files.Count) files to process"

$success = 0
$failed = 0
$failDetails = @()

foreach ($file in $files) {
    # Select Prettier parser based on file extension
    $parser = if ($file.Extension -eq '.js') { 'babel' } else { 'css' }
    $npxArgs = @('prettier', '--write', $file.FullName, '--log-level', 'warn', '--parser', $parser)

    if ($VerboseOutput) { Write-Host "Formatting: $($file.FullName)" -ForegroundColor DarkGray }

    try {
        & $NpxPath 'prettier' '--write' $file.FullName '--log-level' 'warn' '--parser' $parser
        $exitCode = $LASTEXITCODE
        if ($exitCode -eq 0) {
            Write-Success "Success: $($file.FullName)"
            $success++
        } else {
            Write-ErrorMsg "Failed($exitCode): $($file.FullName)"
            $failed++
            $failDetails += $file.FullName
        }
    } catch {
        Write-ErrorMsg "Exception: $($file.FullName) => $($_.Exception.Message)"
        $failed++
        $failDetails += $file.FullName
    }
}

Write-Host ""
Write-Host "Done. Success: $success, Failed: $failed" -ForegroundColor Yellow

if ($failed -gt 0) {
    Write-Host "Failed files:" -ForegroundColor Yellow
    $failDetails | ForEach-Object { Write-Host " - $_" -ForegroundColor Yellow }
    exit 1
} else {
    exit 0
}