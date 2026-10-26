<?php

namespace App\Support;

use Statamic\Facades\AssetContainer;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;

/**
 * Rich text that members write in the editor on the Share page (public/js/rich-text.js), stored as
 * Bard content. Browsers can send anything, so the HTML is read with Statamic's own Bard parser and then
 * rebuilt keeping only what the editor's toolbar offers: paragraphs, two heading sizes, bold, italic,
 * lists, quotes, line breaks, web/email/phone links, and photos the caller allows (the member's own
 * uploads, stored as asset references like the control panel does). Other images, embeds, tables, styles
 * and scripts are dropped; their text is kept as paragraphs.
 */
class RichText
{
    const MARKS = ['bold', 'italic', 'link'];

    const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    const MAX_DEPTH = 6;

    /** Asset container that photos in the text come from. */
    const CONTAINER = 'assets';

    /** Decides which photo paths may stay; set for the duration of toBard(). */
    private static ?\Closure $allowImage = null;

    /**
     * Member HTML → Bard value (a list of nodes), or null when there is neither text nor a photo.
     *
     * @param  (\Closure(string): bool)|null  $allowImage  gets an asset path ("posts/members/5/x.jpg")
     */
    public static function toBard(?string $html, ?\Closure $allowImage = null): ?array
    {
        $html = trim((string) $html);
        if ($html === '' || mb_strlen($html) > 200000) {
            return null;
        }

        self::$allowImage = $allowImage;
        try {
            $document = self::augmentor()->renderHtmlToProsemirror($html);
            $blocks = self::tidy(self::blocks($document['content'] ?? [], 0));
        } finally {
            self::$allowImage = null;
        }

        return self::textLength($blocks) > 0 || self::imagePaths($blocks) ? $blocks : null;
    }

    /**
     * Paths of the photos in Bard content, in order.
     *
     * @return list<string>
     */
    public static function imagePaths(?array $nodes): array
    {
        $paths = [];
        foreach ($nodes ?? [] as $node) {
            $src = (string) ($node['attrs']['src'] ?? '');
            if (($node['type'] ?? null) === 'image' && str_starts_with($src, 'asset::'.self::CONTAINER.'::')) {
                $paths[] = substr($src, strlen('asset::'.self::CONTAINER.'::'));
            }
            array_push($paths, ...self::imagePaths($node['content'] ?? []));
        }

        return $paths;
    }

    /** The asset path for a photo address in the editor ("/assets/x.jpg", full URL or asset reference). */
    public static function assetPath(string $src): ?string
    {
        if (str_starts_with($src, 'asset::'.self::CONTAINER.'::')) {
            $path = substr($src, strlen('asset::'.self::CONTAINER.'::'));
        } else {
            $prefix = rtrim((string) parse_url((string) AssetContainer::find(self::CONTAINER)?->url(), PHP_URL_PATH), '/').'/';
            $url = (string) parse_url($src, PHP_URL_PATH);
            $host = parse_url($src, PHP_URL_HOST);
            if ($prefix === '/' || ! str_starts_with($url, $prefix) || ($host && $host !== request()->getHost())) {
                return null;
            }
            $path = rawurldecode(substr($url, strlen($prefix)));
        }

        return $path !== '' && ! str_contains($path, '..') && ! str_contains($path, '\\') ? $path : null;
    }

    /** A Bard value (nodes, or HTML saved by older code) as HTML for the editor. */
    public static function toHtml(mixed $value): string
    {
        $nodes = is_string($value) ? self::toBard($value) : (is_array($value) ? $value : null);

        return $nodes ? self::augmentor()->renderProsemirrorToHtml(['type' => 'doc', 'content' => array_values($nodes)]) : '';
    }

    /** How many characters of text the content has. */
    public static function textLength(?array $nodes): int
    {
        $length = 0;
        foreach ($nodes ?? [] as $node) {
            $length += mb_strlen($node['text'] ?? '') + self::textLength($node['content'] ?? []);
        }

        return $length;
    }

    /** Block nodes; loose text is gathered into paragraphs. */
    private static function blocks(array $nodes, int $depth): array
    {
        $blocks = [];
        $inline = [];
        $flush = function () use (&$blocks, &$inline) {
            if ($inline) {
                $blocks[] = ['type' => 'paragraph', 'content' => $inline];
                $inline = [];
            }
        };

        foreach ($nodes as $node) {
            $type = is_array($node) ? ($node['type'] ?? null) : null;

            if ($type === 'text' || $type === 'hardBreak' || $type === 'image') {
                array_push($inline, ...self::inline([$node]));

                continue;
            }

            $flush();
            if ($depth >= self::MAX_DEPTH) {
                $content = self::inline(self::flatten($node['content'] ?? []));
                $blocks[] = ['type' => 'paragraph'] + ($content ? ['content' => $content] : []);

                continue;
            }

            switch ($type) {
                case 'paragraph':
                    $content = self::inline($node['content'] ?? []);
                    $blocks[] = ['type' => 'paragraph'] + ($content ? ['content' => $content] : []);
                    break;

                case 'heading':
                    if ($content = self::inline($node['content'] ?? [])) {
                        $level = min(3, max(2, (int) ($node['attrs']['level'] ?? 2)));
                        $blocks[] = ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => $content];
                    }
                    break;

                case 'bulletList':
                case 'orderedList':
                    $items = [];
                    foreach ($node['content'] ?? [] as $item) {
                        $children = ($item['type'] ?? null) === 'listItem' ? ($item['content'] ?? []) : [$item];
                        // Headings inside a list item become plain paragraphs.
                        $content = array_map(fn ($block) => $block['type'] === 'heading' ? ['type' => 'paragraph', 'content' => $block['content']] : $block, self::blocks($children, $depth + 1));
                        if ($content = self::tidy($content)) {
                            $items[] = ['type' => 'listItem', 'content' => $content];
                        }
                    }
                    if ($items) {
                        $start = (int) ($node['attrs']['start'] ?? 1);
                        $blocks[] = ['type' => $type] + ($type === 'orderedList' && $start > 1 ? ['attrs' => ['start' => $start]] : []) + ['content' => $items];
                    }
                    break;

                case 'blockquote':
                    if ($content = self::tidy(self::blocks($node['content'] ?? [], $depth + 1))) {
                        $blocks[] = ['type' => 'blockquote', 'content' => $content];
                    }
                    break;

                default:
                    // Tables, code blocks, unknown wrappers: keep their text. Images, rules and embeds have none.
                    array_push($blocks, ...self::blocks($node['content'] ?? [], $depth + 1));
            }
        }
        $flush();

        return $blocks;
    }

    /** Text and line breaks with allowed marks; anything else inside a block is flattened to its text. */
    private static function inline(array $nodes): array
    {
        $inline = [];
        foreach (self::flatten($nodes) as $node) {
            if ($node['type'] === 'hardBreak') {
                $inline[] = ['type' => 'hardBreak'];

                continue;
            }
            if ($node['type'] === 'image') {
                $path = self::assetPath((string) ($node['attrs']['src'] ?? ''));
                if ($path && self::$allowImage && (self::$allowImage)($path)) {
                    $alt = PlainText::line((string) ($node['attrs']['alt'] ?? ''));
                    $size = $node['attrs']['size'] ?? null;
                    $align = $node['attrs']['align'] ?? null;
                    // Size and position as in app/Bard/PhotoLayout.php; full width and centred are the default.
                    $inline[] = ['type' => 'image', 'attrs' => array_filter([
                        'src' => 'asset::'.self::CONTAINER.'::'.$path,
                        'alt' => $alt !== '' ? $alt : null,
                        'size' => in_array($size, ['small', 'medium'], true) ? $size : null,
                        'align' => in_array($align, ['left', 'right'], true) ? $align : null,
                    ], fn ($value) => $value !== null)];
                }

                continue;
            }

            $text = str_replace("\u{0}", '', (string) $node['text']);
            if ($text === '') {
                continue;
            }
            $marks = self::marks($node['marks'] ?? []);
            $inline[] = ['type' => 'text', 'text' => $text] + ($marks ? ['marks' => $marks] : []);
        }

        return $inline;
    }

    /** All text and line-break nodes inside these nodes, in order. */
    private static function flatten(array $nodes): array
    {
        $flat = [];
        foreach ($nodes as $node) {
            $type = is_array($node) ? ($node['type'] ?? null) : null;
            if ($type === 'text' && isset($node['text'])) {
                $flat[] = $node;
            } elseif ($type === 'hardBreak' || $type === 'image') {
                $flat[] = $node;
            } elseif (is_array($node['content'] ?? null)) {
                array_push($flat, ...self::flatten($node['content']));
            }
        }

        return $flat;
    }

    private static function marks(array $marks): array
    {
        $kept = [];
        foreach ($marks as $mark) {
            $type = $mark['type'] ?? null;
            if (! in_array($type, self::MARKS, true) || isset($kept[$type])) {
                continue;
            }
            if ($type === 'link') {
                $href = trim((string) ($mark['attrs']['href'] ?? ''));
                $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
                if (! in_array($scheme, self::LINK_SCHEMES, true) || preg_match('/[\s"\'<>`]/', $href)) {
                    continue;
                }
                $kept[$type] = ['type' => 'link', 'attrs' => ['href' => $href, 'rel' => 'nofollow ugc noopener', 'target' => '_blank']];

                continue;
            }
            $kept[$type] = ['type' => $type];
        }

        return array_values($kept);
    }

    /** No empty paragraphs at the start or end, and never two in a row. */
    private static function tidy(array $blocks): array
    {
        $tidy = [];
        foreach ($blocks as $block) {
            $empty = $block['type'] === 'paragraph' && empty($block['content']);
            if ($empty && (! $tidy || (end($tidy)['type'] === 'paragraph' && empty(end($tidy)['content'])))) {
                continue;
            }
            $tidy[] = $block;
        }
        while ($tidy && end($tidy)['type'] === 'paragraph' && empty(end($tidy)['content'])) {
            array_pop($tidy);
        }

        return $tidy;
    }

    private static function augmentor(): Augmentor
    {
        $field = new Field('content', ['type' => 'bard', 'save_html' => false]);

        return new Augmentor($field->fieldtype());
    }
}
