<?php

namespace App\Support\Admin;

use App\Support\Site\ArticleHtmlPresenter;
use DOMDocument;
use DOMElement;

final class ToutiaoArticleHtmlExporter
{
    /**
     * Render Markdown as semantic HTML that Toutiao can normalize in its editor.
     */
    public function toHtml(string $markdown): string
    {
        $body = ArticleHtmlPresenter::markdownToHtml($markdown);
        if (trim($body) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><section data-geoflow-export="toutiao-article">'.$body.'</section>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
                break;
            }
        }

        $root = $dom->getElementsByTagName('section')->item(0);
        if (! $root instanceof DOMElement) {
            return $body;
        }

        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement) {
                $this->normalizeAttributes($node);
            }
        }

        $html = '';
        foreach ($root->childNodes as $node) {
            $html .= $dom->saveHTML($node) ?: '';
        }

        return trim($html);
    }

    public function toPlainText(string $html): string
    {
        $html = preg_replace('/<\/(h[1-6]|p|blockquote|li|tr|table|pre)>/iu', "</$1>\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizeAttributes(DOMElement $node): void
    {
        $tag = strtolower($node->tagName);
        $allowed = match ($tag) {
            'a' => ['href', 'title'],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            'td', 'th' => ['colspan', 'rowspan'],
            default => [],
        };

        for ($index = $node->attributes->length - 1; $index >= 0; $index--) {
            $attribute = $node->attributes->item($index);
            if ($attribute !== null && ! in_array(strtolower($attribute->name), $allowed, true)) {
                $node->removeAttribute($attribute->name);
            }
        }
    }
}
