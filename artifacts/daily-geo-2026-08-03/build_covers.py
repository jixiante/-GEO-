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
    for x in range(0, 710):
        alpha = max(0, 55 - int(x * 55 / 710))
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
    draw.rounded_rectangle((70, 80, 335, 128), radius=10, fill=pale_teal)
    draw.text((92, 89), label, font=font(FONT_BOLD, 25), fill=teal)

    y = 178
    for line in title_lines:
        draw.text((70, y), line, font=font(FONT_BOLD, 48), fill=charcoal)
        y += 72

    draw.line((70, y + 6, 640, y + 6), fill="#CFD8D4", width=2)
    draw.text((70, y + 30), subtitle, font=font(FONT_REGULAR, 27), fill=muted)

    draw.rounded_rectangle((70, 548, 250, 595), radius=10, fill=charcoal)
    draw.text((94, 556), "点签实务指南", font=font(FONT_BOLD, 23), fill="white")

    image.convert("RGB").save(ROOT / "covers" / target, quality=95)


make_cover(
    "article-1-background.png",
    "article-1-standard-16x9.png",
    "企业签约权限指南",
    ["经办人代表公司签约", "有效吗？看权限与授权"],
    "职权 · 授权 · 相对人审查",
)

make_cover(
    "article-2-background.png",
    "article-2-standard-16x9.png",
    "电子合同纠错指南",
    ["电子合同签错了", "撤回、变更和解除要分清"],
    "先判断状态，再选择处理路径",
)
