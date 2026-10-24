<?php

declare(strict_types=1);

namespace SepoResources\Admin;

use SepoResources\Activator;
use SepoResources\Activity;
use SepoResources\Countries;
use SepoResources\PostType;
use SepoResources\Settings;
use SepoResources\Visitor;

/**
 * Resources → Activity: who liked and downloaded what, from which country,
 * with which browser, system and device. Filters, breakdowns, the event
 * list and a CSV export.
 */
final class ActivityPage
{
    public const SLUG = 'spr-activity';
    private const PER_PAGE = 50;

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 9);
        add_action('admin_post_spr_activity_csv', [self::class, 'csv']);
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(
            array_merge(['post_type' => PostType::POST_TYPE, 'page' => self::SLUG], array_filter($args, static fn($v) => $v !== '' && $v !== 0 && $v !== null)),
            admin_url('edit.php')
        );
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . PostType::POST_TYPE,
            __('Resource activity', 'sepo-resources'),
            __('Activity', 'sepo-resources'),
            Activator::CAP,
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to view this page.', 'sepo-resources'));
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only report filters
        $f = Activity::filters(wp_unslash($_GET));
        $page = max(1, (int)($_GET['paged'] ?? 1));
        // phpcs:enable

        $totals = Activity::totals($f);
        $events = Activity::events($f, $page, self::PER_PAGE);
        $base = self::query($f);
        ?>
        <div class="wrap spr-activity">
            <h1 class="wp-heading-inline"><?php esc_html_e('Activity', 'sepo-resources'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge(['action' => 'spr_activity_csv'], $base), admin_url('admin-post.php')), 'spr_activity_csv')); ?>"><?php esc_html_e('Export CSV', 'sepo-resources'); ?></a>
            <hr class="wp-header-end">

            <?php if (!(int)Settings::get('log_activity')) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php esc_html_e('Activity logging is off, so nothing new is being recorded.', 'sepo-resources'); ?>
                    <a href="<?php echo esc_url(SettingsPage::url() . '#spr-visitors'); ?>"><?php esc_html_e('Turn it on in Settings', 'sepo-resources'); ?></a>
                </p></div>
            <?php endif; ?>

            <?php self::filterForm($f); ?>

            <ul class="spr-kpis">
                <?php
                $kpis = [
                    [__('Downloads', 'sepo-resources'), $totals['downloads']],
                    [__('Likes', 'sepo-resources'), $totals['likes']],
                    [__('Unlikes', 'sepo-resources'), $totals['unlikes']],
                    [__('Countries', 'sepo-resources'), $totals['countries']],
                    [__('Signed-in people', 'sepo-resources'), $totals['people']],
                    [__('Bot hits (not counted)', 'sepo-resources'), $totals['bots']],
                ];
                foreach ($kpis as [$label, $n]) {
                    printf('<li><span>%s</span><strong>%s</strong></li>', esc_html($label), esc_html(number_format_i18n($n)));
                }
                ?>
            </ul>

            <div class="spr-breakdowns">
                <?php
                self::panel(__('Countries', 'sepo-resources'), 'country', Activity::breakdown('country', $f, 12), $totals['events'], $f);
                self::panel(__('Browsers', 'sepo-resources'), 'browser', Activity::breakdown('browser', $f, 10), $totals['events'], $f);
                self::panel(__('Systems', 'sepo-resources'), 'os', Activity::breakdown('os', $f, 10), $totals['events'], $f);
                self::panel(__('Devices', 'sepo-resources'), 'device', Activity::breakdown('device', $f, 6), $totals['events'], $f);
                ?>
            </div>

            <h2 class="spr-h2">
                <?php
                printf(
                    /* translators: %s: number of events */
                    esc_html(_n('%s event', '%s events', $events['total'], 'sepo-resources')),
                    esc_html(number_format_i18n($events['total']))
                );
                ?>
            </h2>
            <?php
            self::eventsTable($events['rows'], $f['doc'] === 0);
            $pages = (int)ceil($events['total'] / self::PER_PAGE);
            if ($pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([ // phpcs:ignore WordPress.Security.EscapeOutput
                    'base'      => add_query_arg('paged', '%#%', self::url($base)),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                ]);
                echo '</div></div>';
            }
            ?>
            <p class="description spr-foot">
                <?php
                $days = (int)Settings::get('log_retention');
                echo esc_html($days > 0
                    ? sprintf(/* translators: %d: days */ _n('Events are kept for %d day.', 'Events are kept for %d days.', $days, 'sepo-resources'), $days)
                    : __('Events are kept until you delete them.', 'sepo-resources'));
                echo ' ';
                esc_html_e('Times are in the site’s time zone.', 'sepo-resources');
                ?>
                <a href="<?php echo esc_url(SettingsPage::url() . '#spr-visitors'); ?>"><?php esc_html_e('Visitor settings', 'sepo-resources'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Filters as URL parameters, without empty ones.
     *
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    public static function query(array $f): array
    {
        $q = array_filter($f, static fn($v) => $v !== '' && $v !== 0);
        if (($q['country'] ?? '') === '--') {
            $q['country'] = 'unknown';
        }
        return $q;
    }

    /** @param array<string,mixed> $f */
    private static function filterForm(array $f): void
    {
        $docs = get_posts([
            'post_type'      => PostType::POST_TYPE,
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);
        $seen = Activity::countriesSeen();
        $all = Countries::all();
        ?>
        <form class="spr-filters" method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>">
            <input type="hidden" name="post_type" value="<?php echo esc_attr(PostType::POST_TYPE); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <label><span><?php esc_html_e('Document', 'sepo-resources'); ?></span>
                <select name="doc">
                    <option value=""><?php esc_html_e('All documents', 'sepo-resources'); ?></option>
                    <?php foreach ($docs as $id) : ?>
                        <option value="<?php echo (int)$id; ?>" <?php selected($f['doc'], (int)$id); ?>><?php echo esc_html(get_the_title((int)$id) ?: '#' . $id); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php esc_html_e('Action', 'sepo-resources'); ?></span>
                <select name="kind">
                    <option value=""><?php esc_html_e('All', 'sepo-resources'); ?></option>
                    <?php foreach ([Activity::DOWNLOAD => __('Downloads', 'sepo-resources'), Activity::LIKE => __('Likes', 'sepo-resources'), Activity::UNLIKE => __('Unlikes', 'sepo-resources')] as $k => $label) : ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($f['kind'], $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php esc_html_e('Country', 'sepo-resources'); ?></span>
                <select name="country">
                    <option value=""><?php esc_html_e('All countries', 'sepo-resources'); ?></option>
                    <?php foreach ($all as $code => $name) : if (!in_array($code, $seen, true) && $f['country'] !== $code) { continue; } ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($f['country'], $code); ?>><?php echo esc_html(Countries::flag($code) . ' ' . $name); ?></option>
                    <?php endforeach; ?>
                    <option value="unknown" <?php selected($f['country'], '--'); ?>><?php esc_html_e('Unknown', 'sepo-resources'); ?></option>
                </select>
            </label>
            <label><span><?php esc_html_e('Browser', 'sepo-resources'); ?></span>
                <select name="browser">
                    <option value=""><?php esc_html_e('All browsers', 'sepo-resources'); ?></option>
                    <?php foreach (Activity::browsers() as $b) : ?>
                        <option value="<?php echo esc_attr($b); ?>" <?php selected($f['browser'], $b); ?>><?php echo esc_html($b); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php esc_html_e('Device', 'sepo-resources'); ?></span>
                <select name="device">
                    <option value=""><?php esc_html_e('All devices', 'sepo-resources'); ?></option>
                    <?php foreach (['desktop', 'mobile', 'tablet', 'tv'] as $d) : ?>
                        <option value="<?php echo esc_attr($d); ?>" <?php selected($f['device'], $d); ?>><?php echo esc_html(Visitor::deviceLabel($d)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php esc_html_e('People', 'sepo-resources'); ?></span>
                <select name="who">
                    <option value=""><?php esc_html_e('Everyone', 'sepo-resources'); ?></option>
                    <option value="members" <?php selected($f['who'], 'members'); ?>><?php esc_html_e('Signed in', 'sepo-resources'); ?></option>
                    <option value="guests" <?php selected($f['who'], 'guests'); ?>><?php esc_html_e('Signed out', 'sepo-resources'); ?></option>
                </select>
            </label>
            <label><span><?php esc_html_e('Bots', 'sepo-resources'); ?></span>
                <select name="bots">
                    <option value=""><?php esc_html_e('Hide bots', 'sepo-resources'); ?></option>
                    <option value="include" <?php selected($f['bots'], 'include'); ?>><?php esc_html_e('Include bots', 'sepo-resources'); ?></option>
                    <option value="only" <?php selected($f['bots'], 'only'); ?>><?php esc_html_e('Bots only', 'sepo-resources'); ?></option>
                </select>
            </label>
            <label><span><?php esc_html_e('From', 'sepo-resources'); ?></span><input type="date" name="from" value="<?php echo esc_attr($f['from']); ?>"></label>
            <label><span><?php esc_html_e('To', 'sepo-resources'); ?></span><input type="date" name="to" value="<?php echo esc_attr($f['to']); ?>"></label>
            <div class="spr-filters-go">
                <button class="button button-primary"><?php esc_html_e('Filter', 'sepo-resources'); ?></button>
                <a class="button-link" href="<?php echo esc_url(self::url()); ?>"><?php esc_html_e('Clear', 'sepo-resources'); ?></a>
            </div>
        </form>
        <?php
    }

    /**
     * One breakdown card. Each row links to the report filtered by it.
     *
     * @param list<array{value:string,n:int}> $rows
     * @param array<string,mixed>|null $f  current filters; null for no links
     */
    public static function panel(string $title, string $field, array $rows, int $total, ?array $f = null): void
    {
        echo '<section class="spr-panel"><h3>' . esc_html($title) . '</h3>';
        if ($rows === []) {
            echo '<p class="spr-empty">' . esc_html__('Nothing yet.', 'sepo-resources') . '</p></section>';
            return;
        }
        $max = max(1, $rows[0]['n']);
        echo '<ul class="spr-bars">';
        foreach ($rows as $r) {
            $label = match ($field) {
                'country' => Countries::label($r['value']),
                'device'  => esc_html(Visitor::deviceLabel($r['value'])),
                default   => esc_html($r['value'] !== '' ? $r['value'] : __('Unknown', 'sepo-resources')),
            };
            $pct = $total > 0 ? round($r['n'] * 100 / $total) : 0;
            $width = round($r['n'] * 100 / $max, 1);
            if ($f !== null && in_array($field, ['country', 'browser', 'device'], true) && ($r['value'] !== '' || $field === 'country')) {
                $param = $field === 'country' && $r['value'] === '' ? 'unknown' : $r['value'];
                $keep = self::query($f);
                $label = '<a href="' . esc_url(self::url(array_merge($keep, [$field => $param]))) . '">' . $label . '</a>';
            }
            printf(
                '<li><div class="spr-bar-row"><span class="spr-bar-label">%s</span><span class="spr-bar-n">%s <em>%s%%</em></span></div><div class="spr-bar"><i style="width:%s%%"></i></div></li>',
                $label, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
                esc_html(number_format_i18n($r['n'])),
                esc_html((string)$pct),
                esc_attr((string)$width)
            );
        }
        echo '</ul></section>';
    }

    /** @param list<array<string,mixed>> $rows */
    public static function eventsTable(array $rows, bool $showDoc = true): void
    {
        if ($rows === []) {
            echo '<p class="spr-empty">' . esc_html__('No likes or downloads match.', 'sepo-resources') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped spr-events">
            <thead><tr>
                <th><?php esc_html_e('When', 'sepo-resources'); ?></th>
                <?php if ($showDoc) : ?><th><?php esc_html_e('Document', 'sepo-resources'); ?></th><?php endif; ?>
                <th><?php esc_html_e('Action', 'sepo-resources'); ?></th>
                <th><?php esc_html_e('Person', 'sepo-resources'); ?></th>
                <th><?php esc_html_e('Country', 'sepo-resources'); ?></th>
                <th><?php esc_html_e('Browser', 'sepo-resources'); ?></th>
                <th><?php esc_html_e('System', 'sepo-resources'); ?></th>
                <th><?php esc_html_e('Device', 'sepo-resources'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r) :
                $uid = (int)$r['user_id'];
                $ts = (int)strtotime($r['created'] . ' UTC');
                ?>
                <tr>
                    <td data-label="<?php esc_attr_e('When', 'sepo-resources'); ?>"><span title="<?php echo esc_attr(wp_date('Y-m-d H:i:s', $ts)); ?>"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts)); ?></span></td>
                    <?php if ($showDoc) : ?>
                        <td data-label="<?php esc_attr_e('Document', 'sepo-resources'); ?>"><a href="<?php echo esc_url(self::url(['doc' => (int)$r['resource_id']])); ?>"><?php echo esc_html(get_the_title((int)$r['resource_id']) ?: '#' . $r['resource_id']); ?></a></td>
                    <?php endif; ?>
                    <td data-label="<?php esc_attr_e('Action', 'sepo-resources'); ?>">
                        <span class="spr-kind spr-kind--<?php echo esc_attr((string)$r['kind']); ?>"><?php echo esc_html(Activity::kindLabel((string)$r['kind'])); ?></span>
                        <?php if (!(int)$r['counted']) : ?><span class="spr-muted"><?php esc_html_e('not counted', 'sepo-resources'); ?></span><?php endif; ?>
                    </td>
                    <td data-label="<?php esc_attr_e('Person', 'sepo-resources'); ?>">
                        <?php
                        if ($uid > 0) {
                            $name = (string)$r['name'] !== '' ? (string)$r['name'] : '#' . $uid;
                            echo current_user_can('edit_user', $uid)
                                ? '<a href="' . esc_url(get_edit_user_link($uid)) . '">' . esc_html($name) . '</a>'
                                : esc_html($name);
                        } else {
                            echo '<span class="spr-muted">' . esc_html__('Signed-out visitor', 'sepo-resources') . '</span>';
                        }
                        if ((string)$r['ip'] !== '') {
                            echo '<br><code class="spr-ip">' . esc_html((string)$r['ip']) . '</code>';
                        }
                        ?>
                    </td>
                    <td data-label="<?php esc_attr_e('Country', 'sepo-resources'); ?>"><?php echo Countries::label((string)$r['country']); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    <td data-label="<?php esc_attr_e('Browser', 'sepo-resources'); ?>">
                        <span<?php echo (string)$r['user_agent'] !== '' ? ' title="' . esc_attr((string)$r['user_agent']) . '"' : ''; ?>><?php echo esc_html(Activity::browserLabel($r)); ?></span>
                        <?php if ((string)$r['user_agent'] !== '') : ?>
                            <details class="spr-ua"><summary><?php esc_html_e('Agent', 'sepo-resources'); ?></summary><code><?php echo esc_html((string)$r['user_agent']); ?></code></details>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?php esc_attr_e('System', 'sepo-resources'); ?>"><?php echo esc_html((string)$r['os'] !== '' ? (string)$r['os'] : __('Unknown', 'sepo-resources')); ?></td>
                    <td data-label="<?php esc_attr_e('Device', 'sepo-resources'); ?>"><?php echo esc_html(Visitor::deviceLabel((string)$r['device'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function csv(): void
    {
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to export activity.', 'sepo-resources'), '', ['response' => 403]);
        }
        check_admin_referer('spr_activity_csv');
        $f = Activity::filters(wp_unslash($_GET));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="resource-activity-' . wp_date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 with a BOM
        fputcsv($out, ['Date', 'Document ID', 'Document', 'Action', 'Counted', 'User ID', 'Name', 'Country code', 'Country', 'Browser', 'Browser version', 'System', 'Device', 'Bot', 'User agent', 'IP address']);
        $titles = [];
        foreach (Activity::each($f) as $r) {
            $id = (int)$r['resource_id'];
            $titles[$id] ??= (string)get_the_title($id);
            fputcsv($out, array_map([self::class, 'cell'], [
                wp_date('Y-m-d H:i:s', (int)strtotime($r['created'] . ' UTC')),
                $id,
                $titles[$id],
                $r['kind'],
                (int)$r['counted'] ? 'yes' : 'no',
                (int)$r['user_id'] ?: '',
                $r['name'],
                $r['country'],
                (string)$r['country'] !== '' ? Countries::name((string)$r['country']) : '',
                $r['browser'],
                $r['browser_version'],
                $r['os'],
                $r['device'],
                (int)$r['is_bot'] ? 'yes' : 'no',
                $r['user_agent'],
                $r['ip'],
            ]));
        }
        fclose($out);
        exit;
    }

    /** Stops a spreadsheet treating a cell as a formula. */
    private static function cell(mixed $v): string
    {
        $s = (string)$v;
        return $s !== '' && strpbrk($s[0], '=+-@') !== false && !is_numeric($s) ? "'" . $s : $s;
    }
}
