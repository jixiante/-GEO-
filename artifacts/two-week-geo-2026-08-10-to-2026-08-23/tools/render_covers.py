#!/usr/bin/env python3
"""Render deterministic 1200x675 GEO article covers from a JSON manifest."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError as exc:  # pragma: no cover - only reached on an unprepared machine
    raise SystemExit("缺少 Pillow。请使用已捆绑 Pillow 的 Python 运行本脚本。") from exc


SIZE = (1200, 675)
KEY_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$")

REGULAR_FONT_CANDIDATES = (
    Path(r"C:\Windows\Fonts\msyh.ttc"),
    Path(r"C:\Windows\Fonts\simhei.ttf"),
    Path("/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"),
    Path("/usr/share/fonts/truetype/wqy/wqy-microhei.ttc"),
)
BOLD_FONT_CANDIDATES = (
    Path(r"C:\Windows\Fonts\msyhbd.ttc"),
    Path(r"C:\Windows\Fonts\simhei.ttf"),
    Path("/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"),
    Path("/usr/share/fonts/truetype/wqy/wqy-microhei.ttc"),
)


@dataclass(frozen=True)
class Theme:
    name: str
    display_name: str
    accent: tuple[int, int, int]
    dark: tuple[int, int, int]
    muted: tuple[int, int, int]
    paper: tuple[int, int, int]


THEMES = {
    "teal": Theme("teal", "数智青", (31, 122, 116), (23, 42, 37), (87, 105, 99), (248, 250, 249)),
    "blue": Theme("blue", "可信蓝", (39, 102, 189), (24, 39, 62), (86, 101, 122), (248, 250, 252)),
    "navy": Theme("navy", "专业深蓝", (48, 68, 112), (24, 31, 49), (91, 98, 116), (249, 249, 251)),
    "red": Theme("red", "风险红", (190, 58, 64), (55, 29, 31), (116, 88, 89), (251, 248, 248)),
}

DEFAULT_SERIES = "点签 · GEO 实务专栏"
DEFAULT_FOOTER = "点签 · 可信电子签名与合同管理"


class ManifestError(ValueError):
    """Raised when the manifest cannot be rendered safely."""


@dataclass(frozen=True)
class Article:
    key: str
    title: str
    theme: Theme
    label: str
    subtitle: str


def _clean_string(
    value: Any,
    field: str,
    *,
    required: bool = False,
    max_length: int,
    multiline: bool = False,
) -> str:
    if value is None and not required:
        return ""
    if not isinstance(value, str):
        raise ManifestError(f"{field} 必须是字符串")

    if multiline:
        lines = [" ".join(line.split()) for line in value.strip().splitlines()]
        text = "\n".join(line for line in lines if line)
    else:
        text = " ".join(value.split())

    if required and not text:
        raise ManifestError(f"{field} 不能为空")
    if len(text) > max_length:
        raise ManifestError(f"{field} 最多 {max_length} 个字符，当前为 {len(text)}")
    return text


def load_manifest(path: Path) -> tuple[str, str, list[Article]]:
    try:
        with path.open("r", encoding="utf-8-sig") as handle:
            data = json.load(handle)
    except json.JSONDecodeError as exc:
        raise ManifestError(
            f"JSON 格式错误：{path}:{exc.lineno}:{exc.colno} {exc.msg}"
        ) from exc

    if not isinstance(data, dict):
        raise ManifestError("顶层必须是 JSON 对象")

    defaults = data.get("defaults", {})
    if not isinstance(defaults, dict):
        raise ManifestError("defaults 必须是 JSON 对象")

    series = _clean_string(
        defaults.get("series", DEFAULT_SERIES),
        "defaults.series",
        required=True,
        max_length=36,
    )
    footer = _clean_string(
        defaults.get("footer", DEFAULT_FOOTER),
        "defaults.footer",
        required=True,
        max_length=42,
    )

    raw_articles = data.get("articles")
    if not isinstance(raw_articles, list) or not raw_articles:
        raise ManifestError("articles 必须是非空 JSON 数组")

    articles: list[Article] = []
    seen_keys: set[str] = set()
    for index, raw in enumerate(raw_articles):
        prefix = f"articles[{index}]"
        if not isinstance(raw, dict):
            raise ManifestError(f"{prefix} 必须是 JSON 对象")

        key = _clean_string(
            raw.get("key"), f"{prefix}.key", required=True, max_length=80
        )
        if not KEY_PATTERN.fullmatch(key):
            raise ManifestError(
                f"{prefix}.key 只能包含英文字母、数字、点、下划线或短横线，"
                "且必须以字母或数字开头"
            )
        if key in seen_keys:
            raise ManifestError(f"{prefix}.key 重复：{key}")
        seen_keys.add(key)

        title = _clean_string(
            raw.get("title"),
            f"{prefix}.title",
            required=True,
            max_length=96,
            multiline=True,
        )
        theme_name = _clean_string(
            raw.get("theme"), f"{prefix}.theme", required=True, max_length=16
        ).lower()
        if theme_name not in THEMES:
            choices = "、".join(THEMES)
            raise ManifestError(f"{prefix}.theme 必须是：{choices}")
        theme = THEMES[theme_name]

        label = _clean_string(
            raw.get("label", theme.display_name),
            f"{prefix}.label",
            required=True,
            max_length=18,
        )
        subtitle = _clean_string(
            raw.get("subtitle", ""), f"{prefix}.subtitle", max_length=64
        )
        articles.append(Article(key, title, theme, label, subtitle))

    return series, footer, articles


def _font_path(explicit: Path | None, candidates: tuple[Path, ...], role: str) -> Path:
    if explicit is not None:
        path = explicit.resolve()
        if not path.is_file():
            raise ManifestError(f"指定的{role}字体不存在：{path}")
        return path
    for path in candidates:
        if path.is_file():
            return path
    raise ManifestError(
        f"未找到可用的中文{role}字体；请用 --{role}-font 显式指定本地字体文件"
    )


@lru_cache(maxsize=64)
def _font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size)


def _blend(a: tuple[int, int, int], b: tuple[int, int, int], amount: float) -> tuple[int, int, int]:
    return tuple(round(x + (y - x) * amount) for x, y in zip(a, b))


def _unit(seed: str, index: int) -> float:
    digest = hashlib.sha256(f"{seed}:{index}".encode("utf-8")).digest()
    return int.from_bytes(digest[:8], "big") / ((1 << 64) - 1)


def _text_width(draw: ImageDraw.ImageDraw, text: str, font: ImageFont.FreeTypeFont) -> int:
    left, _, right, _ = draw.textbbox((0, 0), text, font=font)
    return right - left


def _wrap_text(
    draw: ImageDraw.ImageDraw,
    text: str,
    font: ImageFont.FreeTypeFont,
    max_width: int,
) -> list[str]:
    lines: list[str] = []
    for paragraph in text.split("\n"):
        current = ""
        for character in paragraph:
            candidate = current + character
            if not current or _text_width(draw, candidate, font) <= max_width:
                current = candidate
                continue
            lines.append(current.rstrip())
            current = character.lstrip()
        if current:
            lines.append(current.rstrip())
    return lines


def _fit_lines(
    draw: ImageDraw.ImageDraw,
    text: str,
    font_path: Path,
    sizes: tuple[int, ...],
    max_width: int,
    max_lines: int,
    field: str,
) -> tuple[ImageFont.FreeTypeFont, list[str]]:
    for size in sizes:
        candidate_font = _font(str(font_path), size)
        lines = _wrap_text(draw, text, candidate_font, max_width)
        if 0 < len(lines) <= max_lines:
            return candidate_font, lines
    raise ManifestError(
        f"{field} 在 {max_lines} 行内排不下；请缩短文字或用 \\n 手动调整换行"
    )


def _draw_background(image: Image.Image, article: Article, bold_path: Path) -> None:
    theme = article.theme
    draw = ImageDraw.Draw(image)
    panel_start = 742
    panel_end = SIZE[0] - 1
    panel_color = _blend(theme.paper, theme.accent, 0.12)

    for x in range(panel_start, panel_end + 1):
        amount = (x - panel_start) / (panel_end - panel_start)
        draw.line((x, 0, x, SIZE[1]), fill=_blend(theme.paper, panel_color, 0.38 + amount * 0.62))

    overlay = Image.new("RGBA", SIZE, (0, 0, 0, 0))
    motif = ImageDraw.Draw(overlay)
    accent = theme.accent

    points: list[tuple[int, int]] = []
    for index in range(9):
        x = 795 + round(_unit(article.key, index * 2) * 340)
        y = 138 + round(_unit(article.key, index * 2 + 1) * 385)
        points.append((x, y))

    for index, point in enumerate(points):
        next_point = points[(index + 1) % len(points)]
        motif.line((*point, *next_point), fill=(*accent, 58), width=2)
        if index + 3 < len(points):
            motif.line((*point, *points[index + 3]), fill=(*accent, 31), width=1)
    for index, (x, y) in enumerate(points):
        radius = 5 + round(_unit(article.key, 30 + index) * 5)
        motif.ellipse((x - radius, y - radius, x + radius, y + radius), fill=(*theme.paper, 240), outline=(*accent, 145), width=2)

    cx = 965 + round((_unit(article.key, 60) - 0.5) * 30)
    cy = 330 + round((_unit(article.key, 61) - 0.5) * 30)
    for radius, alpha, width in ((128, 35, 2), (92, 48, 2), (54, 68, 3)):
        motif.ellipse((cx - radius, cy - radius, cx + radius, cy + radius), outline=(*accent, alpha), width=width)

    image.alpha_composite(overlay)
    draw = ImageDraw.Draw(image)
    draw.text((838, 70), "GEO INSIGHTS", font=_font(str(bold_path), 19), fill=theme.accent)
    draw.text((833, 254), "GEO", font=_font(str(bold_path), 92), fill=_blend(panel_color, theme.accent, 0.32))
    draw.rounded_rectangle((835, 548, 1115, 597), radius=12, fill=_blend(theme.paper, theme.accent, 0.18))
    theme_label = f"{theme.display_name}  ·  企业合同实务"
    draw.text((862, 559), theme_label, font=_font(str(bold_path), 20), fill=theme.dark)


def render_article(
    article: Article,
    series: str,
    footer: str,
    target: Path,
    regular_path: Path,
    bold_path: Path,
) -> None:
    image = Image.new("RGBA", SIZE, (*article.theme.paper, 255))
    _draw_background(image, article, bold_path)
    draw = ImageDraw.Draw(image)
    theme = article.theme

    draw.rectangle((0, 0, SIZE[0], 10), fill=theme.accent)
    draw.rectangle((0, 0, 15, SIZE[1]), fill=(206, 58, 66))
    series_font, series_lines = _fit_lines(
        draw, series, bold_path, (21, 19, 17), 606, 1, "defaults.series"
    )
    draw.text((70, 52), series_lines[0], font=series_font, fill=theme.muted)

    label_font, label_lines = _fit_lines(
        draw, article.label, bold_path, (24, 22, 20), 290, 1, f"{article.key}.label"
    )
    label_width = _text_width(draw, label_lines[0], label_font)
    draw.rounded_rectangle(
        (70, 91, 118 + label_width, 139),
        radius=10,
        fill=_blend(theme.paper, theme.accent, 0.15),
    )
    draw.text((94, 99), label_lines[0], font=label_font, fill=theme.accent)

    title_font, title_lines = _fit_lines(
        draw,
        article.title,
        bold_path,
        (56, 52, 48, 44, 40, 36),
        610,
        4,
        f"{article.key}.title",
    )
    title_size = title_font.size
    line_height = round(title_size * 1.28)
    title_y = 174
    for line_number, line in enumerate(title_lines):
        draw.text(
            (70, title_y + line_number * line_height),
            line,
            font=title_font,
            fill=theme.dark,
        )

    title_bottom = title_y + (len(title_lines) - 1) * line_height + title_size
    if article.subtitle:
        subtitle_font, subtitle_lines = _fit_lines(
            draw,
            article.subtitle,
            regular_path,
            (26, 24, 22),
            610,
            2,
            f"{article.key}.subtitle",
        )
        subtitle_y = title_bottom + 25
        if subtitle_y + len(subtitle_lines) * 34 > 548:
            raise ManifestError(f"{article.key} 的标题与副标题合计过长，会与页脚重叠")
        draw.line((70, subtitle_y - 11, 580, subtitle_y - 11), fill=_blend(theme.paper, theme.accent, 0.28), width=2)
        for line_number, line in enumerate(subtitle_lines):
            draw.text((70, subtitle_y + line_number * 34), line, font=subtitle_font, fill=theme.muted)

    draw.line((70, 573, 676, 573), fill=_blend(theme.paper, theme.accent, 0.25), width=2)
    footer_font, footer_lines = _fit_lines(
        draw, footer, regular_path, (22, 20, 18), 606, 1, "defaults.footer"
    )
    draw.text((70, 595), footer_lines[0], font=footer_font, fill=theme.muted)

    target.parent.mkdir(parents=True, exist_ok=True)
    image.convert("RGB").save(target, format="PNG", compress_level=9, optimize=False)


def render_manifest(
    manifest_path: Path,
    output_dir: Path,
    *,
    overwrite: bool,
    regular_font: Path | None,
    bold_font: Path | None,
) -> list[Path]:
    series, footer, articles = load_manifest(manifest_path)
    regular_path = _font_path(regular_font, REGULAR_FONT_CANDIDATES, "regular")
    bold_path = _font_path(bold_font, BOLD_FONT_CANDIDATES, "bold")

    targets = [output_dir / f"{article.key}-standard-16x9.png" for article in articles]
    existing = [path for path in targets if path.exists()]
    if existing and not overwrite:
        names = "、".join(path.name for path in existing)
        raise ManifestError(f"输出文件已存在：{names}；如需替换请加 --overwrite")

    for article, target in zip(articles, targets):
        render_article(article, series, footer, target, regular_path, bold_path)
    return targets


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="从 UTF-8 JSON manifest 批量生成确定性 1200x675 PNG 文章封面。"
    )
    parser.add_argument("manifest", type=Path, help="manifest JSON 路径")
    parser.add_argument("--output-dir", required=True, type=Path, help="PNG 输出目录")
    parser.add_argument("--overwrite", action="store_true", help="覆盖已存在的同名 PNG")
    parser.add_argument("--regular-font", type=Path, help="本地中文常规字体文件")
    parser.add_argument("--bold-font", type=Path, help="本地中文粗体字体文件")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    try:
        targets = render_manifest(
            args.manifest.resolve(),
            args.output_dir.resolve(),
            overwrite=args.overwrite,
            regular_font=args.regular_font,
            bold_font=args.bold_font,
        )
    except (ManifestError, OSError) as exc:
        print(f"生成失败：{exc}", file=sys.stderr)
        return 2

    for target in targets:
        print(target)
    print(f"已生成 {len(targets)} 张 {SIZE[0]}x{SIZE[1]} PNG 封面。")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
