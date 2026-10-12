param(
    [string]$XamppRoot = 'C:\xampp',
    [string]$AppUrl = 'http://localhost/XAI'
)

$ErrorActionPreference = 'Stop'

$mysqlExe = Join-Path $XamppRoot 'mysql\bin\mysqld.exe'
$mysqlConfig = Join-Path $XamppRoot 'mysql\bin\my.ini'
$mlRoot = Join-Path $PSScriptRoot 'ml'
$pythonExe = Join-Path $mlRoot '.venv\Scripts\python.exe'

function Test-LocalPort([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $result = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (-not $result.AsyncWaitHandle.WaitOne(1000, $false)) {
            return $false
        }
        $client.EndConnect($result)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Wait-ForLocalPort([int]$Port, [int]$TimeoutSeconds) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-LocalPort $Port) {
            return
        }
        Start-Sleep -Seconds 1
    }
    throw "Service did not start listening on 127.0.0.1:$Port within $TimeoutSeconds seconds."
}

function Test-MlHealth {
    try {
        $health = Invoke-RestMethod -Uri 'http://127.0.0.1:5001/health' -TimeoutSec 2
        return $health.status -eq 'ok'
    } catch {
        return $false
    }
}

if (-not (Test-LocalPort 3306)) {
    if (-not (Test-Path $mysqlExe) -or -not (Test-Path $mysqlConfig)) {
        throw "XAMPP MySQL was not found under '$XamppRoot'. Start MySQL in the XAMPP Control Panel."
    }
    Start-Process -FilePath $mysqlExe -ArgumentList @("--defaults-file=$mysqlConfig") `
        -WorkingDirectory (Split-Path $mysqlExe) -WindowStyle Hidden
    Wait-ForLocalPort 3306 30
}

if (-not (Test-MlHealth)) {
    if (-not (Test-Path $pythonExe)) {
        throw "The ML virtual environment was not found at '$pythonExe'. Create it and install ml\requirements.txt as described in README.md."
    }
    Start-Process -FilePath $pythonExe -ArgumentList @('app.py') `
        -WorkingDirectory $mlRoot -WindowStyle Hidden
    $deadline = (Get-Date).AddSeconds(45)
    while ((Get-Date) -lt $deadline -and -not (Test-MlHealth)) {
        Start-Sleep -Seconds 1
    }
    if (-not (Test-MlHealth)) {
        throw 'The ML service did not become healthy on port 5001. Run ml\.venv\Scripts\python.exe app.py interactively to inspect the startup error.'
    }
}

$apiUrl = $AppUrl.TrimEnd('/') + '/backend/index.php?action=csrf'
$api = Invoke-RestMethod -Uri $apiUrl -TimeoutSec 10
if (-not $api.csrf_token) {
    throw 'MySQL is listening, but the PHP API could not establish its database session. Check the XAMPP Apache and MySQL logs.'
}

Write-Output 'Ready: MySQL, the local ML service, and the PHP API are responding.'
