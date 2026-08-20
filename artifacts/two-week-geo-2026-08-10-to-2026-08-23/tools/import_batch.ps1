param(
    [string]$BatchRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$BaseUrl = 'http://localhost:18080'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Get-DotEnvValue {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Name
    )

    $line = Get-Content -LiteralPath $Path -Encoding UTF8 |
        Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') } |
        Select-Object -First 1
    if ([string]::IsNullOrWhiteSpace($line)) {
        throw "Missing $Name in $Path"
    }

    $value = ($line -split '=', 2)[1].Trim()
    if (($value.StartsWith('"') -and $value.EndsWith('"')) -or
        ($value.StartsWith("'") -and $value.EndsWith("'"))) {
        $value = $value.Substring(1, $value.Length - 2)
    }

    return $value
}

function Get-Excerpt {
    param([Parameter(Mandatory = $true)][string]$Markdown)

    $paragraph = ($Markdown -split "`r?`n`r?`n" |
        ForEach-Object { $_.Trim() } |
        Where-Object { $_ -ne '' -and -not $_.StartsWith('#') } |
        Select-Object -First 1)
    $plain = $paragraph -replace '!\[[^\]]*\]\([^\)]+\)', ''
    $plain = $plain -replace '\[([^\]]+)\]\([^\)]+\)', '$1'
    $plain = $plain -replace '[`*_>#-]', ''
    $plain = ($plain -replace '\s+', ' ').Trim()

    if ($plain.Length -gt 118) {
        return $plain.Substring(0, 118).TrimEnd(',', '.', ';', ':') + [char]0x3002
    }

    return $plain
}

function Get-Sha256 {
    param([Parameter(Mandatory = $true)][string]$Path)
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function New-AdminWebClient {
    param(
        [Parameter(Mandatory = $true)][string]$LoginUrl,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password
    )

    Add-Type -AssemblyName System.Net.Http
    $handler = [System.Net.Http.HttpClientHandler]::new()
    $handler.AllowAutoRedirect = $true
    $handler.CookieContainer = [System.Net.CookieContainer]::new()
    $client = [System.Net.Http.HttpClient]::new($handler)

    $loginHtml = $client.GetStringAsync($LoginUrl).GetAwaiter().GetResult()
    $match = [regex]::Match($loginHtml, 'name="_token"\s+value="([^"]+)"')
    if (-not $match.Success) {
        $client.Dispose()
        throw 'Could not read the local admin CSRF token.'
    }

    $csrf = [System.Net.WebUtility]::HtmlDecode($match.Groups[1].Value)
    $fields = [System.Collections.Generic.List[System.Collections.Generic.KeyValuePair[string,string]]]::new()
    $fields.Add([System.Collections.Generic.KeyValuePair[string,string]]::new('_token', $csrf))
    $fields.Add([System.Collections.Generic.KeyValuePair[string,string]]::new('username', $Username))
    $fields.Add([System.Collections.Generic.KeyValuePair[string,string]]::new('password', $Password))
    $fields.Add([System.Collections.Generic.KeyValuePair[string,string]]::new('remember', '1'))
    $form = [System.Net.Http.FormUrlEncodedContent]::new($fields)
    $response = $client.PostAsync($LoginUrl, $form).GetAwaiter().GetResult()
    $form.Dispose()
    if (-not $response.IsSuccessStatusCode -or $response.RequestMessage.RequestUri.AbsolutePath.EndsWith('/login')) {
        $response.Dispose()
        $client.Dispose()
        throw 'Local admin login failed.'
    }
    $response.Dispose()

    $dashboardUrl = $LoginUrl -replace '/login$', '/dashboard'
    $dashboardHtml = $client.GetStringAsync($dashboardUrl).GetAwaiter().GetResult()
    $authenticatedMatch = [regex]::Match($dashboardHtml, '<meta\s+name="csrf-token"\s+content="([^"]+)"')
    if (-not $authenticatedMatch.Success) {
        $client.Dispose()
        throw 'Could not read the authenticated local admin CSRF token.'
    }
    $csrf = [System.Net.WebUtility]::HtmlDecode($authenticatedMatch.Groups[1].Value)

    return [pscustomobject]@{ Client = $client; Csrf = $csrf }
}

function Add-ArticleCover {
    param(
        [Parameter(Mandatory = $true)][System.Net.Http.HttpClient]$Client,
        [Parameter(Mandatory = $true)][string]$Csrf,
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $true)][string]$CoverPath,
        [Parameter(Mandatory = $true)][string]$Alt
    )

    $multipart = [System.Net.Http.MultipartFormDataContent]::new()
    $multipart.Add([System.Net.Http.StringContent]::new($Csrf), '_token')
    $multipart.Add([System.Net.Http.StringContent]::new($Alt), 'alt')
    $multipart.Add([System.Net.Http.StringContent]::new('0'), 'position')

    $stream = [System.IO.File]::OpenRead($CoverPath)
    $fileContent = [System.Net.Http.StreamContent]::new($stream)
    $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('image/png')
    $multipart.Add($fileContent, 'image', [System.IO.Path]::GetFileName($CoverPath))

    try {
        $response = $Client.PostAsync($Url, $multipart).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        if (-not $response.IsSuccessStatusCode) {
            throw "Cover upload failed with HTTP $([int]$response.StatusCode): $body"
        }
    }
    finally {
        if ($null -ne $response) { $response.Dispose() }
        $multipart.Dispose()
        $fileContent.Dispose()
        $stream.Dispose()
    }
}

$projectRoot = (Resolve-Path (Join-Path $BatchRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env'
$schedulePath = Join-Path $BatchRoot 'schedule.json'
$resultPath = Join-Path $BatchRoot 'system-import.json'

if (-not (Test-Path -LiteralPath $schedulePath -PathType Leaf)) {
    throw "Schedule not found: $schedulePath"
}

$schedule = Get-Content -LiteralPath $schedulePath -Raw -Encoding UTF8 | ConvertFrom-Json
if (@($schedule.articles).Count -ne 28) {
    throw "Expected 28 scheduled articles, found $(@($schedule.articles).Count)."
}

$username = Get-DotEnvValue -Path $envPath -Name 'GEOFLOW_ADMIN_USERNAME'
$password = Get-DotEnvValue -Path $envPath -Name 'GEOFLOW_ADMIN_PASSWORD'

$loginBody = @{ username = $username; password = $password } | ConvertTo-Json
$login = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/v1/auth/login" -ContentType 'application/json' -Body $loginBody
$token = [string]$login.data.token
if ([string]::IsNullOrWhiteSpace($token)) {
    throw 'Local API login did not return a token.'
}
$tokenId = [int](($token -split '\|', 2)[0])
$apiHeaders = @{ Authorization = "Bearer $token" }

$web = $null
$results = [System.Collections.Generic.List[object]]::new()

try {
    $web = New-AdminWebClient -LoginUrl "$BaseUrl/geo_admin/login" -Username $username -Password $password
    $existingResponse = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/articles?per_page=100" -Headers $apiHeaders
    $existingBySlug = @{}
    foreach ($existing in @($existingResponse.data.items)) {
        $existingBySlug[[string]$existing.slug] = [int]$existing.id
    }

    foreach ($entry in $schedule.articles) {
        $slot = ([string]$entry.slot).ToLowerInvariant()
        $draftPath = Join-Path $BatchRoot ([string]$entry.file)
        $coverPath = Join-Path $BatchRoot ("covers/{0}-{1}-standard-16x9.png" -f $entry.date, $slot)
        if (-not (Test-Path -LiteralPath $draftPath -PathType Leaf)) {
            throw "Draft not found: $draftPath"
        }
        if (-not (Test-Path -LiteralPath $coverPath -PathType Leaf)) {
            throw "Cover not found: $coverPath"
        }

        $content = (Get-Content -LiteralPath $draftPath -Raw -Encoding UTF8).Trim()
        $excerpt = Get-Excerpt -Markdown $content
        $payload = [ordered]@{
            title = [string]$entry.title
            slug = [string]$entry.slug
            content = $content
            excerpt = $excerpt
            keywords = "$([string]$entry.theme),e-contract,e-signature,Dianqian"
            meta_description = $excerpt
            category_id = [int]$schedule.category_id
            author_id = [int]$schedule.author_id
            status = 'draft'
            review_status = 'pending'
            is_ai_generated = 1
        }
        $slug = [string]$entry.slug
        if ($existingBySlug.ContainsKey($slug)) {
            $articleId = [int]$existingBySlug[$slug]
            $shown = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/articles/$articleId" -Headers $apiHeaders
        }
        else {
            $headers = @{}
            foreach ($key in $apiHeaders.Keys) { $headers[$key] = $apiHeaders[$key] }
            $headers['X-Idempotency-Key'] = "batch-$($schedule.batch_id)-$slug-v2"
            try {
                $created = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/v1/articles" -Headers $headers -ContentType 'application/json; charset=utf-8' -Body ([System.Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json -Depth 5)))
            }
            catch [System.Net.WebException] {
                $errorBody = ''
                if ($null -ne $_.Exception.Response) {
                    $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream(), [System.Text.Encoding]::UTF8)
                    $errorBody = $reader.ReadToEnd()
                    $reader.Dispose()
                }
                throw "Article create failed for ${slug}: $errorBody"
            }
            $articleId = [int]$created.data.id
            if ($articleId -le 0) {
                throw "Article creation returned no id for $([string]$entry.title)."
            }
            $existingBySlug[$slug] = $articleId
            $shown = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/articles/$articleId" -Headers $apiHeaders
        }

        if ([string]$shown.data.title -ne [string]$entry.title -or
            [string]$shown.data.content -ne $content -or
            [string]$shown.data.status -ne 'draft' -or
            [string]$shown.data.review_status -ne 'pending') {
            throw "Local article does not match the frozen draft: $slug"
        }

        if (@($shown.data.images).Count -eq 0) {
            Add-ArticleCover `
                -Client $web.Client `
                -Csrf $web.Csrf `
                -Url "$BaseUrl/geo_admin/articles/$articleId/editor/images/upload" `
                -CoverPath $coverPath `
                -Alt ([string]$entry.title)
        }

        $results.Add([ordered]@{
            date = [string]$entry.date
            slot = [string]$entry.slot
            publish_time = [string]$entry.publish_time
            article_id = $articleId
            title = [string]$entry.title
            slug = [string]$entry.slug
            status = 'draft'
            review_status = 'pending'
            content_sha256 = Get-Sha256 -Path $draftPath
            cover_sha256 = Get-Sha256 -Path $coverPath
            cover_file = "covers/$([string]$entry.date)-$slot-standard-16x9.png"
        })
    }

    $result = [ordered]@{
        batch_id = [string]$schedule.batch_id
        imported_at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss zzz')
        base_url = $BaseUrl
        status = 'draft_pending_approval'
        articles = @($results)
    }
    $json = $result | ConvertTo-Json -Depth 8
    [System.IO.File]::WriteAllText($resultPath, $json, [System.Text.UTF8Encoding]::new($false))
}
finally {
    if ($null -ne $web) {
        try {
            $fields = [System.Collections.Generic.List[System.Collections.Generic.KeyValuePair[string,string]]]::new()
            $fields.Add([System.Collections.Generic.KeyValuePair[string,string]]::new('_token', $web.Csrf))
            $form = [System.Net.Http.FormUrlEncodedContent]::new($fields)
            $revokeResponse = $web.Client.PostAsync("$BaseUrl/geo_admin/api-tokens/$tokenId/revoke", $form).GetAwaiter().GetResult()
            $revokeResponse.Dispose()
            $form.Dispose()
        }
        finally {
            $web.Client.Dispose()
        }
    }
}

Write-Output "Imported $($results.Count) draft articles with covers into the local GEO system."
Write-Output "Result: $resultPath"
