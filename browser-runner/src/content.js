import { ManualActionError, RunnerError } from './errors.js';

export function articleFromRequest(request, platform) {
  const article = request?.payload?.article;
  if (!article || typeof article !== 'object') {
    throw new RunnerError('发布载荷缺少 article 字段。', { code: 'invalid_payload', status: 422 });
  }

  const title = String(article.title ?? '').trim();
  const markdown = String(article.content ?? '').trim();
  const html = String(article.content_html ?? '').trim();
  if (title === '' || (markdown === '' && html === '')) {
    throw new RunnerError('文章标题和正文不能为空。', { code: 'invalid_payload', status: 422 });
  }

  const configuredTitleLimit = platform.maxTitleLength;
  const titleLimit = configuredTitleLimit ?? 120;
  const titleLength = Array.from(title).length;
  if (configuredTitleLimit != null && titleLength > titleLimit) {
    throw new ManualActionError(
      `${platform.label ?? '当前平台'}标题最多 ${titleLimit} 个字符，当前为 ${titleLength} 个字符，请修改标题后重试。`,
      { title_limit: titleLimit, title_length: titleLength },
    );
  }
  const bodyLimit = platform.maxBodyLength ?? null;
  const plain = htmlToPlainText(html || markdown);

  return {
    title: truncate(title, titleLimit),
    markdown,
    html: sanitizeArticleHtml(html || plainTextToHtml(markdown)),
    plain: bodyLimit ? truncate(plain, bodyLimit) : plain,
    keywords: String(article.keywords ?? '').split(/[,，;；\s]+/).filter(Boolean).slice(0, 10),
  };
}

export function uploadFilesFromRequest(request, platform) {
  const files = imageFilesFromRequest(request);

  if (platform.requiresImage && files.length === 0) {
    throw new ManualActionError('小红书图文至少需要一张本地图片，请先给文章设置题图后重试。');
  }

  return files;
}

export function coverFileFromRequest(request, platform) {
  if (!platform.requiresCover) {
    return null;
  }

  const cover = imageFilesFromRequest(request, 1)[0] ?? null;
  if (!cover) {
    throw new ManualActionError(`${platform.label}发布需要一张本地封面图，请先给文章设置题图后重试。`);
  }

  return cover;
}

export function sanitizeArticleHtml(html) {
  return String(html)
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '')
    .replace(/\son\w+\s*=\s*(['"])[\s\S]*?\1/gi, '')
    .replace(/\s(href|src)\s*=\s*(['"])\s*javascript:[\s\S]*?\2/gi, '')
    .trim();
}

export function htmlToPlainText(html) {
  return String(html)
    .replace(/<\s*br\s*\/?\s*>/gi, '\n')
    .replace(/<\/(p|div|h[1-6]|li|blockquote)>/gi, '\n')
    .replace(/<li\b[^>]*>/gi, '• ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

function plainTextToHtml(text) {
  const escaped = String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
  return escaped.split(/\n{2,}/).map((paragraph) => `<p>${paragraph.replace(/\n/g, '<br>')}</p>`).join('');
}

function imageFilesFromRequest(request, limit = 9) {
  const images = Array.isArray(request?.payload?.assets?.images) ? request.payload.assets.images : [];
  const files = [];
  for (const [index, image] of images.entries()) {
    const content = typeof image?.content_base64 === 'string' ? image.content_base64 : '';
    const mimeType = typeof image?.mime_type === 'string' ? image.mime_type : '';
    if (content === '' || !mimeType.startsWith('image/')) {
      continue;
    }
    const buffer = Buffer.from(content, 'base64');
    if (buffer.length === 0 || buffer.length > 10 * 1024 * 1024) {
      continue;
    }
    files.push({
      name: safeFilename(image.filename, mimeType, index),
      mimeType,
      buffer,
    });
    if (files.length >= limit) {
      break;
    }
  }
  return files;
}

function truncate(value, limit) {
  return Array.from(String(value)).slice(0, limit).join('');
}

function safeFilename(filename, mimeType, index) {
  const extension = {
    'image/jpeg': 'jpg',
    'image/png': 'png',
    'image/webp': 'webp',
    'image/gif': 'gif',
  }[mimeType] ?? 'img';
  const candidate = String(filename ?? '').replace(/[^A-Za-z0-9._-]/g, '_');
  return candidate !== '' ? candidate : `image-${index + 1}.${extension}`;
}
