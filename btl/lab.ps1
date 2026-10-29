# PowerShell script for OpenShare on Windows
param (
    [string]$Action = "help",
    [string]$Option = "--preview"
)

$PSScriptRoot = Split-Path -Parent -Path $MyInvocation.MyCommand.Definition
Set-Location -Path $PSScriptRoot

function Check-Environment {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if (-not $phpCmd) {
        Write-Host "THIEU_PHP: PHP chua duoc cai dat hoac chua them vao bien moi truong PATH." -ForegroundColor Red
        return $false
    }

    $verCheck = php -r "exit(PHP_VERSION_ID >= 80100 ? 0 : 1);"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "CAN_PHP_8_1_TRO_LEN: Yeu cau PHP 8.1 tro len." -ForegroundColor Red
        return $false
    }

    $driver = if ($env:OPENSHARE_DB_DRIVER) { $env:OPENSHARE_DB_DRIVER } else { "sqlite" }
    $driverCheck = php -r "exit(in_array('$driver', PDO::getAvailableDrivers(), true) ? 0 : 1);"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "CAN_PDO_$driver : Thieu extension PDO cho $driver." -ForegroundColor Red
        return $false
    }

    Write-Host "MOI_TRUONG=SAN_SANG" -ForegroundColor Green
    return $true
}

switch ($Action.ToLower()) {
    "check" {
        $ok = Check-Environment
        if (-not $ok) { exit 2 }
    }
    "run" {
        $ok = Check-Environment
        if (-not $ok) { exit 2 }
        if (-not (Test-Path "$PSScriptRoot\storage\files")) {
            New-Item -ItemType Directory -Path "$PSScriptRoot\storage\files" -Force | Out-Null
        }
        if (-not (Test-Path "$PSScriptRoot\storage\sessions")) {
            New-Item -ItemType Directory -Path "$PSScriptRoot\storage\sessions" -Force | Out-Null
        }
        Write-Host "Dang khoi chay OpenShare Web tai: http://127.0.0.1:8127" -ForegroundColor Cyan
        php -d "session.save_path=$PSScriptRoot\storage\sessions" -S 127.0.0.1:8127 -t public
    }
    "test" {
        $ok = Check-Environment
        if (-not $ok) { exit 2 }
        php tests/run.php
    }
    "reset" {
        php -r "require 'src/bootstrap.php'; resetStore(); echo 'DU_LIEU_DA_KHOI_TAO' . PHP_EOL;"
    }
    "cleanup" {
        php tools/cleanup.php $Option
    }
    default {
        Write-Host "Cach dung: .\lab.ps1 check | run | test | reset | cleanup [--delete]" -ForegroundColor Yellow
    }
}
