# Called after a successful certificate issuance/renewal to create or update
# CAA and/or HTTPS records at each issued FQDN via the DashDDI host self-service
# API, if dns_dashddi_caa / dns_dashddi_https (or dns_dashddi_https_value) are
# configured.
#
# Each call is an idempotent upsert matched by hostname+domain+type, so running
# this on every renewal is safe. A failure publishing one of these records is
# reported as a warning and does not throw - certificate issuance succeeding is
# the primary outcome, this is a best-effort supplementary step.

param(
    [Parameter(Mandatory)]
    [string[]]$Fqdns
)

$ErrorActionPreference = 'Stop'

# Default (and currently only supported) ACME server for this wrapper is Let's
# Encrypt, so the CA authorized in the CAA record is fixed rather than parsed
# from a --server-style override (win-acme has no such option wired up here).
$DefaultCaDomain = 'letsencrypt.org'

# HTTPS record content can't be inferred from the issuance (it depends on what
# the web server actually supports), so this is a static, generally-safe
# default used only when dns_dashddi_https is enabled with no explicit value.
$DefaultHttpsValue = '1 . alpn=h2'

function Test-DashddiBool([string]$Value) {
    return $Value -and $Value.Trim().ToLower() -in 'true', '1', 'yes', 'on'
}

$cfg = Get-Content (Join-Path $PSScriptRoot 'dashddi.ini') -Raw | ConvertFrom-StringData
$baseUrl = $cfg.dns_dashddi_url.TrimEnd('/')
$apiToken = $cfg.dns_dashddi_token

$recordTypes = @{}
if (Test-DashddiBool $cfg.dns_dashddi_caa) {
    $recordTypes['CAA'] = "0 issue `"$DefaultCaDomain`""
}
if ($cfg.dns_dashddi_https_value) {
    $recordTypes['HTTPS'] = $cfg.dns_dashddi_https_value
} elseif (Test-DashddiBool $cfg.dns_dashddi_https) {
    $recordTypes['HTTPS'] = $DefaultHttpsValue
}

if ($recordTypes.Count -eq 0) {
    return
}

foreach ($fqdn in $Fqdns) {
    foreach ($type in $recordTypes.Keys) {
        $body = [ordered]@{ fqdn = $fqdn; type = $type; value = $recordTypes[$type] } | ConvertTo-Json
        try {
            $response = Invoke-RestMethod `
                -Method Put `
                -Uri "$baseUrl/api/self/records" `
                -Headers @{ Authorization = "Bearer $apiToken"; 'Content-Type' = 'application/json' } `
                -Body $body
            Write-Host "$type record for ${fqdn}: $($response.action)"
        } catch {
            $status = $_.Exception.Response.StatusCode.value__
            Write-Warning "Failed to publish $type record for ${fqdn}: $status $_"
        }
    }
}
