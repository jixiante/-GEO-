from pathlib import Path

from PIL import Image, ImageDraw, ImageFont, ImageOps


ROOT = Path(__file__).resolve().parent
FONT_REGULAR = r"C:\Windows\Fonts\msyh.ttc"
FONT_BOLD = r"C:\Windows\Fonts\msyhbd.ttc"
SIZE = (1200, 675)


def font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size)


def make_cover(source: str, target: str, label: str, title_lines: list[str], subtitle: str) -> None:
    image = Image.open(ROOT / "covers" / source).convert("RGB")
    image = ImageOps.fit(image, SIZE, method=Image.Resampling.LANCZOS)

    overlay = Image.new("RGBA", SIZE, (0, 0, 0, 0))
    overlay_draw = ImageDraw.Draw(overlay)
    for x in range(0, 740):
        alpha = max(0, 92 - int(x * 92 / 740))
        overlay_draw.line((x, 0, x, SIZE[1]), fill=(250, 248, 242, alpha))
    image = Image.alpha_composite(image.convert("RGBA"), overlay)
    draw = ImageDraw.Draw(image)

    teal = "#1F7A74"
    charcoal = "#17231F"
    muted = "#5D6965"
    red = "#CE3A42"
    pale_teal = "#E4EFEC"

    draw.rectangle((0, 0, SIZE[0], 10), fill=teal)
    draw.rectangle((0, 0, 16, SIZE[1]), fill=red)
    draw.rounded_rectangle((70, 80, 365, 128), radius=10, fill=pale_teal)
    draw.text((92, 89), label, font=font(FONT_BOLD, 25), fill=teal)

    y = 178
    for line in title_lines:
        draw.text((70, y), line, font=font(FONT_BOLD, 48), fill=charcoal)
        y += 72

    draw.line((70, y + 6, 660, y + 6), fill="#CFD8D4", width=2)
    draw.text((70, y + 30), subtitle, font=font(FONT_REGULAR, 27), fill=muted)

    draw.rounded_rectangle((70, 548, 250, 595), radius=10, fill=charcoal)
    draw.text((94, 556), "点签实务指南", font=font(FONT_BOLD, 23), fill="white")

    image.convert("RGB").save(ROOT / "covers" / target, quality=95)


make_cover(
    "article-1-background.png",
    "article-1-standard-16x9.png",
    "存量合同数字化指南",
    ["纸质合同要不要", "补签电子版？"],
    "扫描归档 · 确认副本 · 重新签署",
)

make_cover(
    "article-2-background.png",
    "article-2-standard-16x9.png",
    "OpenAPI 稳定性指南",
    ["回调丢了怎么办？", "幂等、重试、对账"],
    "重复不重做，漏收能找回，状态可核验",
)
