$ErrorActionPreference = 'Stop'
$runnerRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location -LiteralPath $runnerRoot

if (-not (Test-Path -LiteralPath '.env')) {
    $tokenBytes = New-Object byte[] 32
    $randomNumberGenerator = [Security.Cryptography.RandomNumberGenerator]::Create()
    $randomNumberGenerator.GetBytes($tokenBytes)
    $randomNumberGenerator.Dispose()
    $token = [Convert]::ToBase64String($tokenBytes).Replace('+', '-').Replace('/', '_').TrimEnd('=')
    @(
        'RUNNER_HOST=0.0.0.0'
        'RUNNER_PORT=19090'
        "RUNNER_TOKEN=$token"
        'RUNNER_ENABLED=true'
        'RUNNER_HEADLESS=false'
        'RUNNER_BROWSER=chromium'
        'RUNNER_OPERATION_TIMEOUT_MS=180000'
    ) | Set-Content -LiteralPath '.env' -Encoding utf8
    Write-Host "已生成本机配对令牌：$token"
}

if (-not (Test-Path -LiteralPath 'node_modules')) {
    npm install --no-audit --no-fund
}

npx playwright install chromium

npm start
