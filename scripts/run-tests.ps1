<#
.SYNOPSIS
Runs the project test suite.

.DESCRIPTION
Executes the PHP seed verification and backend API smoke tests to confirm
database state and service logic are healthy. Call from the repository root.

.EXAMPLE
.\scripts\run-tests.ps1

.EXAMPLE
.\scripts\run-tests.ps1 -SkipSeed
Runs only the API backend test and skips the seed verification.
#>
[CmdletBinding()]
param (
    [switch]$SkipSeed
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

function Invoke-PhpTest {
    param (
        [Parameter(Mandatory = $true)]
        [string]$RelativePath
    )

    $fullPath = Join-Path $projectRoot $RelativePath
    Write-Host ("Running php {0}" -f $RelativePath) -ForegroundColor Yellow
    php $fullPath
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed: php $RelativePath"
    }
}

Write-Host "Running project tests..." -ForegroundColor Cyan

if (-not $SkipSeed) {
    Invoke-PhpTest -RelativePath 'tests\seed_verification.php'
}

Invoke-PhpTest -RelativePath 'tests\api_backend_test.php'

Write-Host "All tests completed successfully." -ForegroundColor Green
