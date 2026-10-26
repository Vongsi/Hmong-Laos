<#
.SYNOPSIS
    Starts the site on a Windows dev machine: database, web server, Reverb and the scheduler.

.DESCRIPTION
    1. Puts PHP 8.4 first on PATH for this window and sets OPENSSL_CONF (needed to send phone notifications).
    2. Starts MariaDB in the background if it isn't running yet, and waits until it accepts connections.
    3. Applies new database migrations.
    4. Runs `php artisan dev`: the web server, Reverb (live features) and the scheduler, in this window.
       Ctrl+C stops them; MariaDB keeps running for next time (stop it with -StopDatabase).

    Paths default to the portable installs in %USERPROFILE%\.config; override them with the
    DAH_PHP_DIR and DAH_MARIADB_DIR environment variables.

.PARAMETER Lan
    Also serve on this computer's Wi-Fi address, to open the site on a phone on the same network.
    Windows may ask to allow php.exe through the firewall.

.PARAMETER StopDatabase
    Shut MariaDB down cleanly and exit.

.EXAMPLE
    ./scripts/dev.ps1
    ./scripts/dev.ps1 -Lan
    ./scripts/dev.ps1 -StopDatabase
#>
param([switch]$Lan, [switch]$StopDatabase)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$phpDir = if ($env:DAH_PHP_DIR) { $env:DAH_PHP_DIR } else { Join-Path $env:USERPROFILE '.config\php\8.4' }
$mariaDir = if ($env:DAH_MARIADB_DIR) { $env:DAH_MARIADB_DIR } else { Join-Path $env:USERPROFILE '.config\mariadb' }
$mariaBin = Join-Path $mariaDir '11.4\bin'
$mariaIni = Join-Path $mariaDir 'data\my.ini'

function Test-Port([int]$port) {
    $client = New-Object Net.Sockets.TcpClient
    try { $client.ConnectAsync('127.0.0.1', $port).Wait(300) -and $client.Connected } catch { $false } finally { $client.Dispose() }
}

if ($StopDatabase) {
    if (Test-Port 3306) {
        & (Join-Path $mariaBin 'mariadb-admin.exe') -u root -h 127.0.0.1 shutdown
        Write-Host 'MariaDB stopped.'
    } else {
        Write-Host 'MariaDB is not running.'
    }
    return
}

# 1. PHP 8.4 for this window.
if (Test-Path (Join-Path $phpDir 'php.exe')) {
    $env:Path = "$phpDir;$env:Path"
    $env:OPENSSL_CONF = Join-Path $phpDir 'extras\ssl\openssl.cnf'
}
$version = & php -r 'echo PHP_VERSION;'
if ([version]$version -lt [version]'8.4.1') { throw "PHP $version found; this site needs PHP 8.4.1 or newer (set DAH_PHP_DIR)." }

# 2. MariaDB.
if (-not (Test-Port 3306)) {
    if (-not (Test-Path $mariaIni)) { throw "MariaDB isn't running and $mariaIni was not found (set DAH_MARIADB_DIR)." }
    Write-Host 'Starting MariaDB...'
    Start-Process -FilePath (Join-Path $mariaBin 'mariadbd.exe') -ArgumentList "--defaults-file=`"$mariaIni`"" -WindowStyle Hidden
    $deadline = (Get-Date).AddSeconds(30)
    while (-not (Test-Port 3306)) {
        if ((Get-Date) -gt $deadline) { throw "MariaDB didn't start within 30 seconds; see the .err file in $mariaDir\data." }
        Start-Sleep -Milliseconds 300
    }
}
Write-Host 'MariaDB is running on 127.0.0.1:3306.'

Set-Location $root

# 3. Database up to date.
php artisan migrate --force

# 4. Web server, Reverb and scheduler. -Lan: listen on all addresses and tell browsers to use the Wi-Fi one.
if ($Lan) {
    $ip = (Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1).IPv4Address.IPAddress
    if (-not $ip) { throw 'No Wi-Fi or network address found.' }
    $env:SERVER_HOST = '0.0.0.0'
    $env:REVERB_SERVER_HOST = '0.0.0.0'
    $env:REVERB_HOST = $ip
    $env:APP_URL = "http://${ip}:8000"
    Write-Host "On your phone (same Wi-Fi): http://${ip}:8000"
} else {
    Write-Host 'Open http://127.0.0.1:8000  (control panel: http://127.0.0.1:8000/cp)'
}

php artisan dev
