# Called by win-acme's DNS script validation plugin to create the ACME
# challenge TXT record via the DashDDI host self-service API.
#
# win-acme invocation (configured by Install-Dashddi.ps1):
#   powershell.exe -ExecutionPolicy ByPass -File New-DashddiChallenge.ps1 {Identifier} {Token}
#
# {Identifier} = FQDN being validated (e.g. srv.example.com)
# {Token}      = TXT record value assigned by the CA

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
    $response = Invoke-RestMethod `
        -Method Post `
        -Uri "$baseUrl/api/self/dns-challenge" `
        -Headers @{ Authorization = "Bearer $apiToken"; 'Content-Type' = 'application/json' } `
        -Body $body
    Write-Host "Created challenge record id=$($response.id) for $Identifier"
} catch {
    $status = $_.Exception.Response.StatusCode.value__
    Write-Error "DashDDI returned $status creating challenge for ${Identifier}: $_"
    exit 1
}
