<?php

declare(strict_types=1);

namespace SepoResources;

use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Front end: template loading, the archive query, assets, the shortcode,
 * and the two renderers (index and single) that replace Partial.php's
 * dispatcher and the ResourceIndex / ResourceDetail views.
 *
 * Themes can override any template by copying it from this plugin's
 * templates/ folder into their own sepo-resources/ folder.
 */
final class Frontend
{
    private static bool $needAssets = false;

    public static function init(): void
    {
        add_filter('template_include', [self::class, 'templateInclude'], 50);
        add_action('pre_get_posts', [self::class, 'archiveQuery']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
        add_shortcode('sepo_resources', [self::class, 'shortcode']);
    }

    // ------------------------------------------------------------ routing

    public static function isResourceContext(): bool
    {
        return is_singular(PostType::POST_TYPE)
            || is_post_type_archive(PostType::POST_TYPE)
            || is_tax([PostType::GROUP, PostType::TAG]);
    }

    public static function templateInclude(string $template): string
    {
        if (is_singular(PostType::POST_TYPE)) {
            return self::locate('single.php');
        }
        if (is_post_type_archive(PostType::POST_TYPE) || is_tax([PostType::GROUP, PostType::TAG])) {
            return self::locate('archive.php');
        }
        return $template;
    }

    public static function locate(string $name): string
    {
        $theme = locate_template(['sepo-resources/' . $name]);
        return $theme !== '' ? $theme : SPR_DIR . 'templates/' . $name;
    }

    /** The index does its own search and paging in the browser, so the archive loads every document. */
    public static function archiveQuery(WP_Query $q): void
    {
        if (is_admin() || !$q->is_main_query()) {
            return;
        }
        if ($q->is_post_type_archive(PostType::POST_TYPE) || $q->is_tax([PostType::GROUP, PostType::TAG])) {
            $q->set('posts_per_page', -1);
            $q->set('no_found_rows', true);
            $q->set('post_type', PostType::POST_TYPE);
        }
    }

    // ------------------------------------------------------------- layout

    /** get_header() for classic themes; the header template part for block themes. */
    public static function header(): void
    {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $header = do_blocks('<!-- wp:template-part {"slug":"header","tagName":"header"} /-->');
            ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        get_header();
    }

    public static function footer(): void
    {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            echo do_blocks('<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->'); // phpcs:ignore WordPress.Security.EscapeOutput
            echo "</div>\n";
            wp_footer();
            echo "\n</body>\n</html>\n";
            return;
        }
        get_footer();
    }

    // ------------------------------------------------------------- assets

    /**
     * The accent chosen in the settings, as custom properties. Left at the
     * default, nothing is printed, so a theme with its own design tokens
     * supplies the accent (and its dark-mode version) instead.
     */
    private static function accentCss(): string
    {
        $accent = (string) sanitize_hex_color((string) Settings::get('accent'));
        if ($accent === '' || strtolower($accent) === '#0b3d33') {
            return '';
        }
        $hex = ltrim($accent, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $lin = static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int) hexdec(substr($hex, 0, 2))) + 0.7152 * $lin((int) hexdec(substr($hex, 2, 2))) + 0.0722 * $lin((int) hexdec(substr($hex, 4, 2)));
        // Whichever of white or near-black text reads better on the accent.
        $on = (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0625) ? '#ffffff' : '#0b1512';
        // On a dark page the accent is lifted towards white so it stays legible
        // as text, with dark text on it when it is a button.
        return ':root{--spr-accent-custom:' . $accent . ';--spr-on-accent-custom:' . $on . ';'
            . '--spr-accent-custom-dark:color-mix(in srgb,' . $accent . ' 55%,#ffffff);--spr-on-accent-custom-dark:#0b1512;}';
    }

    public static function registerAssets(): void
    {
        wp_register_style('sepo-resources', SPR_URL . 'assets/css/resources.css', [], SPR_VERSION);
        wp_add_inline_style('sepo-resources', self::accentCss());

        wp_register_script('sepo-resources', SPR_URL . 'assets/js/resources.js', [], SPR_VERSION, true);
        wp_localize_script('sepo-resources', 'SepoResources', [
            'api'   => esc_url_raw(rest_url(Rest::NS)),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n'  => [
                'like'      => __('Like this document', 'sepo-resources'),
                'unlike'    => __('Remove your like', 'sepo-resources'),
                'copied'    => __('Copied', 'sepo-resources'),
                'showing'   => __('Showing %1$s–%2$s of %3$s documents', 'sepo-resources'),
                'one'       => __('1 document', 'sepo-resources'),
                'many'      => __('%s documents', 'sepo-resources'),
                'prev'      => __('Previous page', 'sepo-resources'),
                'next'      => __('Next page', 'sepo-resources'),
                'failed'    => __('That didn’t work. Try again.', 'sepo-resources'),
            ],
        ]);

        $post = get_post();
        if (self::isResourceContext() || ($post instanceof WP_Post && has_shortcode((string)$post->post_content, 'sepo_resources'))) {
            self::enqueue();
        }
    }

    public static function enqueue(): void
    {
        self::$needAssets = true;
        wp_enqueue_style('sepo-resources');
        wp_enqueue_script('sepo-resources');
    }

    // ---------------------------------------------------------- shortcode

    /**
     * [sepo_resources group="policy-papers,reports" tag="water" per_page="10"
     *                 search="yes" groups="yes" limit="-1" ids="12,15"]
     *
     * @param array<string,string>|string $atts
     */
    public static function shortcode($atts): string
    {
        $a = shortcode_atts([
            'group'    => '',
            'tag'      => '',
            'ids'      => '',
            'per_page' => '',
            'limit'    => '-1',
            'search'   => 'yes',
            'groups'   => 'yes',
        ], is_array($atts) ? $atts : [], 'sepo_resources');

        $args = [
            'post_type'      => PostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => (int)$a['limit'] ?: -1,
            'no_found_rows'  => true,
        ];
        $tax = [];
        if (trim($a['group']) !== '') {
            $tax[] = ['taxonomy' => PostType::GROUP, 'field' => 'slug', 'terms' => array_map('trim', explode(',', $a['group']))];
        }
        if (trim($a['tag']) !== '') {
            $tax[] = ['taxonomy' => PostType::TAG, 'field' => 'slug', 'terms' => array_map('trim', explode(',', $a['tag']))];
        }
        if ($tax) {
            $args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        if (trim($a['ids']) !== '') {
            $args['post__in'] = array_map('absint', explode(',', $a['ids']));
        }

        self::enqueue();
        return self::renderIndex(get_posts($args), [
            'per_page'    => $a['per_page'] !== '' ? (int)$a['per_page'] : null,
            'show_search' => $a['search'] !== 'no',
            'show_groups' => $a['groups'] !== 'no',
        ]);
    }

    // -------------------------------------------------------------- index

    /**
     * @param WP_Post[] $posts
     * @param array<string,mixed> $opts per_page, show_search, show_groups, ungrouped_label
     */
    public static function renderIndex(array $posts, array $opts = []): string
    {
        self::enqueue();

        $rows = [];
        foreach ($posts as $p) {
            if ($p instanceof WP_Post && $p->post_type === PostType::POST_TYPE) {
                $rows[] = Document::fromPost($p);
            }
        }

        // ---- groups (ordered by the group's "Display order", then name) ----
        $groupNames = [];
        $groupRank = [];
        foreach ($rows as $r) {
            if ($r['group'] !== '') {
                $groupNames[$r['group_slug']] = $r['group'];
                if (!isset($groupRank[$r['group_slug']])) {
                    $o = get_term_meta((int)$r['group_id'], 'spr_order', true);
                    $groupRank[$r['group_slug']] = $o === '' ? PHP_INT_MAX : (int)$o;
                }
            }
        }
        uksort($groupNames, static function (string $a, string $b) use ($groupRank, $groupNames): int {
            return ($groupRank[$a] <=> $groupRank[$b]) ?: strcasecmp($groupNames[$a], $groupNames[$b]);
        });
        $rank = array_flip(array_keys($groupNames));

        usort($rows, static function (array $a, array $b) use ($rank): int {
            $ga = $a['group'] !== '' ? ($rank[$a['group_slug']] ?? PHP_INT_MAX) : PHP_INT_MAX;
            $gb = $b['group'] !== '' ? ($rank[$b['group_slug']] ?? PHP_INT_MAX) : PHP_INT_MAX;
            return ($ga <=> $gb)
                ?: ($a['order'] <=> $b['order'])
                ?: ((strtotime($b['published'] ?: '@0') ?: 0) <=> (strtotime($a['published'] ?: '@0') ?: 0))
                ?: strcasecmp($a['title'], $b['title']);
        });

        $groupCounts = [];
        foreach ($rows as $r) {
            $k = $r['group'] !== '' ? $r['group_slug'] : '';
            $groupCounts[$k] = ($groupCounts[$k] ?? 0) + 1;
        }

        // ---- likes + downloads for every row in one query ----
        $uid = get_current_user_id();
        $counts = $rows ? EventStore::counts(array_column($rows, 'id')) : [];
        $mine = $uid > 0 ? array_flip(EventStore::likedIds($uid)) : [];

        $vars = [
            'rows'           => $rows,
            'groupNames'     => $groupNames,
            'groupCounts'    => $groupCounts,
            'hasGroups'      => ($opts['show_groups'] ?? true) && $groupNames !== [],
            'showSearch'     => (bool)($opts['show_search'] ?? true),
            'ungroupedLabel' => (string)($opts['ungrouped_label'] ?? Settings::get('ungrouped_label')),
            'perPage'        => max(1, (int)($opts['per_page'] ?? Settings::get('per_page'))),
            'counts'         => $counts,
            'mine'           => $mine,
            'loggedIn'       => $uid > 0,
        ];
        return self::part('index.php', $vars);
    }

    // ------------------------------------------------------------- single

    public static function renderSingle(WP_Post $post): string
    {
        self::enqueue();

        $doc = Document::fromPost($post);
        $uid = get_current_user_id();
        $loggedIn = $uid > 0;
        $stats = EventStore::summary($post->ID, $uid);

        // Preview: local PDFs only, through the streamer (never counted).
        $previewUrl = '';
        if ($doc['preview'] && $doc['has_file'] && $doc['ext'] === 'pdf' && !$doc['external']) {
            if (!$doc['gated'] || $loggedIn) {
                $previewUrl = Download::url($post->ID, true);
            }
        }

        // Citation line.
        $cite = '';
        if ($doc['author'] !== '' || $doc['published'] !== '') {
            $bits = [];
            if ($doc['author'] !== '') {
                $bits[] = $doc['author'] . '.';
            }
            $bits[] = '“' . $doc['title'] . '.”';
            if ($doc['author_org'] !== '') {
                $bits[] = $doc['author_org'] . ',';
            }
            if ($doc['published'] !== '') {
                $bits[] = Helpers::when($doc['published']) . '.';
            }
            if ($doc['version'] !== '') {
                /* translators: %s: version number */
                $bits[] = sprintf(__('Version %s.', 'sepo-resources'), $doc['version']);
            }
            if ($doc['reference'] !== '') {
                $bits[] = $doc['reference'];
            }
            $cite = implode(' ', $bits);
        }

        $vars = [
            'post'       => $post,
            'doc'        => $doc,
            'stats'      => $stats,
            'loggedIn'   => $loggedIn,
            'previewUrl' => $previewUrl,
            'cite'       => $cite,
            'related'    => self::related($post, $doc),
            'body'       => apply_filters('the_content', $post->post_content),
        ];
        return self::part('single.php', $vars);
    }

    /**
     * Up to four related documents: same group first, then newest.
     *
     * @param array<string,mixed> $doc
     * @return list<array<string,mixed>>
     */
    private static function related(WP_Post $post, array $doc): array
    {
        $out = [];
        $seen = [$post->ID];
        if ($doc['group_id'] > 0) {
            foreach (get_posts([
                'post_type'      => PostType::POST_TYPE,
                'posts_per_page' => 4,
                'post__not_in'   => $seen,
                'no_found_rows'  => true,
                'tax_query'      => [['taxonomy' => PostType::GROUP, 'terms' => [(int)$doc['group_id']]]], // phpcs:ignore WordPress.DB.SlowDBQuery
            ]) as $p) {
                $out[] = Document::fromPost($p);
                $seen[] = $p->ID;
            }
        }
        if (count($out) < 4) {
            foreach (get_posts([
                'post_type'      => PostType::POST_TYPE,
                'posts_per_page' => 4 - count($out),
                'post__not_in'   => $seen,
                'no_found_rows'  => true,
            ]) as $p) {
                $out[] = Document::fromPost($p);
            }
        }
        return $out;
    }

    // -------------------------------------------------------------- parts

    /** @param array<string,mixed> $vars */
    public static function part(string $name, array $vars): string
    {
        $file = self::locate('parts/' . $name);
        ob_start();
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract
            include $__file;
        })($file, $vars);
        return (string)ob_get_clean();
    }

    /** Archive heading for the current request. @return array{title:string,intro:string} */
    public static function archiveHeading(): array
    {
        $obj = get_queried_object();
        if ($obj instanceof WP_Term) {
            return ['title' => $obj->name, 'intro' => wpautop(wp_kses_post($obj->description))];
        }
        $intro = (string)Settings::get('archive_intro');
        return [
            'title' => (string)Settings::get('archive_title'),
            'intro' => $intro !== '' ? wpautop($intro) : '',
        ];
    }
}
