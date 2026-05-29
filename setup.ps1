#Requires -Version 5.1
# DashDDI setup — development or production.
# Usage: powershell -ExecutionPolicy Bypass -File setup.ps1

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptDir       = Split-Path -Parent $MyInvocation.MyCommand.Path
$SslDir          = Join-Path $ScriptDir 'docker\ssl'
$ComposeProject  = (Split-Path -Leaf $ScriptDir).ToLower()
$AppImage        = "$ComposeProject-app"

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

function AskSecret {
    param($Prompt)
    $val = Read-Host "  $Prompt" -AsSecureString
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
        [Runtime.InteropServices.Marshal]::SecureStringToBSTR($val))
    if ([string]::IsNullOrWhiteSpace($plain)) { Die "$Prompt is required." }
    return $plain
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

# ── Environment ───────────────────────────────────────────────────────────────
Write-Host
Write-Host 'DashDDI Setup' -ForegroundColor White
Write-Host
Write-Host '  1) Development / test  (self-signed cert, containerised DB, dev tools)'
Write-Host '  2) Production          (SSL options, optional external DB, hardened containers)'
Write-Host
$EnvChoice = Ask 'Environment' '1'

switch ($EnvChoice) {
    '1' { $AppEnv = 'dev' }
    '2' { $AppEnv = 'prod' }
    default { Die 'Invalid choice.' }
}

$ComposeFile = Join-Path $ScriptDir "docker-compose.$AppEnv.yml"

Write-Host
if ($AppEnv -eq 'dev') {
    Write-Host 'DashDDI Development Setup' -ForegroundColor White
    Write-Host '  Creates docker-compose.dev.yml and starts the stack with APP_ENV=dev.'
} else {
    Write-Host 'DashDDI Production Setup' -ForegroundColor White
    Write-Host '  Creates docker-compose.prod.yml and starts the stack.'
    Write-Host '  Have your IdP SAML metadata (XML file or URL) ready before you begin.'
}
Write-Host

# ── 1. Prerequisites ──────────────────────────────────────────────────────────
Header 'Checking prerequisites'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Die 'docker not found in PATH.' }
Ok 'docker'

docker compose version 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) { Die 'docker compose (plugin v2) is required.' }
Ok 'docker compose'

if ($AppEnv -eq 'dev') {
    $DistFile = Join-Path $ScriptDir 'docker-compose.dev.yml.dist'
    if (-not (Test-Path $DistFile)) { Die 'docker-compose.dev.yml.dist not found.' }
    Ok 'docker-compose.dev.yml.dist'
}

if (Test-Path $ComposeFile) {
    Warn "docker-compose.$AppEnv.yml already exists."
    if (-not (AskYn 'Overwrite and re-run full setup?')) {
        Write-Host '  Aborted.'; exit 0
    }
    docker compose -f $ComposeFile down -v 2>&1 | Out-Null
}

# ── 2. Base URL ───────────────────────────────────────────────────────────────
Header 'Base URL'

if ($AppEnv -eq 'dev') {
    $Fqdn      = Ask 'Hostname or IP (e.g. localhost, 192.168.1.50, mydev.local)' 'localhost'
    $HttpPort  = Ask 'HTTP port'  '8080'
    $HttpsPort = Ask 'HTTPS port' '8443'
} else {
    $Fqdn      = Ask 'Fully-qualified domain name (e.g. dashddi.example.com)'
    $HttpPort  = Ask 'HTTP port'  '80'
    $HttpsPort = Ask 'HTTPS port' '443'
}

if ($HttpsPort -eq '443') {
    $BaseUrl = "https://$Fqdn"
} else {
    $BaseUrl = "https://${Fqdn}:$HttpsPort"
}
Ok "Base URL: $BaseUrl"

# ── 3. Secrets ────────────────────────────────────────────────────────────────
Header 'Generating secrets'

$AppSecret        = RandHex 32
Ok 'APP_SECRET generated'

$AppEncryptionKey = RandBase64 32
Ok 'APP_ENCRYPTION_KEY generated'

# ── 4. SSL certificate ────────────────────────────────────────────────────────
Header 'SSL Certificate'

if (-not (Test-Path $SslDir)) { New-Item -ItemType Directory -Path $SslDir | Out-Null }

function GenerateSelfSignedCert($fqdn, $keySize) {
    $san = if ($fqdn -match '^\d+\.\d+\.\d+\.\d+$') { "IP:$fqdn" } else { "DNS:$fqdn,IP:127.0.0.1" }

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
        & $opensslExe req -x509 -newkey "rsa:$keySize" -nodes `
            -keyout "$SslDir\key.pem" `
            -out    "$SslDir\cert.pem" `
            -days 825 `
            -subj   "/CN=$fqdn" `
            -addext "subjectAltName=$san" 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Die 'openssl certificate generation failed.' }
    } else {
        Warn 'openssl not found in PATH or Git for Windows — generating certificate via Docker.'
        $dockerSslDir = $SslDir -replace '\\', '/'
        docker run --rm -v "${dockerSslDir}:/ssl" alpine sh -c `
            "apk add --no-cache openssl -q 2>/dev/null && openssl req -x509 -newkey rsa:$keySize -nodes -keyout /ssl/key.pem -out /ssl/cert.pem -days 825 -subj /CN=$fqdn -addext subjectAltName=$san 2>/dev/null"
        if ($LASTEXITCODE -ne 0) { Die 'Docker-based certificate generation failed.' }
    }
}

if ($AppEnv -eq 'dev') {
    $RegenCert = $true
    if ((Test-Path "$SslDir\cert.pem") -and (Test-Path "$SslDir\key.pem")) {
        Warn 'Existing certificate found in docker/ssl/.'
        $RegenCert = AskYn 'Regenerate?'
        if (-not $RegenCert) { Ok 'Keeping existing certificate' }
    }

    if ($RegenCert) {
        GenerateSelfSignedCert $Fqdn 2048
        Ok 'Self-signed certificate written to docker/ssl/'
        Warn 'Browsers will show a security warning — expected for dev/test.'
    }
} else {
    Write-Host '  1) Generate self-signed certificate (fine for internal/testing use)'
    Write-Host '  2) Use Let''s Encrypt  (requires certbot, public DNS, and port 80 reachable)'
    Write-Host '  3) I will provide my own certificate'
    Write-Host
    $SslChoice = Ask 'Choice' '1'

    switch ($SslChoice) {
        '1' {
            Write-Host
            Write-Host '  Generating self-signed RSA-4096 certificate (valid 10 years)...'
            GenerateSelfSignedCert $Fqdn 4096
            Ok 'Certificate written to docker/ssl/'
            Warn 'Browsers will show a security warning for self-signed certs.'
            Warn 'For a trusted cert, install certbot and run ''certbot certonly --standalone'''
            Warn 'then replace docker/ssl/cert.pem and key.pem with the issued files.'
        }
        '2' {
            if (-not (Get-Command certbot -ErrorAction SilentlyContinue)) { Die 'certbot is not installed.' }
            Write-Host
            Warn 'Port 80 must be reachable from the internet for the HTTP-01 challenge.'
            Warn 'Stop any service listening on port 80 before continuing.'
            Write-Host
            certbot certonly --standalone -d $Fqdn --non-interactive --agree-tos --register-unsafely-without-email
            if ($LASTEXITCODE -ne 0) { Die 'certbot failed — check the output above.' }
            Copy-Item "/etc/letsencrypt/live/$Fqdn/fullchain.pem" "$SslDir\cert.pem"
            Copy-Item "/etc/letsencrypt/live/$Fqdn/privkey.pem"   "$SslDir\key.pem"
            Ok "Let's Encrypt certificate copied to docker/ssl/"
            Warn 'For certificate renewal, run:'
            Warn '  certbot renew'
            Warn "  Copy-Item /etc/letsencrypt/live/$Fqdn/fullchain.pem $SslDir\cert.pem"
            Warn "  Copy-Item /etc/letsencrypt/live/$Fqdn/privkey.pem $SslDir\key.pem"
            Warn "  docker run --rm -v ${ComposeProject}_ssl_certs:/ssl -v ${SslDir}:/src:ro alpine sh -c 'cp /src/cert.pem /src/key.pem /ssl/'"
            Warn '  docker compose -f docker-compose.prod.yml restart nginx'
        }
        '3' {
            if ((Test-Path "$SslDir\cert.pem") -and (Test-Path "$SslDir\key.pem")) {
                Ok 'Found existing docker/ssl/cert.pem and key.pem'
            } else {
                Warn 'No certificate found at docker/ssl/ — copy cert.pem and key.pem there before starting.'
            }
        }
        default { Die 'Invalid choice.' }
    }
}

# ── 5. Database ───────────────────────────────────────────────────────────────
$UseContainerDb = $true

if ($AppEnv -eq 'prod') {
    Header 'Database (MySQL 8)'
    Write-Host '  1) Run MySQL in a container  (recommended for standalone deployments)'
    Write-Host '  2) Use an external MySQL server'
    Write-Host
    $DbChoice = Ask 'Choice' '1'

    switch ($DbChoice) {
        '1' {
            $DbHost         = 'db'
            $DbPort         = '3306'
            $DbName         = 'dashddi'
            $DbUser         = 'dash'
            $DbPassword     = RandHex 24
            $DbRootPassword = RandHex 24
            Ok 'MySQL will run in a container with auto-generated credentials'
        }
        '2' {
            $UseContainerDb = $false
            $DbHost         = Ask 'MySQL hostname or IP'
            $DbPort         = Ask 'MySQL port' '3306'
            $DbName         = Ask 'Database name' 'dashddi'
            $DbUser         = Ask 'MySQL username'
            $DbPassword     = AskSecret 'MySQL password'
            Ok "External MySQL: ${DbUser}@${DbHost}:${DbPort}/$DbName"
            Warn 'Ensure the database and user already exist with the correct privileges.'
        }
        default { Die 'Invalid choice.' }
    }

    $DbServerVersion = '8.0'
    if (-not $UseContainerDb) {
        $DbServerVersion = Ask 'MySQL server version (used in DSN)' '8.0'
    }
    $DatabaseUrl = "mysql://${DbUser}:${DbPassword}@${DbHost}:${DbPort}/${DbName}?serverVersion=${DbServerVersion}&charset=utf8mb4"
} else {
    $DbHost         = 'db'
    $DbPort         = '3306'
    $DbName         = 'dashddi'
    $DbUser         = 'dash'
    $DbPassword     = RandHex 16
    $DbRootPassword = RandHex 16
}

# ── 6. Write compose file ─────────────────────────────────────────────────────
if ($AppEnv -eq 'dev') {
    Header 'Writing docker-compose.dev.yml'

    $content = [IO.File]::ReadAllText($DistFile)
    $content = $content.Replace('replace_with_32plus_char_secret',                                          $AppSecret)
    $content = $content.Replace('run: docker compose exec app php bin/console app:generate-encryption-key', $AppEncryptionKey)
    $content = $content.Replace('https://your-dev-hostname.example.com',                                    $BaseUrl)
    $content = $content.Replace('dash_password',                                                            $DbPassword)
    $content = $content.Replace('root_password',                                                            $DbRootPassword)
    $content = $content.Replace('"8080:80"',                                                               "`"${HttpPort}:80`"")
    $content = $content.Replace('"8443:443"',                                                              "`"${HttpsPort}:443`"")
    [IO.File]::WriteAllText($ComposeFile, $content)

    Ok 'docker-compose.dev.yml written'
} else {
    Header 'Writing docker-compose.prod.yml'

    $dbServiceBlock = ''
    $dependsOnBlock = ''
    $volumesBlock   = "volumes:`n  ssl_certs:`n  symfony_var:"

    if ($UseContainerDb) {
        $dbServiceBlock = @"

  db:
    image: mysql:8.0
    read_only: true
    environment:
      MYSQL_DATABASE: $DbName
      MYSQL_USER: $DbUser
      MYSQL_PASSWORD: $DbPassword
      MYSQL_ROOT_PASSWORD: $DbRootPassword
    volumes:
      - mysql_data:/var/lib/mysql
    tmpfs:
      - /var/run/mysqld
      - /tmp
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "$DbUser", "-p$DbPassword"]
      interval: 5s
      timeout: 5s
      retries: 12
"@
        $dependsOnBlock = @"
    depends_on:
      db:
        condition: service_healthy
"@
        $volumesBlock = @"
volumes:
  ssl_certs:
  mysql_data:
  symfony_var:
"@
    }

    $workerEnv = @"
      APP_ENV: prod
      APP_SECRET: "$AppSecret"
      APP_ENCRYPTION_KEY: "$AppEncryptionKey"
      DATABASE_URL: "$DatabaseUrl"
      DEFAULT_URI: "$BaseUrl"
      MESSENGER_TRANSPORT_DSN: "doctrine://default?auto_setup=0"
"@

    $composeContent = @"
services:
  app:
    build:
      context: .
      target: prod
      args:
        APP_ENV: prod
    image: $AppImage
    read_only: true
    restart: unless-stopped
    volumes:
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
$workerEnv
    healthcheck:
      test: ["CMD-SHELL", "nc -z 127.0.0.1 9000"]
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 10s
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
$dependsOnBlock

  worker_priority:
    image: $AppImage
    read_only: true
    restart: unless-stopped
    command: ["php", "bin/console", "messenger:consume", "async_priority", "failed_priority", "--time-limit=3600"]
    volumes:
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
$workerEnv
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
$dependsOnBlock

  worker_bulk:
    image: $AppImage
    read_only: true
    restart: unless-stopped
    command: ["php", "bin/console", "messenger:consume", "async_priority", "async_bulk", "failed_bulk", "--time-limit=3600"]
    volumes:
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
$workerEnv
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
$dependsOnBlock

  # Rebuild order: docker compose build app && docker compose build nginx
  nginx:
    build:
      context: docker/nginx
      args:
        APP_IMAGE: $AppImage
    read_only: true
    restart: unless-stopped
    ports:
      - "${HttpPort}:80"
      - "${HttpsPort}:443"
    volumes:
      - ssl_certs:/etc/nginx/ssl:ro
    tmpfs:
      - /var/cache/nginx
      - /var/run
      - /tmp
    depends_on:
      app:
        condition: service_healthy
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
$dbServiceBlock
$volumesBlock
"@

    [IO.File]::WriteAllText($ComposeFile, $composeContent)
    Ok 'docker-compose.prod.yml written'
}

# ── 7. Build image(s) ─────────────────────────────────────────────────────────
Header 'Building image'

docker compose -f $ComposeFile build app
if ($LASTEXITCODE -ne 0) { Die 'Image build failed.' }
Ok "Application image built ($AppImage)"

if ($AppEnv -eq 'prod') {
    docker compose -f $ComposeFile build nginx
    if ($LASTEXITCODE -ne 0) { Die 'Nginx image build failed.' }
    Ok 'Nginx image built'
}

# ── 8. PHP dependencies (dev only) ────────────────────────────────────────────
if ($AppEnv -eq 'dev') {
    Header 'Installing PHP dependencies'

    docker run --rm `
        --volume "${ScriptDir}:/var/www/html" `
        --workdir /var/www/html `
        --env COMPOSER_HOME=/tmp/composer `
        $AppImage `
        composer install --no-interaction --no-progress
    if ($LASTEXITCODE -ne 0) { Die 'composer install failed.' }
    Ok 'Dependencies installed'
}

# ── 9. SSL certificate volume ─────────────────────────────────────────────────
Header 'Copying SSL certificates into volume'

$dockerSslDir = $SslDir -replace '\\', '/'
docker run --rm `
    -v "${ComposeProject}_ssl_certs:/ssl" `
    -v "${dockerSslDir}:/src:ro" `
    alpine sh -c "cp /src/cert.pem /src/key.pem /ssl/ && chmod 644 /ssl/*.pem"
if ($LASTEXITCODE -ne 0) { Die 'Failed to copy SSL certificates into volume.' }
Ok 'SSL certificates ready in named volume'

# ── 10. Start services ────────────────────────────────────────────────────────
Header 'Starting services'

docker compose -f $ComposeFile up -d
if ($LASTEXITCODE -ne 0) { Die 'Failed to start containers.' }
Ok 'Containers started'

Write-Host '  Waiting for the application container to be ready...'
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    $result = docker compose -f $ComposeFile exec -T app php -r 'echo "ok";' 2>$null
    if ($result -match 'ok') { $ready = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) { Die "App container did not become ready in time. Check: docker compose -f docker-compose.$AppEnv.yml logs app" }
Ok 'App container ready'

Write-Host '  Waiting for database to accept connections...'
$dbReady = $false
$pdoCheck = "try { new PDO('mysql:host=$DbHost;port=$DbPort;dbname=$DbName', '$DbUser', '$DbPassword'); exit(0); } catch(Exception `$e) { exit(1); }"
for ($i = 1; $i -le 30; $i++) {
    $null = docker compose -f $ComposeFile exec -T app php -r $pdoCheck 2>$null
    if ($LASTEXITCODE -eq 0) { $dbReady = $true; break }
    Start-Sleep -Seconds 2
}
if (-not $dbReady) { Die "Database did not become ready. Check: docker compose -f docker-compose.$AppEnv.yml logs db" }
Ok 'Database ready'

# ── 11. Database migrations ───────────────────────────────────────────────────
Header 'Running database migrations'

docker compose -f $ComposeFile exec -T app `
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
if ($LASTEXITCODE -ne 0) { Die 'Migrations failed.' }
Ok 'Migrations complete'

# ── 12. Cache warmup ──────────────────────────────────────────────────────────
Header 'Warming up cache'

docker compose -f $ComposeFile exec -T app php bin/console cache:warmup
if ($LASTEXITCODE -ne 0) { Die 'Cache warmup failed.' }
Ok 'Cache warm'

# ── 13. Fixtures (dev only) ───────────────────────────────────────────────────
if ($AppEnv -eq 'dev') {
    Header 'Loading fixtures'
    if (Test-Path (Join-Path $ScriptDir 'src\DataFixtures\*.php')) {
        docker compose -f $ComposeFile exec -T app `
            php bin/console doctrine:fixtures:load --no-interaction --append
        if ($LASTEXITCODE -ne 0) { Die 'Fixture loading failed.' }
        Ok 'Fixtures loaded'
    } else {
        Ok 'No fixture files found — skipped'
    }
}

# ── 14. SAML identity provider ───────────────────────────────────────────────
Header 'SAML Identity Provider'
Write-Host

if ($AppEnv -eq 'prod') {
    Write-Host "  SP metadata URL (give this to your IdP administrator):" -ForegroundColor White
    Write-Host "  $BaseUrl/saml/metadata" -ForegroundColor Cyan
    Write-Host
    Write-Host '  Your IdP administrator needs to register that URL as a Service Provider'
    Write-Host '  in their system before users can log in.'
    Write-Host
}

$SamlSource = Read-Host '  Path to IdP metadata XML file, or URL (leave blank to configure later)'

if (-not [string]::IsNullOrWhiteSpace($SamlSource)) {
    $SamlName = Ask "Provider name (e.g. 'Okta', 'Azure AD', 'Entra ID')" 'IdP'
    $SamlTmp  = $null

    if ($SamlSource -match '^https?://') {
        $SamlArg = $SamlSource
    } else {
        if (-not (Test-Path $SamlSource)) { Die "File not found: $SamlSource" }
        $SamlTmp = Join-Path $ScriptDir '.saml-setup-metadata.xml'
        Copy-Item $SamlSource $SamlTmp
        if ($AppEnv -eq 'dev') {
            $SamlArg = '/var/www/html/.saml-setup-metadata.xml'
        } else {
            $AppContainer = (docker compose -f $ComposeFile ps -q app)[0]
            docker cp $SamlTmp "${AppContainer}:/tmp/saml-metadata.xml"
            if ($LASTEXITCODE -ne 0) { Die 'Failed to copy SAML metadata into container.' }
            $SamlArg = '/tmp/saml-metadata.xml'
        }
    }

    docker compose -f $ComposeFile exec -T app `
        php bin/console app:saml:import-metadata $SamlArg --name="$SamlName" --activate
    if ($LASTEXITCODE -ne 0) { Die 'SAML import failed.' }

    if ($SamlTmp) { Remove-Item $SamlTmp -Force }
    Ok "SAML provider '$SamlName' imported and set as active"
    Write-Host
    Write-Host '  SP metadata URL for your IdP:' -ForegroundColor White
    Write-Host "  $BaseUrl/saml/metadata" -ForegroundColor Cyan
} else {
    Warn 'Skipped — no one will be able to log in until a SAML provider is configured.'
    Warn 'Run this when ready:'
    Warn "  docker compose -f docker-compose.$AppEnv.yml exec app php bin/console app:saml:import-metadata <file-or-url> --activate"
    Warn "SP metadata URL (accessible after import):  $BaseUrl/saml/metadata"
}

# ── 15. Summary ───────────────────────────────────────────────────────────────
Header 'Setup complete'
Write-Host

if ($AppEnv -eq 'dev') {
    Write-Host "  DashDDI (dev) is running at:  $BaseUrl" -ForegroundColor Cyan
} else {
    Write-Host "  DashDDI is running at:  $BaseUrl" -ForegroundColor Cyan
}
Write-Host

Write-Host '  MySQL credentials (save these — they are not stored elsewhere):' -ForegroundColor White
if ($AppEnv -eq 'dev' -or $UseContainerDb) {
    if ($AppEnv -eq 'dev') {
        Write-Host "    App user:  dash / $DbPassword"
    } else {
        Write-Host "    App user:  $DbUser / $DbPassword"
    }
    Write-Host "    Root:      root / $DbRootPassword"
}
Write-Host

if ($AppEnv -eq 'dev') {
    Warn 'Self-signed cert in use — browsers will show a security warning.'
} elseif ($SslChoice -eq '1') {
    Warn 'Self-signed cert in use — browsers will show a warning.'
    Warn 'To switch to a trusted cert, replace docker/ssl/cert.pem and key.pem, then run:'
    Warn "  docker run --rm -v ${ComposeProject}_ssl_certs:/ssl -v ${dockerSslDir}:/src:ro alpine sh -c 'cp /src/cert.pem /src/key.pem /ssl/'"
    Warn "  docker compose -f docker-compose.$AppEnv.yml restart nginx"
}
Write-Host

Write-Host '  Common commands:'
Write-Host "    Start:    docker compose -f docker-compose.$AppEnv.yml up -d"
Write-Host "    Stop:     docker compose -f docker-compose.$AppEnv.yml down"
Write-Host "    Logs:     docker compose -f docker-compose.$AppEnv.yml logs -f"
Write-Host "    Migrate:  docker compose -f docker-compose.$AppEnv.yml exec app php bin/console doctrine:migrations:migrate"
Write-Host
Write-Host "  SP metadata URL:  $BaseUrl/saml/metadata" -ForegroundColor Cyan
Write-Host
