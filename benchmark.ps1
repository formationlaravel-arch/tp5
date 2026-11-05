param(
    [ValidateRange(1, 10)]
    [int]$Iterations = 5,
    [string]$V1Url = "http://localhost/lab_mpin/version1/index.php",
    [string]$V2Url = "http://localhost/lab_mpin/version2/public/index.php",
    [string]$ComposeFile = "version3/docker-compose.yml"
)

$ErrorActionPreference = "Stop"

function Measure-Requests([string]$Name, [scriptblock]$Action, [int]$Count) {
    $times = @()
    for ($i = 0; $i -lt $Count; $i++) {
        $watch = [Diagnostics.Stopwatch]::StartNew()
        & $Action $i
        $watch.Stop()
        $times += [math]::Round($watch.Elapsed.TotalMilliseconds, 3)
    }
    $sorted = @($times | Sort-Object)
    $median = $sorted[[int][math]::Floor(($sorted.Count - 1) / 2)]
    $p95Index = [math]::Min($sorted.Count - 1, [int][math]::Ceiling($sorted.Count * 0.95) - 1)
    [pscustomobject]@{
        Architecture = $Name
        Iterations = $Count
        MedianMs = $median
        P95Ms = $sorted[$p95Index]
        MeasurementsMs = ($times -join ", ")
    }
}

$v1Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri $V1Url -Method Get -WebSession $v1Session
$null = Invoke-WebRequest -Uri $V1Url -Method Post -WebSession $v1Session -Body @{
    action = "login"; username = "customer"; password = "Customer123!"
}
$loginPage = Invoke-WebRequest -Uri $V1Url -Method Get -WebSession $v1Session
if ($loginPage.Content -notmatch 'name="csrf" value="([^"]+)"') {
    throw "V1 login failed or CSRF form is missing at $V1Url"
}
$v1Csrf = $Matches[1]
$v1Action = {
    param($Index)
    $response = Invoke-WebRequest -Uri $V1Url -Method Post -WebSession $v1Session -Body @{
        action = "checkout"; csrf = $v1Csrf; item_id = "3"; quantity = "1"
    }
    if ($response.StatusCode -ne 200 -or $response.Content -notmatch "Commande #") {
        throw "V1 checkout failed: $($response.StatusCode)"
    }
}
$v1 = Measure-Requests "V1 PHP/MySQL transaction" $v1Action $Iterations

$v2Login = Invoke-RestMethod -Uri "$V2Url/api/v1/auth/login" -Method Post -ContentType "application/json" -Body (
    @{ username = "customer"; password = "Customer123!" } | ConvertTo-Json
)
$v2Headers = @{ Authorization = "Bearer $($v2Login.access_token)" }
$v2Action = {
    param($Index)
    $body = @{ product_id = 3; quantity = 1 } | ConvertTo-Json
    $result = Invoke-RestMethod -Uri "$V2Url/api/v1/orders" -Method Post -Headers $v2Headers -ContentType "application/json" -Body $body
    if (-not $result.data.id) { throw "V2 returned no order identifier." }
}
$v2 = Measure-Requests "V2 MVC API/JWT" $v2Action $Iterations

$composeArgs = @("compose", "-f", $ComposeFile, "exec", "-T", "order-service", "php", "/app/benchmark/run.php", "$Iterations")
$v3Output = & docker @composeArgs
if ($LASTEXITCODE -ne 0) { throw "V3 Docker benchmark failed (exit $LASTEXITCODE)." }
$v3 = $v3Output | ConvertFrom-Json

Write-Host "Mesures par opération réussie; authentification initiale exclue de V1/V2/V3."
Write-Host "Les temps de V1/V2 incluent l'aller-retour HTTP hôte->WAMP; V3 mesure l'appel HTTP depuis le réseau Docker privé."
Write-Host "Réinitialiser les bases entre séries pour comparer des états de stock identiques."
@($v1, $v2, [pscustomobject]@{
    Architecture = "V3 Docker: services polyglottes"
    Iterations = $v3.iterations
    MedianMs = $v3.median_ms
    P95Ms = $v3.p95_ms
    MeasurementsMs = ($v3.measurements -join ", ")
}) | Format-Table -AutoSize
