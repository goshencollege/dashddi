#Requires -Version 5.1
# DashDDI dev/test setup for Windows
# Usage: powershell -ExecutionPolicy Bypass -File setup-dev.ps1

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptDir    = Split-Path -Parent $MyInvocation.MyCommand.Path
$ComposeFile  = Join-Path $ScriptDir 'docker-compose.dev.yml'
$DistFile     = Join-Path $ScriptDir 'docker-compose.dev.yml.dist'
$SslDir       = Join-Path $ScriptDir 'docker\ssl'

# ── Helpers ───────────────────────────────────────────────────────────────────
function Header($msg) { Write-Host; Write-Host "── $msg ──" -ForegroundColor Cyan }
function Ok($msg)     { Write-Host "  [+] $msg" -ForegroundColor Green }
function Warn($msg)   { Write-Host "  [!] $msg" -ForegroundColor Yellow }
function Die($msg)    { Write-Host "  [x] $msg" -ForegroundColor Red; exit 1 }

function Ask {
    param($Prompt, $Default = '')
    if ($Default) {
        $val = Read-Host "  $Prompt [$Default]"
        if ([string]::IsNullOrWhiteSpace($val)) { $val = $Default }
    } else {
        $val = Read-Host "  $Prompt"
        if ([string]::IsNullOrWhiteSpace($val)) { Die "$Prompt is required." }
    }
    return $val
}

function AskYn($Prompt, $Default = 'n') {
    $val = Read-Host "  $Prompt [y/N]"
    if ([string]::IsNullOrWhiteSpace($val)) { $val = $Default }
    return $val -match '^y'
}

function RandBytes($count) {
    $buf = New-Object byte[] $count
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($buf)
    return $buf
}

function RandHex($byteCount)    { [BitConverter]::ToString((RandBytes $byteCount)).Replace('-', '').ToLower() }
function RandBase64($byteCount) { [Convert]::ToBase64String((RandBytes $byteCount)) }

Set-Location $ScriptDir

Write-Host
Write-Host 'DashDDI Dev/Test Setup' -ForegroundColor White
Write-Host '  Creates docker-compose.dev.yml and starts the stack with APP_ENV=dev.'
Write-Host

# ── 1. Prerequisites ──────────────────────────────────────────────────────────
Header 'Checking prerequisites'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Die 'docker not found in PATH.' }
Ok 'docker'

docker compose version 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) { Die 'docker compose (plugin v2) is required.' }
Ok 'docker compose'

if (-not (Test-Path $DistFile)) { Die 'docker-compose.dev.yml.dist not found.' }
Ok 'docker-compose.dev.yml.dist'

if (Test-Path $ComposeFile) {
    Warn 'docker-compose.dev.yml already exists.'
    if (-not (AskYn 'Overwrite and re-run full setup?')) {
        Write-Host '  Aborted.'; exit 0
    }
}

# ── 2. Hostname ───────────────────────────────────────────────────────────────
Header 'Hostname'

$Fqdn    = Ask 'Hostname or IP (e.g. localhost, 192.168.1.50, mydev.local)' 'localhost'
$BaseUrl = "https://$Fqdn"
Ok "Base URL: $BaseUrl"

# ── 3. Secrets ────────────────────────────────────────────────────────────────
Header 'Generating secrets'

$AppSecret        = RandHex 32
Ok 'APP_SECRET generated'

$AppEncryptionKey = RandBase64 32
Ok 'APP_ENCRYPTION_KEY generated'

$DbPassword     = RandHex 16
$DbRootPassword = RandHex 16
Ok 'Database passwords generated'

# ── 4. SSL certificate ────────────────────────────────────────────────────────
Header 'SSL Certificate'

if (-not (Test-Path $SslDir)) { New-Item -ItemType Directory -Path $SslDir | Out-Null }

$RegenCert = $true
if ((Test-Path "$SslDir\cert.pem") -and (Test-Path "$SslDir\key.pem")) {
    Warn 'Existing certificate found in docker/ssl/.'
    $RegenCert = AskYn 'Regenerate?'
    if (-not $RegenCert) { Ok 'Keeping existing certificate' }
}

if ($RegenCert) {
    $San = if ($Fqdn -match '^\d+\.\d+\.\d+\.\d+$') { "IP:$Fqdn" } else { "DNS:$Fqdn,IP:127.0.0.1" }

    # Locate openssl: PATH first, then Git for Windows bundled copy
    $opensslExe = $null
    $opensslCmd = Get-Command openssl -ErrorAction SilentlyContinue
    if ($opensslCmd) {
        $opensslExe = $opensslCmd.Source
    } else {
        $gitPaths = @(
            'C:\Program Files\Git\usr\bin\openssl.exe',
            'C:\Program Files\Git\mingw64\bin\openssl.exe'
        )
        foreach ($p in $gitPaths) {
            if (Test-Path $p) { $opensslExe = $p; break }
        }
    }

    if ($opensslExe) {
        & $opensslExe req -x509 -newkey rsa:2048 -nodes `
            -keyout "$SslDir\key.pem" `
            -out    "$SslDir\cert.pem" `
            -days 825 `
            -subj   "/CN=$Fqdn" `
            -addext "subjectAltName=$San" 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Die 'openssl certificate generation failed.' }
    } else {
        # Docker is already a prerequisite — use it to run openssl in a container
        Warn 'openssl not found in PATH or Git for Windows — generating certificate via Docker.'
        $dockerSslDir = $SslDir -replace '\\', '/'
        docker run --rm -v "${dockerSslDir}:/ssl" alpine sh -c `
            "apk add --no-cache openssl -q 2>/dev/null && openssl req -x509 -newkey rsa:2048 -nodes -keyout /ssl/key.pem -out /ssl/cert.pem -days 825 -subj /CN=$Fqdn -addext subjectAltName=$San 2>/dev/null"
        if ($LASTEXITCODE -ne 0) { Die 'Docker-based certificate generation failed.' }
    }

    Ok 'Self-signed certificate written to docker/ssl/'
    Warn 'Browsers will show a security warning — expected for dev/test.'
}

# ── 5. Write docker-compose.dev.yml ──────────────────────────────────────────
Header 'Writing docker-compose.dev.yml'

$content = [IO.File]::ReadAllText($DistFile)
$content = $content.Replace('replace_with_32plus_char_secret',                                          $AppSecret)
$content = $content.Replace('run: docker compose exec app php bin/console app:generate-encryption-key', $AppEncryptionKey)
$content = $content.Replace('https://your-dev-hostname.example.com',                                    $BaseUrl)
$content = $content.Replace('ipam_password',                                                            $DbPassword)
$content = $content.Replace('root_password',                                                            $DbRootPassword)
[IO.File]::WriteAllText($ComposeFile, $content)

Ok 'docker-compose.dev.yml written'

# ── 6. Build image ────────────────────────────────────────────────────────────
Header 'Building image'

docker compose -f $ComposeFile build app
if ($LASTEXITCODE -ne 0) { Die 'Image build failed.' }
Ok 'Image built'

# ── 7. PHP dependencies ───────────────────────────────────────────────────────
Header 'Installing PHP dependencies'

$composeConfig  = docker compose -f $ComposeFile config 2>$null | Out-String
$composeProject = if ($composeConfig -match '(?m)^name:\s*(.+)$') { $Matches[1].Trim() }
                  else { (Split-Path -Leaf $ScriptDir).ToLower() }

docker run --rm `
    --volume "${ScriptDir}:/var/www/html" `
    --workdir /var/www/html `
    --env COMPOSER_HOME=/tmp/composer `
    "${composeProject}-app" `
    composer install --no-interaction --no-progress
if ($LASTEXITCODE -ne 0) { Die 'composer install failed.' }
Ok 'Dependencies installed'

# ── 8. Start services ─────────────────────────────────────────────────────────
Header 'Starting services'

docker compose -f $ComposeFile up -d
if ($LASTEXITCODE -ne 0) { Die 'Failed to start containers.' }
Ok 'Containers started'

Write-Host '  Waiting for the app container to be ready...'
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    $result = docker compose -f $ComposeFile exec -T app php -r 'echo "ok";' 2>$null
    if ($result -match 'ok') { $ready = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) { Die 'App container did not become ready in time. Check: docker compose -f docker-compose.dev.yml logs app' }
Ok 'App container ready'

# ── 9. Database migrations ────────────────────────────────────────────────────
Header 'Running database migrations'

docker compose -f $ComposeFile exec -T app `
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
if ($LASTEXITCODE -ne 0) { Die 'Migrations failed.' }
Ok 'Migrations complete'

# ── 10. Cache warmup ──────────────────────────────────────────────────────────
Header 'Warming up cache'

docker compose -f $ComposeFile exec -T app php bin/console cache:warmup
if ($LASTEXITCODE -ne 0) { Die 'Cache warmup failed.' }
Ok 'Cache warm'

# ── 11. SAML identity provider (optional) ────────────────────────────────────
Header 'SAML Identity Provider'
Write-Host
Write-Host '  SP metadata URL (give this to your IdP administrator):'
Write-Host "  $BaseUrl/saml/metadata" -ForegroundColor Cyan
Write-Host
$SamlSource = Read-Host '  Path to IdP metadata XML file, or URL (leave blank to skip)'

if (-not [string]::IsNullOrWhiteSpace($SamlSource)) {
    $SamlName = Ask "Provider name (e.g. 'Okta', 'Azure AD')" 'IdP'
    $SamlTmp  = $null

    if ($SamlSource -match '^https?://') {
        $SamlArg = $SamlSource
    } else {
        if (-not (Test-Path $SamlSource)) { Die "File not found: $SamlSource" }
        $SamlTmp = Join-Path $ScriptDir '.saml-setup-metadata.xml'
        Copy-Item $SamlSource $SamlTmp
        $SamlArg = '/var/www/html/.saml-setup-metadata.xml'
    }

    docker compose -f $ComposeFile exec -T app `
        php bin/console app:saml:import-metadata $SamlArg --name="$SamlName" --activate
    if ($LASTEXITCODE -ne 0) { Die 'SAML import failed.' }

    if ($SamlTmp) { Remove-Item $SamlTmp -Force }
    Ok "SAML provider '$SamlName' imported and set as active"
} else {
    Warn 'Skipped — configure SAML later with:'
    Warn '  docker compose -f docker-compose.dev.yml exec app php bin/console app:saml:import-metadata <file-or-url> --activate'
}

# ── 12. Summary ───────────────────────────────────────────────────────────────
Header 'Setup complete'
Write-Host
Write-Host "  DashDDI (dev) is running at:  $BaseUrl" -ForegroundColor Cyan
Write-Host
Write-Host '  MySQL credentials (save these — they are not stored elsewhere):' -ForegroundColor White
Write-Host "    App user:  ipam / $DbPassword"
Write-Host "    Root:      root / $DbRootPassword"
Write-Host
Warn 'Self-signed cert in use — browsers will show a security warning.'
Write-Host
Write-Host '  Common commands:'
Write-Host '    Logs:     docker compose -f docker-compose.dev.yml logs -f'
Write-Host '    Stop:     docker compose -f docker-compose.dev.yml down'
Write-Host '    Start:    docker compose -f docker-compose.dev.yml up -d'
Write-Host '    Migrate:  docker compose -f docker-compose.dev.yml exec app php bin/console doctrine:migrations:migrate'
Write-Host
Write-Host "  SP metadata URL:  $BaseUrl/saml/metadata" -ForegroundColor Cyan
Write-Host
