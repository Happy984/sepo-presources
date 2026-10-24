<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * Plugin settings, stored as one option array.
 *
 * Every read goes through get() so a missing or partial option always
 * falls back to the defaults below.
 */
final class Settings
{
    public const OPTION = 'sepo_resources_settings';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'base'                   => 'resources',
            'archive_title'          => __('Resources', 'sepo-resources'),
            'archive_intro'          => '',
            'per_page'               => 10,
            'ungrouped_label'        => __('Other documents', 'sepo-resources'),
            'default_allow_download' => 1,
            'default_requires_login' => 0,
            'accent'                 => '#0b3d33',
            'count_window'           => 60,
            'protect_uploads'        => 1,
            'log_activity'           => 1,
            'geo_header'             => 1,
            'geo_provider'           => 'none',
            'geo_token'              => '',
            'ip_source'              => 'REMOTE_ADDR',
            'keep_ip'                => 0,
            'keep_agent'             => 1,
            'count_bots'             => 0,
            'log_retention'          => 365,
            'delete_on_uninstall'    => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, []);
            self::$cache = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        }
        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? (self::defaults()[$key] ?? null);
    }

    public static function base(): string
    {
        $base = sanitize_title((string)self::get('base'));
        return $base !== '' ? $base : 'resources';
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function sanitize(array $in): array
    {
        $d = self::defaults();
        $accent = sanitize_hex_color((string)($in['accent'] ?? '')) ?: $d['accent'];

        return [
            'base'                   => sanitize_title((string)($in['base'] ?? '')) ?: $d['base'],
            'archive_title'          => sanitize_text_field((string)($in['archive_title'] ?? $d['archive_title'])),
            'archive_intro'          => wp_kses_post((string)($in['archive_intro'] ?? '')),
            'per_page'               => max(1, min(200, (int)($in['per_page'] ?? $d['per_page']))),
            'ungrouped_label'        => sanitize_text_field((string)($in['ungrouped_label'] ?? $d['ungrouped_label'])),
            'default_allow_download' => empty($in['default_allow_download']) ? 0 : 1,
            'default_requires_login' => empty($in['default_requires_login']) ? 0 : 1,
            'accent'                 => $accent,
            'count_window'           => max(0, min(86400, (int)($in['count_window'] ?? $d['count_window']))),
            'protect_uploads'        => empty($in['protect_uploads']) ? 0 : 1,
            'log_activity'           => empty($in['log_activity']) ? 0 : 1,
            'geo_header'             => empty($in['geo_header']) ? 0 : 1,
            'geo_provider'           => in_array($in['geo_provider'] ?? '', ['none', 'countryis', 'ipapi', 'ipinfo'], true) ? (string)$in['geo_provider'] : 'none',
            'geo_token'              => sanitize_text_field((string)($in['geo_token'] ?? '')),
            'ip_source'              => in_array($in['ip_source'] ?? '', ['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP'], true) ? (string)$in['ip_source'] : 'REMOTE_ADDR',
            'keep_ip'                => empty($in['keep_ip']) ? 0 : 1,
            'keep_agent'             => empty($in['keep_agent']) ? 0 : 1,
            'count_bots'             => empty($in['count_bots']) ? 0 : 1,
            'log_retention'          => max(0, min(3650, (int)($in['log_retention'] ?? $d['log_retention']))),
            'delete_on_uninstall'    => empty($in['delete_on_uninstall']) ? 0 : 1,
        ];
    }
}
