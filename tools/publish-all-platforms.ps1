[CmdletBinding()]
param(
    [string] $ArticleId = ''
)

$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$OutputEncoding = [Console]::OutputEncoding

$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

function Write-Step {
    param([string] $Message)

    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Test-LocalPort {
    param(
        [string] $HostName,
        [int] $Port,
        [int] $TimeoutMilliseconds = 1000
    )

    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $connection = $client.ConnectAsync($HostName, $Port)

        return $connection.Wait($TimeoutMilliseconds) -and $client.Connected
    }
    catch {
        return $false
    }
    finally {
        $client.Dispose()
    }
}

function Invoke-PublishCommand {
    param(
        [string] $SelectedArticleId,
        [switch] $Preflight
    )

    $dockerArguments = @(
        'compose', 'exec', '-T', 'app',
        'php', 'artisan', 'geoflow:publish-all'
    )
    if ($SelectedArticleId -ne '') {
        $dockerArguments += $SelectedArticleId
    }
    if ($Preflight) {
        $dockerArguments += '--preflight'
    }

    $lines = @(& docker @dockerArguments 2>&1 | ForEach-Object { $_.ToString() })
    $exitCode = $LASTEXITCODE

    return [pscustomobject]@{
        ExitCode = $exitCode
        Lines = $lines
    }
}

function Start-BrowserRunnerIfNeeded {
    if (Test-LocalPort -HostName '127.0.0.1' -Port 19090) {
        Write-Host '浏览器发布助手已在运行。' -ForegroundColor Green

        return
    }

    $runnerScript = Join-Path $projectRoot 'browser-runner\start.ps1'
    if (-not (Test-Path -LiteralPath $runnerScript)) {
        throw '找不到 browser-runner\start.ps1，无法启动浏览器发布助手。'
    }

    Write-Step '启动浏览器发布助手'
    $logDirectory = Join-Path $projectRoot 'logs'
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
    $standardOutputLog = Join-Path $logDirectory 'browser-runner.out.log'
    $standardErrorLog = Join-Path $logDirectory 'browser-runner.error.log'
    $quotedRunnerScript = '"{0}"' -f $runnerScript

    Start-Process `
        -FilePath 'powershell.exe' `
        -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $quotedRunnerScript) `
        -WorkingDirectory (Split-Path -Parent $runnerScript) `
        -WindowStyle Hidden `
        -RedirectStandardOutput $standardOutputLog `
        -RedirectStandardError $standardErrorLog | Out-Null

    $deadline = [DateTime]::UtcNow.AddSeconds(45)
    while ([DateTime]::UtcNow -lt $deadline) {
        Start-Sleep -Milliseconds 750
        if (Test-LocalPort -HostName '127.0.0.1' -Port 19090) {
            Write-Host '浏览器发布助手启动成功。' -ForegroundColor Green

            return
        }
    }

    throw "浏览器发布助手未能在 45 秒内启动。请查看日志：$standardErrorLog"
}

try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw '未找到 Docker 命令，请先安装并启动 Docker Desktop。'
    }

    if ($ArticleId -eq '') {
        $ArticleId = (Read-Host '请输入文章 ID（直接回车将自动选择最新的可分发文章）').Trim()
    }
    else {
        $ArticleId = $ArticleId.Trim()
    }

    if ($ArticleId -ne '' -and $ArticleId -notmatch '^[1-9][0-9]*$') {
        throw '文章 ID 必须是大于 0 的整数。'
    }

    Write-Step '检查 Docker'
    & docker info *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker Desktop 尚未启动，请启动后重试。'
    }

    Write-Step '启动点签应用和单个分发 Worker'
    & docker compose up -d app queue
    if ($LASTEXITCODE -ne 0) {
        throw '点签 Docker 服务启动失败。'
    }

    Write-Step '检查文章与平台配置'
    $preflight = Invoke-PublishCommand -SelectedArticleId $ArticleId -Preflight
    if ($preflight.ExitCode -ne 0) {
        $preflight.Lines | ForEach-Object { Write-Host $_ -ForegroundColor Red }
        exit $preflight.ExitCode
    }

    $selectedArticleId = ''
    $browserRunnerRequired = $false
    foreach ($line in $preflight.Lines) {
        if ($line -match '^GEOFLOW_ARTICLE_ID=([1-9][0-9]*)$') {
            $selectedArticleId = $Matches[1]
        }
        elseif ($line -eq 'GEOFLOW_BROWSER_RUNNER_REQUIRED=1') {
            $browserRunnerRequired = $true
        }
    }
    if ($selectedArticleId -eq '') {
        throw '未能从配置检查结果中取得文章 ID。'
    }

    if ($browserRunnerRequired) {
        Start-BrowserRunnerIfNeeded
    }

    Write-Step "发布文章 #$selectedArticleId 到全部已配置平台"
    $publishResult = Invoke-PublishCommand -SelectedArticleId $selectedArticleId
    $publishResult.Lines | ForEach-Object { Write-Host $_ }
    if ($publishResult.ExitCode -ne 0) {
        exit $publishResult.ExitCode
    }

    Write-Host "`n一键分发已提交。可在点签的“分发管理”中查看各平台结果。" -ForegroundColor Green
    exit 0
}
catch {
    Write-Host "`n操作失败：$($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
