<?php

declare(strict_types=1);

namespace SepoResources;

use InvalidArgumentException;

/**
 * Likes and download counts for every document, in one table.
 *
 * Rules:
 *   - A like is presence: one row per person, count always 1, toggling
 *     removes the row.
 *   - A download is one row per person carrying a running tally, not one
 *     row per event, so the table grows with the audience, not the traffic.
 *   - Signed-out visitors share the '~guest' row.
 *
 * The (resource_id, kind, actor) unique key makes recordDownload() a single
 * atomic INSERT ... ON DUPLICATE KEY UPDATE, so two simultaneous downloads
 * never lose an increment.
 *
 * Totals are also mirrored to post meta (_spr_downloads, _spr_likes) after
 * every write so wp-admin can sort by them without a join.
 */
final class EventStore
{
    public const LIKE = 'like';
    public const DOWNLOAD = 'download';
    public const GUEST = '~guest';

    /** @var array<int,array{likes:int,downloads:int}> */
    private static array $countCache = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sepo_resource_events';
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
  kind varchar(16) NOT NULL,
  actor varchar(64) NOT NULL,
  name varchar(120) NOT NULL DEFAULT '',
  count int(10) unsigned NOT NULL DEFAULT 1,
  created datetime NOT NULL,
  last datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY resource_kind_actor (resource_id,kind,actor),
  KEY kind_actor (kind,actor)
) {$charset};");
    }

    public static function dropTable(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table()); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** The actor id for a user; '~guest' when signed out. */
    public static function actor(int $userId): string
    {
        return $userId > 0 ? (string)$userId : self::GUEST;
    }

    // ------------------------------------------------------------------ read

    /**
     * Likes + downloads for one document, and this viewer's own state.
     *
     * @return array{likes:int,downloads:int,unique:int,liked:bool,yours:int}
     */
    public static function summary(int $resourceId, int $userId = 0): array
    {
        global $wpdb;
        $table = self::table();
        $actor = self::actor($userId);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT kind, actor, count FROM {$table} WHERE resource_id = %d",
            $resourceId
        ), ARRAY_A) ?: [];

        $out = ['likes' => 0, 'downloads' => 0, 'unique' => 0, 'liked' => false, 'yours' => 0];
        foreach ($rows as $r) {
            if ($r['kind'] === self::LIKE) {
                $out['likes']++;
                if ($userId > 0 && $r['actor'] === $actor) {
                    $out['liked'] = true;
                }
            } elseif ($r['kind'] === self::DOWNLOAD) {
                $out['downloads'] += (int)$r['count'];
                $out['unique']++;
                if ($r['actor'] === $actor) {
                    $out['yours'] = (int)$r['count'];
                }
            }
        }
        return $out;
    }

    public static function downloadTotal(int $resourceId): int
    {
        global $wpdb;
        $table = self::table();
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(count),0) FROM {$table} WHERE resource_id = %d AND kind = %s",
            $resourceId,
            self::DOWNLOAD
        ));
    }

    /**
     * Likes + downloads for a whole listing in ONE query.
     *
     * @param int[] $ids
     * @return array<int,array{likes:int,downloads:int}>
     */
    public static function counts(array $ids): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        $missing = [];
        foreach ($ids as $id) {
            if (isset(self::$countCache[$id])) {
                $out[$id] = self::$countCache[$id];
            } else {
                $out[$id] = ['likes' => 0, 'downloads' => 0];
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return $out;
        }

        $table = self::table();
        $in = implode(',', array_fill(0, count($missing), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT resource_id, kind, COUNT(*) AS n, COALESCE(SUM(count),0) AS total
             FROM {$table} WHERE resource_id IN ({$in}) GROUP BY resource_id, kind",
            ...$missing
        ), ARRAY_A) ?: [];

        foreach ($rows as $r) {
            $id = (int)$r['resource_id'];
            if ($r['kind'] === self::LIKE) {
                $out[$id]['likes'] = (int)$r['n'];
            } elseif ($r['kind'] === self::DOWNLOAD) {
                $out[$id]['downloads'] = (int)$r['total'];
            }
        }
        foreach ($missing as $id) {
            self::$countCache[$id] = $out[$id];
        }
        return $out;
    }

    /** @return int[] ids of every document this user has liked */
    public static function likedIds(int $userId): array
    {
        global $wpdb;
        if ($userId <= 0) {
            return [];
        }
        $table = self::table();
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT resource_id FROM {$table} WHERE kind = %s AND actor = %s",
            self::LIKE,
            (string)$userId
        )) ?: []);
    }

    /**
     * Most recent actors for the admin screen.
     *
     * @return list<array{actor:string,name:string,count:int,last:string}>
     */
    public static function recent(int $resourceId, string $kind, int $limit = 10): array
    {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT actor, name, count, last FROM {$table}
             WHERE resource_id = %d AND kind = %s ORDER BY last DESC LIMIT %d",
            $resourceId,
            $kind,
            $limit
        ), ARRAY_A) ?: [];
        return array_map(static fn(array $r): array => [
            'actor' => (string)$r['actor'],
            'name'  => (string)$r['name'],
            'count' => (int)$r['count'],
            'last'  => (string)$r['last'],
        ], $rows);
    }

    // ----------------------------------------------------------------- write

    /**
     * Like if not liked, unlike if liked.
     *
     * @return array{liked:bool,count:int}
     */
    public static function toggleLike(int $resourceId, int $userId, string $name = ''): array
    {
        global $wpdb;
        if ($userId <= 0) {
            throw new InvalidArgumentException(__('You must be signed in to like a document.', 'sepo-resources'));
        }
        $table = self::table();
        $actor = (string)$userId;

        $removed = (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE resource_id = %d AND kind = %s AND actor = %s",
            $resourceId,
            self::LIKE,
            $actor
        ));

        $liked = false;
        if ($removed === 0) {
            $now = current_time('mysql', true);
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (resource_id, kind, actor, name, count, created, last)
                 VALUES (%d, %s, %s, %s, 1, %s, %s)",
                $resourceId,
                self::LIKE,
                $actor,
                mb_substr(trim($name), 0, 120),
                $now,
                $now
            ));
            $liked = true;
        }

        Activity::log($resourceId, $liked ? Activity::LIKE : Activity::UNLIKE, $userId, $name);

        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE resource_id = %d AND kind = %s",
            $resourceId,
            self::LIKE
        ));

        self::afterWrite($resourceId);
        return ['liked' => $liked, 'count' => $count];
    }

    /**
     * Count one download. Pass 0 for a signed-out visitor.
     *
     * @return array{total:int,yours:int}
     */
    public static function recordDownload(int $resourceId, int $userId = 0, string $name = ''): array
    {
        global $wpdb;
        $table = self::table();
        $actor = self::actor($userId);
        $now = current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (resource_id, kind, actor, name, count, created, last)
             VALUES (%d, %s, %s, %s, 1, %s, %s)
             ON DUPLICATE KEY UPDATE count = count + 1, last = VALUES(last),
               name = IF(name = '', VALUES(name), name)",
            $resourceId,
            self::DOWNLOAD,
            $actor,
            mb_substr(trim($name), 0, 120),
            $now,
            $now
        ));

        $yours = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT count FROM {$table} WHERE resource_id = %d AND kind = %s AND actor = %s",
            $resourceId,
            self::DOWNLOAD,
            $actor
        ));

        Activity::log($resourceId, Activity::DOWNLOAD, $userId, $name);

        self::afterWrite($resourceId);
        return ['total' => self::downloadTotal($resourceId), 'yours' => $yours];
    }

    /**
     * Clear a document's rows. $what: all | likes | downloads.
     * Returns false when there was nothing to clear.
     */
    public static function reset(int $resourceId, string $what = 'all'): bool
    {
        global $wpdb;
        $what = strtolower(trim($what));
        if (!in_array($what, ['all', 'likes', 'downloads'], true)) {
            throw new InvalidArgumentException(__('Unknown reset target.', 'sepo-resources'));
        }
        $table = self::table();

        if ($what === 'all') {
            $n = (int)$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE resource_id = %d", $resourceId));
            Activity::clear($resourceId);
        } else {
            Activity::clear($resourceId, $what === 'likes' ? [Activity::LIKE, Activity::UNLIKE] : [Activity::DOWNLOAD]);
            $n = (int)$wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE resource_id = %d AND kind = %s",
                $resourceId,
                $what === 'likes' ? self::LIKE : self::DOWNLOAD
            ));
        }

        self::afterWrite($resourceId);
        return $n > 0;
    }

    public static function deleteFor(int $resourceId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE resource_id = %d', $resourceId));
        Activity::clear($resourceId);
        unset(self::$countCache[$resourceId]);
    }

    /** Refresh the per-request cache and the sortable post-meta totals. */
    private static function afterWrite(int $resourceId): void
    {
        unset(self::$countCache[$resourceId]);
        $c = self::counts([$resourceId])[$resourceId] ?? ['likes' => 0, 'downloads' => 0];
        update_post_meta($resourceId, '_spr_downloads', $c['downloads']);
        update_post_meta($resourceId, '_spr_likes', $c['likes']);
    }
}
