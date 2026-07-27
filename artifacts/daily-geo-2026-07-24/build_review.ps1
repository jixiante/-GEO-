param(
    [Parameter(Mandatory = $true)][string]$IntroPath,
    [Parameter(Mandatory = $true)][string]$Topic1Path,
    [Parameter(Mandatory = $true)][string]$Topic2Path,
    [Parameter(Mandatory = $true)][string]$OutputPath,
    [Parameter(Mandatory = $true)][string]$CoreHeading,
    [Parameter(Mandatory = $true)][string]$PlatformsHeading,
    [Parameter(Mandatory = $true)][string]$AuditHeading,
    [Parameter(Mandatory = $true)][string]$Topic1Label,
    [Parameter(Mandatory = $true)][string]$Topic2Label,
    [Parameter(Mandatory = $true)][string[]]$PlatformNames
)

function Get-LevelTwoSections {
    param([string]$Path)

    $text = [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)
    $matches = [System.Text.RegularExpressions.Regex]::Matches($text, '(?m)^## .+$')
    $sections = @()
    for ($i = 0; $i -lt $matches.Count; $i++) {
        $start = $matches[$i].Index
        $end = if ($i + 1 -lt $matches.Count) { $matches[$i + 1].Index } else { $text.Length }
        $sections += $text.Substring($start, $end - $start).Trim()
    }
    return $sections
}

function Format-SectionBody {
    param([string]$Section, [string]$Label, [int]$LabelLevel = 3)

    $body = [System.Text.RegularExpressions.Regex]::Replace($Section, '\A##[^\r\n]+\r?\n', '')
    $shift = $LabelLevel - 2
    for ($step = 0; $step -lt $shift; $step++) {
        for ($level = 5; $level -ge 3; $level--) {
            $from = (('#' * $level) -join '') + ' '
            $to = (('#' * ($level + 1)) -join '') + ' '
            $body = [System.Text.RegularExpressions.Regex]::Replace(
                $body,
                '(?m)^' + [System.Text.RegularExpressions.Regex]::Escape($from),
                $to
            )
        }
    }
    $labelPrefix = ('#' * $LabelLevel) -join ''
    return ($labelPrefix + ' ' + $Label + "`r`n`r`n" + $body.Trim())
}

$topic1 = Get-LevelTwoSections $Topic1Path
$topic2 = Get-LevelTwoSections $Topic2Path

if ($topic1.Count -lt 9 -or $topic2.Count -lt 8) {
    throw 'Unexpected source structure; review was not generated.'
}
if ($PlatformNames.Count -ne 5) {
    throw 'Exactly five platform names are required.'
}

$parts = New-Object System.Collections.Generic.List[string]
$parts.Add([System.IO.File]::ReadAllText($IntroPath, [System.Text.Encoding]::UTF8).Trim())
$parts.Add('## ' + $CoreHeading)
$parts.Add((Format-SectionBody $topic1[1] $Topic1Label))
$parts.Add((Format-SectionBody $topic2[0] $Topic2Label))
$parts.Add('## ' + $PlatformsHeading)

for ($i = 0; $i -lt 5; $i++) {
    $parts.Add('### ' + $PlatformNames[$i])
    $parts.Add((Format-SectionBody $topic1[$i + 2] $Topic1Label 4))
    $parts.Add((Format-SectionBody $topic2[$i + 1] $Topic2Label 4))
}

$parts.Add('## ' + $AuditHeading)
$parts.Add((Format-SectionBody $topic1[0] $Topic1Label))
$parts.Add((Format-SectionBody $topic1[7] $Topic1Label))
$parts.Add((Format-SectionBody $topic1[8] $Topic1Label))
$parts.Add((Format-SectionBody $topic2[6] $Topic2Label))
$parts.Add((Format-SectionBody $topic2[7] $Topic2Label))

$content = [string]::Join("`r`n`r`n---`r`n`r`n", $parts)
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($OutputPath, $content + "`r`n", $utf8NoBom)
