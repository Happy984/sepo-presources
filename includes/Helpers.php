<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * Small render helpers shared by the listing and single-document views.
 */
final class Helpers
{
    public static function bytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($n >= 10 || $i === 0 ? round($n) : round($n, 1)) . ' ' . $units[$i];
    }

    /** @return array{0:string,1:string} [label, css class] */
    public static function format(string $ext): array
    {
        $ext = strtolower(trim($ext, '. '));
        $map = [
            'pdf'  => ['PDF', 'pdf'],
            'doc'  => ['DOC', 'doc'],
            'docx' => ['DOCX', 'doc'],
            'rtf'  => ['RTF', 'doc'],
            'odt'  => ['ODT', 'doc'],
            'xls'  => ['XLS', 'sheet'],
            'xlsx' => ['XLSX', 'sheet'],
            'csv'  => ['CSV', 'sheet'],
            'ods'  => ['ODS', 'sheet'],
            'ppt'  => ['PPT', 'slides'],
            'pptx' => ['PPTX', 'slides'],
            'odp'  => ['ODP', 'slides'],
            'zip'  => ['ZIP', 'archive'],
            'rar'  => ['RAR', 'archive'],
            '7z'   => ['7Z', 'archive'],
            'txt'  => ['TXT', 'text'],
            'md'   => ['MD', 'text'],
            'epub' => ['EPUB', 'book'],
            'png'  => ['PNG', 'image'],
            'jpg'  => ['JPG', 'image'],
            'jpeg' => ['JPG', 'image'],
            'svg'  => ['SVG', 'image'],
        ];
        return $map[$ext] ?? [strtoupper($ext !== '' ? $ext : 'FILE'), 'other'];
    }

    public static function mime(string $ext): string
    {
        return [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'rtf'  => 'application/rtf',
            'odt'  => 'application/vnd.oasis.opendocument.text',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
            'csv'  => 'text/csv',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odp'  => 'application/vnd.oasis.opendocument.presentation',
            'zip'  => 'application/zip',
            'epub' => 'application/epub+zip',
            'txt'  => 'text/plain',
            'md'   => 'text/markdown',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ][strtolower($ext)] ?? 'application/octet-stream';
    }

    public static function when(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts !== false ? date_i18n('j M Y', $ts) : $raw;
    }

    /** @param array<string,mixed> $d */
    public static function stamp(array $d): string
    {
        $bits = [];
        if ($d['author'] !== '') {
            $bits[] = $d['author'];
        }
        if ($d['published'] !== '') {
            $bits[] = self::when($d['published']);
        }
        if ($d['size'] !== '') {
            $bits[] = $d['size'];
        }
        if ($d['pages'] !== '') {
            /* translators: %s: number of pages */
            $bits[] = sprintf(_n('%s page', '%s pages', (int)$d['pages'], 'sepo-resources'), $d['pages']);
        }
        return implode(' · ', $bits);
    }

    public static function loginUrl(string $back = ''): string
    {
        return wp_login_url($back !== '' ? $back : (string)(is_singular() ? get_permalink() : home_url(add_query_arg([]))));
    }

    /** @param array<string,mixed> $d */
    public static function likeButton(array $d, int $count, bool $liked, bool $loggedIn, string $size = ''): string
    {
        $cls = 'spr-like' . ($size === 'sm' ? ' spr-like--sm' : '') . ($liked ? ' is-on' : '');
        if (!$loggedIn) {
            return '<a class="' . $cls . '" href="' . esc_url(self::loginUrl($d['url'])) . '" title="' . esc_attr__('Sign in to save this document', 'sepo-resources') . '">'
                . '<span class="spr-heart" aria-hidden="true">&#9825;</span><span class="spr-like-n">' . $count . '</span></a>';
        }
        return '<button type="button" class="' . $cls . '"'
            . ' data-spr-like="' . (int)$d['id'] . '"'
            . ' aria-pressed="' . ($liked ? 'true' : 'false') . '"'
            . ' title="' . esc_attr($liked ? __('Remove your like', 'sepo-resources') : __('Like this document', 'sepo-resources')) . '">'
            . '<span class="spr-heart" aria-hidden="true">' . ($liked ? '&#9829;' : '&#9825;') . '</span>'
            . '<span class="spr-like-n">' . $count . '</span></button>';
    }

    /** @param array<string,mixed> $d */
    public static function downloadLink(array $d, bool $loggedIn, int $count = 0, string $variant = ''): string
    {
        $sm = $variant === 'sm' ? ' spr-btn--sm' : '';
        if (!$d['download'] || !$d['has_file']) {
            return '<span class="spr-btn spr-btn--muted' . $sm . '">' . esc_html__('Not available', 'sepo-resources') . '</span>';
        }
        if ($d['gated'] && !$loggedIn) {
            return '<a class="spr-btn' . $sm . '" href="' . esc_url(self::loginUrl($d['url'])) . '">'
                . esc_html__('Sign in to download', 'sepo-resources') . '</a>';
        }

        $label = $variant === 'sm'
            ? esc_html__('Download', 'sepo-resources')
            /* translators: %s: file format, e.g. PDF */
            : esc_html(sprintf(__('Download %s', 'sepo-resources'), $d['format'])) . ($d['size'] !== '' ? ' &middot; ' . esc_html($d['size']) : '');

        /* translators: %d: number of downloads */
        $title = sprintf(_n('%d download', '%d downloads', $count, 'sepo-resources'), $count);

        return '<a class="spr-btn spr-btn--primary' . $sm . '" href="' . esc_url($d['src']) . '"'
            . ($d['external'] ? ' rel="noopener"' : ' download')
            . ' data-spr-dl="' . (int)$d['id'] . '"'
            . ' title="' . esc_attr($title) . '">' . $label . '</a>';
    }

    /** @param array<string,mixed> $d */
    public static function dlBadge(array $d, int $count): string
    {
        return '<span class="spr-dl-n" data-spr-dl-n="' . (int)$d['id'] . '" title="' . esc_attr__('Downloads', 'sepo-resources') . '">'
            . '<span class="spr-dl-i" aria-hidden="true">&#8595;</span><span class="spr-dl-v">' . $count . '</span></span>';
    }

    /** @param array<string,mixed> $d */
    public static function thumb(array $d, string $size = ''): string
    {
        $cls = 'spr-thumb' . ($size === 'lg' ? ' spr-thumb--lg' : '');
        $badge = '<span class="spr-format spr-format--' . esc_attr($d['format_class']) . '">' . esc_html($d['format']) . '</span>';
        if ($d['image'] !== '') {
            return '<span class="' . $cls . '" style="background-image:url(\'' . esc_url($d['image']) . '\')">' . $badge . '</span>';
        }
        return '<span class="' . $cls . ' spr-thumb--plain">' . $badge . '</span>';
    }
}
