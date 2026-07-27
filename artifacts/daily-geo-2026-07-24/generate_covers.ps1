param(
    [Parameter(Mandatory = $true)][ValidateSet(1, 2)][int]$Topic,
    [Parameter(Mandatory = $true)][int]$Width,
    [Parameter(Mandatory = $true)][int]$Height,
    [Parameter(Mandatory = $true)][string]$OutputPath,
    [Parameter(Mandatory = $true)][string]$Kicker,
    [Parameter(Mandatory = $true)][string]$Headline,
    [Parameter(Mandatory = $true)][string]$Deck,
    [Parameter(Mandatory = $true)][string]$Step1,
    [Parameter(Mandatory = $true)][string]$Step2,
    [Parameter(Mandatory = $true)][string]$Step3,
    [Parameter(Mandatory = $true)][string]$Source,
    [Parameter(Mandatory = $true)][string]$Disclosure
)

Add-Type -AssemblyName System.Drawing

function New-RoundedPath {
    param([float]$X, [float]$Y, [float]$W, [float]$H, [float]$Radius)
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $diameter = $Radius * 2
    $path.AddArc($X, $Y, $diameter, $diameter, 180, 90)
    $path.AddArc($X + $W - $diameter, $Y, $diameter, $diameter, 270, 90)
    $path.AddArc($X + $W - $diameter, $Y + $H - $diameter, $diameter, $diameter, 0, 90)
    $path.AddArc($X, $Y + $H - $diameter, $diameter, $diameter, 90, 90)
    $path.CloseFigure()
    return $path
}

function Fill-RoundedRectangle {
    param($Graphics, $Brush, [float]$X, [float]$Y, [float]$W, [float]$H, [float]$Radius)
    $path = New-RoundedPath $X $Y $W $H $Radius
    $Graphics.FillPath($Brush, $path)
    $path.Dispose()
}

function Draw-CenteredText {
    param($Graphics, [string]$Text, $Font, $Brush, [float]$X, [float]$Y, [float]$W, [float]$H)
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = [System.Drawing.StringAlignment]::Center
    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
    $format.Trimming = [System.Drawing.StringTrimming]::EllipsisCharacter
    $bounds = [System.Drawing.RectangleF]::new([float]$X, [float]$Y, [float]$W, [float]$H)
    $Graphics.DrawString($Text, $Font, $Brush, $bounds, $format)
    $format.Dispose()
}

function Draw-Steps {
    param($Graphics, [string[]]$Labels, [float]$X, [float]$Y, [float]$W, [float]$H, [float]$Gap, $Font, $TextBrush, $FillBrushes)
    $stepHeight = ($H - ($Gap * 2)) / 3
    for ($i = 0; $i -lt 3; $i++) {
        $top = $Y + (($stepHeight + $Gap) * $i)
        Fill-RoundedRectangle $Graphics $FillBrushes[$i] $X $top $W $stepHeight 8
        Draw-CenteredText $Graphics $Labels[$i] $Font $TextBrush $X $top $W $stepHeight
    }
}

$bitmap = New-Object System.Drawing.Bitmap($Width, $Height, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$graphics.Clear([System.Drawing.Color]::FromArgb(246, 247, 244))

$ink = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(28, 32, 30))
$muted = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(83, 91, 86))
$paper = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)
$linePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(28, 32, 30), [Math]::Max(3, $Width / 420))
$whitePen = New-Object System.Drawing.Pen([System.Drawing.Color]::White, [Math]::Max(3, $Width / 420))

if ($Topic -eq 1) {
    $accent = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(16, 125, 120))
    $accentDark = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(9, 81, 78))
    $stepBrushes = @(
        (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(221, 242, 238))),
        (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 232, 188))),
        (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(244, 211, 204)))
    )
} else {
    $accent = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(41, 112, 69))
    $accentDark = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(24, 72, 43))
    $stepBrushes = @(
        (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(219, 239, 226))),
        (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 226, 207))),
        (New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(229, 221, 245)))
    )
}

$isPortrait = $Height -gt $Width
$margin = [Math]::Round([Math]::Min($Width, $Height) * 0.07)
$kickerFont = New-Object System.Drawing.Font('Microsoft YaHei', [Math]::Max(16, $Width / 46), [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
$headlineFont = New-Object System.Drawing.Font('Microsoft YaHei', [Math]::Max(34, $(if ($isPortrait) { $Width / 14 } else { $Width / 22 })), [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
$deckFont = New-Object System.Drawing.Font('Microsoft YaHei', [Math]::Max(19, $Width / 39), [System.Drawing.FontStyle]::Regular, [System.Drawing.GraphicsUnit]::Pixel)
$stepFont = New-Object System.Drawing.Font('Microsoft YaHei', [Math]::Max(18, $Width / 50), [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
$smallFont = New-Object System.Drawing.Font('Microsoft YaHei', [Math]::Max(14, $Width / 60), [System.Drawing.FontStyle]::Regular, [System.Drawing.GraphicsUnit]::Pixel)

$graphics.FillRectangle($accent, 0, 0, $Width, [Math]::Max(12, $Height * 0.018))

if ($isPortrait) {
    $textX = $margin
    $textW = $Width - ($margin * 2)
    $graphics.DrawString($Kicker, $kickerFont, $accentDark, $textX, $margin)
    $headlineY = $margin + ($Height * 0.055)
    $headlineH = $Height * 0.22
    $headlineBounds = [System.Drawing.RectangleF]::new([float]$textX, [float]$headlineY, [float]$textW, [float]$headlineH)
    $graphics.DrawString($Headline, $headlineFont, $ink, $headlineBounds)
    $deckY = $headlineY + $headlineH + ($Height * 0.015)
    $deckBounds = [System.Drawing.RectangleF]::new([float]$textX, [float]$deckY, [float]$textW, [float]($Height * 0.09))
    $graphics.DrawString($Deck, $deckFont, $muted, $deckBounds)

    $panelX = $margin
    $panelY = $Height * 0.43
    $panelW = $Width - ($margin * 2)
    $panelH = $Height * 0.40
    Fill-RoundedRectangle $graphics $paper $panelX $panelY $panelW $panelH 8

    $iconX = $panelX + ($panelW * 0.08)
    $iconY = $panelY + ($panelH * 0.12)
    $iconW = $panelW * 0.40
    $iconH = $panelH * 0.76
    $stepsX = $panelX + ($panelW * 0.54)
    $stepsY = $panelY + ($panelH * 0.17)
    $stepsW = $panelW * 0.37
    $stepsH = $panelH * 0.66
} else {
    $textX = $margin
    $textW = $Width * 0.54
    $graphics.DrawString($Kicker, $kickerFont, $accentDark, $textX, $margin)
    $headlineY = $margin + ($Height * 0.075)
    $headlineH = $Height * 0.32
    $headlineBounds = [System.Drawing.RectangleF]::new([float]$textX, [float]$headlineY, [float]$textW, [float]$headlineH)
    $graphics.DrawString($Headline, $headlineFont, $ink, $headlineBounds)
    $deckY = $headlineY + $headlineH + ($Height * 0.01)
    $deckBounds = [System.Drawing.RectangleF]::new([float]$textX, [float]$deckY, [float]$textW, [float]($Height * 0.14))
    $graphics.DrawString($Deck, $deckFont, $muted, $deckBounds)

    $panelX = $Width * 0.64
    $panelY = $Height * 0.11
    $panelW = $Width * 0.29
    $panelH = $Height * 0.72
    Fill-RoundedRectangle $graphics $paper $panelX $panelY $panelW $panelH 8

    $iconX = $panelX + ($panelW * 0.16)
    $iconY = $panelY + ($panelH * 0.09)
    $iconW = $panelW * 0.68
    $iconH = $panelH * 0.43
    $stepsX = $panelX + ($panelW * 0.11)
    $stepsY = $panelY + ($panelH * 0.56)
    $stepsW = $panelW * 0.78
    $stepsH = $panelH * 0.36
}

if ($Topic -eq 1) {
    $phonePath = New-RoundedPath $iconX $iconY $iconW $iconH 12
    $graphics.FillPath($accent, $phonePath)
    $phonePath.Dispose()
    $screenMargin = $iconW * 0.10
    $screenX = $iconX + $screenMargin
    $screenY = $iconY + ($iconH * 0.08)
    $screenW = $iconW - ($screenMargin * 2)
    $screenH = $iconH * 0.76
    Fill-RoundedRectangle $graphics $paper $screenX $screenY $screenW $screenH 5
    $roofY = $screenY + ($screenH * 0.28)
    $houseLeft = $screenX + ($screenW * 0.22)
    $houseRight = $screenX + ($screenW * 0.78)
    $houseMid = $screenX + ($screenW * 0.50)
    $roofPoints = [System.Drawing.PointF[]]@(
        [System.Drawing.PointF]::new([float]$houseLeft, [float]($roofY + ($screenH * 0.18))),
        [System.Drawing.PointF]::new([float]$houseMid, [float]$roofY),
        [System.Drawing.PointF]::new([float]$houseRight, [float]($roofY + ($screenH * 0.18)))
    )
    $graphics.DrawLines($linePen, $roofPoints)
    $graphics.DrawRectangle($linePen, $houseLeft + ($screenW * 0.06), $roofY + ($screenH * 0.18), $screenW * 0.44, $screenH * 0.26)
    $graphics.FillEllipse($paper, $iconX + ($iconW * 0.46), $iconY + ($iconH * 0.89), $iconW * 0.08, $iconW * 0.08)
} else {
    $sheetX = $iconX + ($iconW * 0.05)
    $sheetY = $iconY + ($iconH * 0.03)
    $sheetW = $iconW * 0.70
    $sheetH = $iconH * 0.78
    Fill-RoundedRectangle $graphics $accent $sheetX $sheetY $sheetW $sheetH 7
    $innerX = $sheetX + ($sheetW * 0.10)
    $innerY = $sheetY + ($sheetH * 0.10)
    $innerW = $sheetW * 0.80
    $innerH = $sheetH * 0.80
    Fill-RoundedRectangle $graphics $paper $innerX $innerY $innerW $innerH 4
    for ($i = 0; $i -lt 3; $i++) {
        $lineY = $innerY + ($innerH * (0.24 + ($i * 0.18)))
        $graphics.DrawLine($linePen, $innerX + ($innerW * 0.14), $lineY, $innerX + ($innerW * 0.86), $lineY)
    }
    $checkX = $innerX + ($innerW * 0.54)
    $checkY = $innerY + ($innerH * 0.72)
    $checkPoints = [System.Drawing.PointF[]]@(
        [System.Drawing.PointF]::new([float]$checkX, [float]$checkY),
        [System.Drawing.PointF]::new([float]($checkX + ($innerW * 0.10)), [float]($checkY + ($innerH * 0.08))),
        [System.Drawing.PointF]::new([float]($checkX + ($innerW * 0.28)), [float]($checkY - ($innerH * 0.10)))
    )
    $graphics.DrawLines($linePen, $checkPoints)
    $graphics.FillRectangle($accentDark, $iconX + ($iconW * 0.70), $iconY + ($iconH * 0.70), $iconW * 0.24, $iconH * 0.20)
    $graphics.DrawLine($whitePen, $iconX + ($iconW * 0.74), $iconY + ($iconH * 0.79), $iconX + ($iconW * 0.90), $iconY + ($iconH * 0.79))
}

Draw-Steps $graphics @($Step1, $Step2, $Step3) $stepsX $stepsY $stepsW $stepsH ([Math]::Max(8, $Height * 0.012)) $stepFont $ink $stepBrushes

$footerY = $Height - ($margin * 0.62)
$graphics.DrawString($Source, $smallFont, $muted, $margin, $footerY)
$disclosureSize = $graphics.MeasureString($Disclosure, $smallFont)
$graphics.DrawString($Disclosure, $smallFont, $muted, $Width - $margin - $disclosureSize.Width, $footerY)

$directory = Split-Path -Parent $OutputPath
if (-not (Test-Path -LiteralPath $directory)) {
    New-Item -ItemType Directory -Path $directory | Out-Null
}
$bitmap.Save($OutputPath, [System.Drawing.Imaging.ImageFormat]::Png)

$smallFont.Dispose()
$stepFont.Dispose()
$deckFont.Dispose()
$headlineFont.Dispose()
$kickerFont.Dispose()
$whitePen.Dispose()
$linePen.Dispose()
$paper.Dispose()
$muted.Dispose()
$ink.Dispose()
$accent.Dispose()
$accentDark.Dispose()
$stepBrushes | ForEach-Object { $_.Dispose() }
$graphics.Dispose()
$bitmap.Dispose()
