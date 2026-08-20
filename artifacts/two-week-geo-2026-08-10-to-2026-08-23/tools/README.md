# GEO 文章封面批量生成器

`render_covers.py` 读取 UTF-8 JSON manifest，批量输出 1200×675 RGB PNG。背景几何纹理由文章 `key` 稳定生成，不请求网络，不依赖外部背景图。同一环境下，相同 manifest 与字体会产生字节一致的文件。

## 使用

```powershell
python .\render_covers.py .\sample_manifest.json --output-dir .\preview
```

默认使用本机微软雅黑，也可用 `--regular-font` 和 `--bold-font` 指定本地中文字体。已存在的输出不会被默认覆盖；确认替换时加 `--overwrite`。输出文件名为 `<key>-standard-16x9.png`。

## Manifest 结构

参见 `sample_manifest.json`。`articles` 中每项必须提供：

- `key`：唯一文件键，限英文字母、数字、点、下划线和短横线；
- `title`：标题，可在字符串中用 `\n` 强制换行；
- `theme`：`teal`、`blue`、`navy` 或 `red`。

`label` 和 `subtitle` 可选。顶层 `defaults.series` 和 `defaults.footer` 也可省略。标题和副标题会自动换行；若排版空间不足，脚本会报错而不是截断文字。

## 依赖

- Python 3.10+
- Pillow
- 本地中文字体（Windows 上默认检测微软雅黑）
