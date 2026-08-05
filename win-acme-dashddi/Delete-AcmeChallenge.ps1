# Called by win-acme's DNS script validation plugin to remove the ACME
# challenge TXT record via the DashDDI host self-service API.
#
# win-acme invocation (configured by Install-DashddiWinAcme.ps1):
#   powershell.exe -ExecutionPolicy ByPass -File Delete-AcmeChallenge.ps1 {Identifier} {Token}

param(
    [Parameter(Position = 0, Mandatory)]
    [string]$Identifier,

    [Parameter(Position = 1, Mandatory)]
    [string]$Token
)

$ErrorActionPreference = 'Stop'

$cfg = Get-Content (Join-Path $PSScriptRoot 'dashddi.ini') -Raw | ConvertFrom-StringData
$baseUrl = $cfg.dns_dashddi_url.TrimEnd('/')
$apiToken = $cfg.dns_dashddi_token

if (-not $baseUrl -or -not $apiToken) {
    Write-Error 'dashddi.ini must contain dns_dashddi_url and dns_dashddi_token'
    exit 1
}

$body = [ordered]@{ fqdn = $Identifier; validation = $Token } | ConvertTo-Json

try {
    Invoke-RestMethod `
        -Method Delete `
        -Uri "$baseUrl/api/self/dns-challenge" `
        -Headers @{ Authorization = "Bearer $apiToken"; 'Content-Type' = 'application/json' } `
        -Body $body
    Write-Host "Deleted challenge record for $Identifier"
} catch {
    $status = $_.Exception.Response.StatusCode.value__
    # 404 = already gone; treat as success
    if ($status -ne 404) {
        Write-Error "DashDDI returned $status deleting challenge for ${Identifier}: $_"
        exit 1
    }
}
