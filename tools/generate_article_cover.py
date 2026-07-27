#!/usr/bin/env python3
"""Generate a deterministic 16:9 article cover for domestic publishing platforms."""

from __future__ import annotations

import argparse
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


WIDTH = 1200
HEIGHT = 675


def font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    candidates = [
        Path("C:/Windows/Fonts/msyhbd.ttc" if bold else "C:/Windows/Fonts/msyh.ttc"),
        Path("C:/Windows/Fonts/NotoSansSC-VF.ttf"),
        Path("C:/Windows/Fonts/Dengb.ttf" if bold else "C:/Windows/Fonts/Deng.ttf"),
        Path("/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc" if bold else "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"),
    ]
    for candidate in candidates:
        if candidate.is_file():
            return ImageFont.truetype(str(candidate), size=size)
    return ImageFont.load_default(size=size)


def wrap_text(draw: ImageDraw.ImageDraw, text: str, selected_font: ImageFont.ImageFont, max_width: int) -> list[str]:
    lines: list[str] = []
    current = ""
    for character in text.strip():
        candidate = current + character
        if current and draw.textlength(candidate, font=selected_font) > max_width:
            lines.append(current.rstrip())
            current = character.lstrip()
        else:
            current = candidate
    if current:
        lines.append(current.rstrip())
    return lines


def draw_check(draw: ImageDraw.ImageDraw, x: int, y: int, color: str) -> None:
    draw.rounded_rectangle((x, y, x + 42, y + 42), radius=11, fill=color)
    draw.line((x + 10, y + 22, x + 18, y + 30, x + 33, y + 12), fill="#ffffff", width=5, joint="curve")


def generate(title: str, output: Path, eyebrow: str, footer: str) -> None:
    image = Image.new("RGB", (WIDTH, HEIGHT), "#f3f6f4")
    draw = ImageDraw.Draw(image)

    ink = "#17211d"
    muted = "#52605a"
    red = "#c9363e"
    teal = "#1e7770"
    line = "#d9dfdc"

    draw.rectangle((0, 0, 18, HEIGHT), fill=red)
    draw.rectangle((18, 0, WIDTH, 10), fill=teal)

    mark_x, mark_y = 74, 62
    draw.rounded_rectangle((mark_x, mark_y, mark_x + 52, mark_y + 52), radius=12, fill=ink)
    draw.line((mark_x + 12, mark_y + 26, mark_x + 22, mark_y + 36, mark_x + 40, mark_y + 14), fill="#ffffff", width=6, joint="curve")

    draw.rounded_rectangle((74, 164, 324, 210), radius=8, fill="#e4ece8")
    draw.text((94, 173), eyebrow, font=font(22, bold=True), fill=teal)

    title_font = font(48, bold=True)
    lines = wrap_text(draw, title, title_font, 650)
    while len(lines) > 4 and getattr(title_font, "size", 48) > 40:
        title_font = font(getattr(title_font, "size", 48) - 2, bold=True)
        lines = wrap_text(draw, title, title_font, 650)

    title_y = 242
    line_height = getattr(title_font, "size", 48) + 16
    for index, line_text in enumerate(lines[:4]):
        draw.text((74, title_y + index * line_height), line_text, font=title_font, fill=ink)

    footer_y = min(578, title_y + len(lines[:4]) * line_height + 34)
    draw.line((74, footer_y, 710, footer_y), fill=line, width=2)
    draw.text((74, footer_y + 18), footer, font=font(24), fill=muted)

    panel = (790, 86, 1126, 589)
    draw.rounded_rectangle(panel, radius=24, fill="#ffffff", outline=line, width=2)
    draw.rounded_rectangle((824, 122, 1092, 212), radius=15, fill=ink)
    draw.text((854, 139), "合规核验", font=font(27, bold=True), fill="#ffffff")
    draw.text((854, 178), "发布清单", font=font(17, bold=True), fill="#b9d8d1")

    checklist_y = [254, 330, 406]
    checklist_labels = ["规则依据", "签署证据", "数据合规"]
    checklist_colors = [red, teal, ink]
    for y, label, color in zip(checklist_y, checklist_labels, checklist_colors, strict=True):
        draw_check(draw, 830, y, color)
        draw.text((893, y + 2), label, font=font(21, bold=True), fill=ink)
        draw.line((893, y + 35, 1058, y + 35), fill=line, width=3)

    draw.rounded_rectangle((830, 503, 1086, 547), radius=8, fill="#edf2ef")
    draw.text((855, 511), "签署前逐项核验", font=font(18, bold=True), fill=teal)

    output.parent.mkdir(parents=True, exist_ok=True)
    image.save(output, format="PNG", optimize=True)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--title", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--eyebrow", default="电子签约合规指南")
    parser.add_argument("--footer", default="规则核验 · 签署证据 · 数据合规")
    args = parser.parse_args()
    generate(args.title, args.output, args.eyebrow, args.footer)


if __name__ == "__main__":
    main()
