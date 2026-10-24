<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * The activity log: one row per like, unlike and download, with where the
 * visitor was and what they used — country, browser, system, device.
 *
 * This sits beside EventStore. EventStore keeps the running totals the
 * library shows; this keeps the detail an editor reads in wp-admin. Rows
 * older than the retention period are purged daily.
 *
 * Country comes from, in order:
 *   1. a CDN or host header (Cloudflare's CF-IPCountry and the like), at once;
 *   2. an IP lookup service chosen in settings, done later by WP-cron so the
 *      download is never slowed down. Answers are cached per IP for 30 days.
 * The IP address is kept only until the country is known, unless the
 * "Keep IP addresses" setting is on.
 */
final class Activity
{
    public const DOWNLOAD = 'download';
    public const LIKE = 'like';
    public const UNLIKE = 'unlike';

    public const CRON_RESOLVE = 'spr_resolve_countries';
    public const CRON_PURGE = 'spr_activity_purge';

    private const LOOKUP_BATCH = 40;
    /** Give up on an address after this long without an answer. */
    private const LOOKUP_GIVE_UP = 2 * DAY_IN_SECONDS;

    public static function init(): void
    {
        add_action(self::CRON_RESOLVE, [self::class, 'resolveCountries']);
        add_action(self::CRON_PURGE, [self::class, 'purge']);
        add_action('init', [self::class, 'schedule']);
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sepo_resource_log';
    }

    public static function createTable(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NOT NULL,
  kind varchar(10) NOT NULL,
  counted tinyint(1) unsigned NOT NULL DEFAULT 1,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(120) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  browser varchar(40) NOT NULL DEFAULT '',
  browser_version varchar(16) NOT NULL DEFAULT '',
  os varchar(40) NOT NULL DEFAULT '',
  device varchar(10) NOT NULL DEFAULT '',
  is_bot tinyint(1) unsigned NOT NULL DEFAULT 0,
  user_agent varchar(255) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_created (resource_id,created),
  KEY kind_created (kind,created),
  KEY created (created),
  KEY user_id (user_id),
  KEY ip_country (ip,country)
) {$charset};");
    }

    public static function dropTable(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table()); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_PURGE)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_PURGE);
        wp_clear_scheduled_hook(self::CRON_RESOLVE);
    }

    // ----------------------------------------------------------------- write

    /**
     * Record one event for the current visitor. Never throws: a logging
     * problem must not stop a download or a like.
     */
    public static function log(int $resourceId, string $kind, int $userId = 0, string $name = '', bool $counted = true): void
    {
        if (!(int)Settings::get('log_activity')) {
            return;
        }
        try {
            global $wpdb;
            $v = Visitor::current();

            $country = $v['country'];
            $ip = $v['ip'];
            $provider = (string)Settings::get('geo_provider');
            $keepIp = (int)Settings::get('keep_ip') === 1;
            $needsLookup = false;

            if ($country === '' && $provider !== 'none' && Visitor::isPublicIp($ip)) {
                $cached = get_transient(self::cacheKey($ip));
                if (is_string($cached) && $cached !== '') {
                    $country = $cached === '--' ? '' : $cached;
                } else {
                    $needsLookup = true;
                }
            }
            // The address stays only while a lookup is waiting for it, or when the site asks to keep it.
            $storeIp = ($keepIp || $needsLookup) ? $ip : '';

            $wpdb->insert(self::table(), [
                'resource_id'     => $resourceId,
                'kind'            => $kind,
                'counted'         => $counted ? 1 : 0,
                'user_id'         => max(0, $userId),
                'name'            => mb_substr(trim($name), 0, 120),
                'country'         => $country,
                'browser'         => mb_substr($v['browser'], 0, 40),
                'browser_version' => mb_substr($v['browser_version'], 0, 16),
                'os'              => mb_substr($v['os'], 0, 40),
                'device'          => $v['device'],
                'is_bot'          => $v['bot'] ? 1 : 0,
                'user_agent'      => (int)Settings::get('keep_agent') ? $v['user_agent'] : '',
                'ip'              => $storeIp,
                'created'         => current_time('mysql', true),
            ], ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);

            if ($needsLookup && !wp_next_scheduled(self::CRON_RESOLVE)) {
                wp_schedule_single_event(time() + 30, self::CRON_RESOLVE);
            }

            /**
             * Fires after a like, unlike or download is logged.
             *
             * @param int    $resourceId
             * @param string $kind      download | like | unlike
             * @param array  $visitor   browser, os, device, bot, country, ip
             */
            do_action('sepo_resources_activity', $resourceId, $kind, $v + ['country' => $country]);
        } catch (\Throwable $e) {
            error_log('[sepo-resources] activity log failed: ' . $e->getMessage());
        }
    }

    /** Remove a document's log rows; $kind null clears all of them. */
    public static function clear(int $resourceId, ?array $kinds = null): void
    {
        global $wpdb;
        $table = self::table();
        if ($kinds === null) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE resource_id = %d", $resourceId)); // phpcs:ignore WordPress.DB.PreparedSQL
            return;
        }
        $in = implode(',', array_fill(0, count($kinds), '%s'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE resource_id = %d AND kind IN ({$in})", $resourceId, ...$kinds)); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    // ------------------------------------------------------------- countries

    private static function cacheKey(string $ip): string
    {
        return 'spr_geo_' . md5($ip);
    }

    /**
     * WP-cron: put a country on rows that are waiting for one, then forget
     * their IP addresses (unless they are to be kept).
     */
    public static function resolveCountries(): void
    {
        global $wpdb;
        $table = self::table();
        $keepIp = (int)Settings::get('keep_ip') === 1;

        $ips = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ip FROM {$table} WHERE country = '' AND ip <> '' AND created >= %s LIMIT %d",
            gmdate('Y-m-d H:i:s', time() - self::LOOKUP_GIVE_UP),
            self::LOOKUP_BATCH
        )) ?: [];

        foreach ($ips as $ip) {
            $country = self::lookup((string)$ip);
            if ($country === null) {
                continue;   // no answer this time; try again on the next run
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET country = %s" . ($keepIp ? '' : ", ip = ''") . " WHERE ip = %s AND country = ''",
                $country,
                $ip
            ));
        }

        // Anything still waiting gets another run shortly.
        $left = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE country = '' AND ip <> '' AND created >= %s",
            gmdate('Y-m-d H:i:s', time() - self::LOOKUP_GIVE_UP)
        ));
        if ($left > 0 && $ips !== [] && !wp_next_scheduled(self::CRON_RESOLVE)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::CRON_RESOLVE);
        }
    }

    /**
     * Country for an IP: a two-letter code, '' when the service does not
     * know, or null when it could not be asked (try later).
     */
    public static function lookup(string $ip): ?string
    {
        if (!Visitor::isPublicIp($ip)) {
            return '';
        }
        $cached = get_transient(self::cacheKey($ip));
        if (is_string($cached) && $cached !== '') {
            return $cached === '--' ? '' : $cached;
        }

        /**
         * Short-circuit the lookup, e.g. with a local MaxMind database.
         * Return a two-letter code, '' for unknown, or null to carry on.
         */
        $pre = apply_filters('sepo_resources_country_lookup', null, $ip);
        if (is_string($pre)) {
            $c = Countries::normalise($pre);
            set_transient(self::cacheKey($ip), $c !== '' ? $c : '--', 30 * DAY_IN_SECONDS);
            return $c;
        }

        $provider = (string)Settings::get('geo_provider');
        $token = trim((string)Settings::get('geo_token'));
        $url = match ($provider) {
            'ipinfo'    => 'https://ipinfo.io/' . rawurlencode($ip) . '/country' . ($token !== '' ? '?token=' . rawurlencode($token) : ''),
            'ipapi'     => 'https://ipapi.co/' . rawurlencode($ip) . '/country/',
            'countryis' => 'https://api.country.is/' . rawurlencode($ip),
            default     => '',
        };
        if ($url === '') {
            return '';
        }

        $res = wp_remote_get($url, [
            'timeout'    => 4,
            'user-agent' => 'Sepo Resources/' . SPR_VERSION . '; ' . home_url('/'),
            'headers'    => ['Accept' => 'application/json, text/plain'],
        ]);
        if (is_wp_error($res)) {
            return null;
        }
        $code = (int)wp_remote_retrieve_response_code($res);
        $body = trim((string)wp_remote_retrieve_body($res));
        if ($code === 429 || $code >= 500) {
            return null;                    // busy or over quota: later
        }
        if ($code !== 200) {
            set_transient(self::cacheKey($ip), '--', DAY_IN_SECONDS);
            return '';
        }
        if ($provider === 'countryis') {
            $json = json_decode($body, true);
            $body = is_array($json) ? (string)($json['country'] ?? '') : '';
        }
        $c = Countries::normalise($body);
        set_transient(self::cacheKey($ip), $c !== '' ? $c : '--', ($c !== '' ? 30 : 1) * DAY_IN_SECONDS);
        return $c;
    }

    /** WP-cron, daily: drop rows past the retention period and stale IPs. */
    public static function purge(): void
    {
        global $wpdb;
        $table = self::table();
        $days = (int)Settings::get('log_retention');
        if ($days > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE created < %s",
                gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
            ));
        }
        if ((int)Settings::get('keep_ip') !== 1) {
            // Lookups that never answered: forget the address anyway.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET ip = '' WHERE ip <> '' AND created < %s",
                gmdate('Y-m-d H:i:s', time() - self::LOOKUP_GIVE_UP)
            ));
        }
    }

    // ------------------------------------------------------------------ read

    /**
     * Normalised report filters from a request array.
     *
     * @param array<string,mixed> $in
     * @return array{doc:int,kind:string,from:string,to:string,country:string,device:string,browser:string,bots:string,who:string}
     */
    public static function filters(array $in): array
    {
        $date = static function ($v): string {
            $v = is_string($v) ? trim($v) : '';
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
        };
        $pick = static fn($v, array $ok, string $def): string => in_array($v, $ok, true) ? (string)$v : $def;
        $country = isset($in['country']) ? strtoupper(sanitize_key((string)$in['country'])) : '';

        return [
            'doc'     => max(0, (int)($in['doc'] ?? 0)),
            'kind'    => $pick($in['kind'] ?? '', ['', self::DOWNLOAD, self::LIKE, self::UNLIKE], ''),
            'from'    => $date($in['from'] ?? ''),
            'to'      => $date($in['to'] ?? ''),
            'country' => $country === 'UNKNOWN' ? '--' : Countries::normalise($country),
            'device'  => $pick($in['device'] ?? '', ['', 'desktop', 'mobile', 'tablet', 'tv', 'bot'], ''),
            'browser' => isset($in['browser']) ? mb_substr(sanitize_text_field((string)$in['browser']), 0, 40) : '',
            'bots'    => $pick($in['bots'] ?? '', ['', 'include', 'only'], ''),
            'who'     => $pick($in['who'] ?? '', ['', 'members', 'guests'], ''),
        ];
    }

    /**
     * @param array<string,mixed> $f filters()
     * @return array{0:string,1:list<mixed>}
     */
    private static function where(array $f): array
    {
        $sql = ['1=1'];
        $args = [];
        if ($f['doc'] > 0) {
            $sql[] = 'resource_id = %d';
            $args[] = $f['doc'];
        }
        if ($f['kind'] !== '') {
            $sql[] = 'kind = %s';
            $args[] = $f['kind'];
        }
        if ($f['from'] !== '') {
            $sql[] = 'created >= %s';
            $args[] = get_gmt_from_date($f['from'] . ' 00:00:00');
        }
        if ($f['to'] !== '') {
            $sql[] = 'created <= %s';
            $args[] = get_gmt_from_date($f['to'] . ' 23:59:59');
        }
        if ($f['country'] === '--') {
            $sql[] = "country = ''";
        } elseif ($f['country'] !== '') {
            $sql[] = 'country = %s';
            $args[] = $f['country'];
        }
        if ($f['device'] !== '') {
            $sql[] = 'device = %s';
            $args[] = $f['device'];
        }
        if ($f['browser'] !== '') {
            $sql[] = 'browser = %s';
            $args[] = $f['browser'];
        }
        if ($f['bots'] === '') {
            $sql[] = 'is_bot = 0';
        } elseif ($f['bots'] === 'only') {
            $sql[] = 'is_bot = 1';
        }
        if ($f['who'] === 'members') {
            $sql[] = 'user_id > 0';
        } elseif ($f['who'] === 'guests') {
            $sql[] = 'user_id = 0';
        }
        return [implode(' AND ', $sql), $args];
    }

    /** @param list<mixed> $args */
    private static function prep(string $sql, array $args): string
    {
        global $wpdb;
        return $args === [] ? $sql : (string)$wpdb->prepare($sql, ...$args); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /**
     * Headline numbers for the filters.
     *
     * @param array<string,mixed> $f
     * @return array{downloads:int,likes:int,unlikes:int,countries:int,people:int,bots:int,events:int}
     */
    public static function totals(array $f): array
    {
        global $wpdb;
        $table = self::table();
        [$w, $a] = self::where($f);
        $row = $wpdb->get_row(self::prep(
            "SELECT COUNT(*) events,
                SUM(kind = 'download' AND counted = 1) downloads,
                SUM(kind = 'like') likes,
                SUM(kind = 'unlike') unlikes,
                COUNT(DISTINCT NULLIF(country, '')) countries,
                COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) people
             FROM {$table} WHERE {$w}",
            $a
        ), ARRAY_A) ?: [];

        // Bot hits are shown separately, whatever the bot filter says.
        $fb = ['bots' => 'only'] + $f;
        [$wb, $ab] = self::where($fb);
        $bots = (int)$wpdb->get_var(self::prep("SELECT COUNT(*) FROM {$table} WHERE {$wb}", $ab));

        return [
            'events'    => (int)($row['events'] ?? 0),
            'downloads' => (int)($row['downloads'] ?? 0),
            'likes'     => (int)($row['likes'] ?? 0),
            'unlikes'   => (int)($row['unlikes'] ?? 0),
            'countries' => (int)($row['countries'] ?? 0),
            'people'    => (int)($row['people'] ?? 0),
            'bots'      => $bots,
        ];
    }

    /**
     * Counts grouped by one field, biggest first.
     *
     * @param array<string,mixed> $f
     * @return list<array{value:string,n:int}>
     */
    public static function breakdown(string $field, array $f, int $limit = 10): array
    {
        if (!in_array($field, ['country', 'browser', 'os', 'device'], true)) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        [$w, $a] = self::where($f);
        $a[] = $limit;
        $rows = $wpdb->get_results(self::prep(
            "SELECT {$field} AS value, COUNT(*) AS n FROM {$table} WHERE {$w} GROUP BY {$field} ORDER BY n DESC, value ASC LIMIT %d",
            $a
        ), ARRAY_A) ?: [];
        return array_map(static fn(array $r): array => ['value' => (string)$r['value'], 'n' => (int)$r['n']], $rows);
    }

    /**
     * One page of events, newest first.
     *
     * @param array<string,mixed> $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function events(array $f, int $page = 1, int $per = 50): array
    {
        global $wpdb;
        $table = self::table();
        [$w, $a] = self::where($f);
        $total = (int)$wpdb->get_var(self::prep("SELECT COUNT(*) FROM {$table} WHERE {$w}", $a));
        $args = array_merge($a, [$per, max(0, ($page - 1) * $per)]);
        $rows = $wpdb->get_results(self::prep(
            "SELECT * FROM {$table} WHERE {$w} ORDER BY created DESC, id DESC LIMIT %d OFFSET %d",
            $args
        ), ARRAY_A) ?: [];
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Every matching row, in chunks, for the CSV export.
     *
     * @param array<string,mixed> $f
     * @return \Generator<array<string,mixed>>
     */
    public static function each(array $f): \Generator
    {
        global $wpdb;
        $table = self::table();
        [$w, $a] = self::where($f);
        $lastId = PHP_INT_MAX;
        do {
            $args = array_merge($a, [$lastId, 1000]);
            $rows = $wpdb->get_results(self::prep(
                "SELECT * FROM {$table} WHERE {$w} AND id < %d ORDER BY id DESC LIMIT %d",
                $args
            ), ARRAY_A) ?: [];
            foreach ($rows as $r) {
                $lastId = (int)$r['id'];
                yield $r;
            }
        } while (count($rows) === 1000);
    }

    /** Distinct browsers seen, for the filter menu. @return list<string> */
    public static function browsers(): array
    {
        global $wpdb;
        $table = self::table();
        return array_values(array_filter(array_map('strval', $wpdb->get_col("SELECT DISTINCT browser FROM {$table} ORDER BY browser") ?: []))); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** Distinct countries seen, for the filter menu. @return list<string> */
    public static function countriesSeen(): array
    {
        global $wpdb;
        $table = self::table();
        return array_values(array_filter(array_map('strval', $wpdb->get_col("SELECT DISTINCT country FROM {$table}") ?: []))); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::DOWNLOAD => __('Download', 'sepo-resources'),
            self::LIKE     => __('Like', 'sepo-resources'),
            self::UNLIKE   => __('Unlike', 'sepo-resources'),
            default        => $kind,
        };
    }

    /** "Chrome 129" */
    public static function browserLabel(array $r): string
    {
        $b = (string)($r['browser'] ?? '');
        if ($b === '') {
            return __('Unknown', 'sepo-resources');
        }
        $v = (string)($r['browser_version'] ?? '');
        return $v !== '' ? $b . ' ' . $v : $b;
    }

    // --------------------------------------------------------------- privacy

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit, int $offset): array
    {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        ), ARRAY_A) ?: [];
    }

    public static function eraseUser(int $userId): int
    {
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE user_id = %d', $userId)); // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
