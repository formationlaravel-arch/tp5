param([string]$ComposeFile = "version3/docker-compose.yml")

$ErrorActionPreference = "Stop"
$stopped = $false
try {
    & docker compose -f $ComposeFile stop inventory-service
    if ($LASTEXITCODE -ne 0) { throw "Unable to stop inventory-service." }
    $stopped = $true
    $result = & docker compose -f $ComposeFile exec -T order-service php /app/benchmark/failure-test.php
    if ($LASTEXITCODE -ne 0) { throw "Crash test failed: $($result -join ' ')" }
    $result | ConvertFrom-Json | Format-List
} finally {
    if ($stopped) {
        & docker compose -f $ComposeFile start inventory-service
        if ($LASTEXITCODE -ne 0) { Write-Error "CRITICAL: inventory-service failed to restart." }
    }
}
