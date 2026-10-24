<?php

declare(strict_types=1);

namespace SepoResources\Admin;

use SepoResources\Countries;
use SepoResources\Download;
use SepoResources\PostType;
use SepoResources\Settings;
use SepoResources\Uploads;
use SepoResources\Visitor;

/**
 * Resources → Settings.
 */
final class SettingsPage
{
    public const SLUG = 'sepo-resources-settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_action('update_option_' . Settings::OPTION, [self::class, 'changed'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(SPR_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'sepo-resources') . '</a>');
            return $links;
        });
    }

    public static function url(): string
    {
        return admin_url('edit.php?post_type=' . PostType::POST_TYPE . '&page=' . self::SLUG);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . PostType::POST_TYPE,
            __('Resources settings', 'sepo-resources'),
            __('Settings', 'sepo-resources'),
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function register(): void
    {
        register_setting('sepo_resources', Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => static fn($in): array => Settings::sanitize(is_array($in) ? $in : []),
        ]);
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public static function changed($old, $new): void
    {
        Settings::flush();
        if (!is_array($old) || !is_array($new) || ($old['base'] ?? '') !== ($new['base'] ?? '')) {
            update_option('sepo_resources_flush', 1);
        }
        if (!empty($new['protect_uploads'])) {
            Uploads::ensureProtectedDir();
        }
    }

    private static function field(string $key): string
    {
        return Settings::OPTION . '[' . $key . ']';
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Settings::all();
        $base = Settings::base();
        ?>
        <div class="wrap spr-settings">
            <h1><?php esc_html_e('Resources settings', 'sepo-resources'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('sepo_resources'); ?>

                <h2><?php esc_html_e('Library page', 'sepo-resources'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="spr-base"><?php esc_html_e('Address', 'sepo-resources'); ?></label></th>
                        <td>
                            <code><?php echo esc_html(home_url('/')); ?></code><input id="spr-base" type="text" name="<?php echo esc_attr(self::field('base')); ?>" value="<?php echo esc_attr($base); ?>" class="regular-text" style="width:14em">
                            <p class="description"><?php esc_html_e('The library lives here and each document lives under it. Downloads use …/download/{id}/.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-title"><?php esc_html_e('Heading', 'sepo-resources'); ?></label></th>
                        <td><input id="spr-title" type="text" name="<?php echo esc_attr(self::field('archive_title')); ?>" value="<?php echo esc_attr((string)$s['archive_title']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-intro"><?php esc_html_e('Introduction', 'sepo-resources'); ?></label></th>
                        <td><textarea id="spr-intro" name="<?php echo esc_attr(self::field('archive_intro')); ?>" rows="3" class="large-text"><?php echo esc_textarea((string)$s['archive_intro']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-pp"><?php esc_html_e('Documents per page', 'sepo-resources'); ?></label></th>
                        <td><input id="spr-pp" type="number" min="1" max="200" name="<?php echo esc_attr(self::field('per_page')); ?>" value="<?php echo (int)$s['per_page']; ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-ung"><?php esc_html_e('Label for ungrouped documents', 'sepo-resources'); ?></label></th>
                        <td><input id="spr-ung" type="text" name="<?php echo esc_attr(self::field('ungrouped_label')); ?>" value="<?php echo esc_attr((string)$s['ungrouped_label']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-accent"><?php esc_html_e('Accent colour', 'sepo-resources'); ?></label></th>
                        <td><input id="spr-accent" type="color" name="<?php echo esc_attr(self::field('accent')); ?>" value="<?php echo esc_attr((string)$s['accent']); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Downloads', 'sepo-resources'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('New documents', 'sepo-resources'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::field('default_allow_download')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('default_allow_download')); ?>" value="1" <?php checked((int)$s['default_allow_download'], 1); ?>> <?php esc_html_e('Allow download', 'sepo-resources'); ?></label><br>
                            <input type="hidden" name="<?php echo esc_attr(self::field('default_requires_login')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('default_requires_login')); ?>" value="1" <?php checked((int)$s['default_requires_login'], 1); ?>> <?php esc_html_e('Sign-in required to download', 'sepo-resources'); ?></label>
                            <p class="description"><?php esc_html_e('Each document can override these in its Download rules box.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-window"><?php esc_html_e('Count repeat downloads after', 'sepo-resources'); ?></label></th>
                        <td>
                            <input id="spr-window" type="number" min="0" max="86400" name="<?php echo esc_attr(self::field('count_window')); ?>" value="<?php echo (int)$s['count_window']; ?>" class="small-text"> <?php esc_html_e('seconds', 'sepo-resources'); ?>
                            <p class="description"><?php esc_html_e('Stops double-clicks and download managers from inflating the count. 0 counts every request.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('File protection', 'sepo-resources'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::field('protect_uploads')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('protect_uploads')); ?>" value="1" <?php checked((int)$s['protect_uploads'], 1); ?>> <?php esc_html_e('Store uploaded documents in a protected folder', 'sepo-resources'); ?></label>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: folder path */
                                    esc_html__('Files go to %s, which blocks direct access on Apache and LiteSpeed. On nginx, add this rule to your server block:', 'sepo-resources'),
                                    '<code>' . esc_html(str_replace(ABSPATH, '', Uploads::protectedDir())) . '</code>'
                                );
                                ?>
                            </p>
                            <p><code><?php echo esc_html(Uploads::nginxSnippet()); ?></code></p>
                        </td>
                    </tr>
                </table>

                <h2 id="spr-visitors"><?php esc_html_e('Visitor details', 'sepo-resources'); ?></h2>
                <p><?php
                printf(
                    /* translators: %s: link to the Activity page */
                    esc_html__('Each like, unlike and download is logged with the country, browser, system and device. See them in %s and on each document’s edit screen.', 'sepo-resources'),
                    '<a href="' . esc_url(ActivityPage::url()) . '">' . esc_html__('Resources → Activity', 'sepo-resources') . '</a>'
                ); // phpcs:ignore WordPress.Security.EscapeOutput
                ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Activity log', 'sepo-resources'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::field('log_activity')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('log_activity')); ?>" value="1" <?php checked((int)$s['log_activity'], 1); ?>> <?php esc_html_e('Record country, browser and device for likes and downloads', 'sepo-resources'); ?></label><br>
                            <input type="hidden" name="<?php echo esc_attr(self::field('keep_agent')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('keep_agent')); ?>" value="1" <?php checked((int)$s['keep_agent'], 1); ?>> <?php esc_html_e('Keep the full user-agent text as well', 'sepo-resources'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Country from your host', 'sepo-resources'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::field('geo_header')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('geo_header')); ?>" value="1" <?php checked((int)$s['geo_header'], 1); ?>> <?php esc_html_e('Use the country sent by the CDN or host', 'sepo-resources'); ?></label>
                            <p class="description"><?php esc_html_e('Cloudflare (CF-IPCountry), Amazon CloudFront, Vercel, Google App Engine and servers with a GeoIP module send it with every request. Free, instant, and no IP address is stored.', 'sepo-resources'); ?></p>
                            <p class="description"><?php
                            $found = Visitor::headerCountry();
                            echo $found !== ''
                                ? esc_html(sprintf(/* translators: %s: country */ __('Working: your host says you are in %s.', 'sepo-resources'), Countries::name($found)))
                                : esc_html__('Your host is not sending a country right now. Use a lookup service below, or turn on Cloudflare’s “IP Geolocation”.', 'sepo-resources');
                            ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-geo"><?php esc_html_e('Country lookup service', 'sepo-resources'); ?></label></th>
                        <td>
                            <select id="spr-geo" name="<?php echo esc_attr(self::field('geo_provider')); ?>">
                                <option value="none" <?php selected($s['geo_provider'], 'none'); ?>><?php esc_html_e('None', 'sepo-resources'); ?></option>
                                <option value="countryis" <?php selected($s['geo_provider'], 'countryis'); ?>>country.is — <?php esc_html_e('free, no key', 'sepo-resources'); ?></option>
                                <option value="ipapi" <?php selected($s['geo_provider'], 'ipapi'); ?>>ipapi.co — <?php esc_html_e('free up to 1,000 a day', 'sepo-resources'); ?></option>
                                <option value="ipinfo" <?php selected($s['geo_provider'], 'ipinfo'); ?>>ipinfo.io — <?php esc_html_e('free with a token', 'sepo-resources'); ?></option>
                            </select>
                            <p><label><?php esc_html_e('Token (ipinfo.io)', 'sepo-resources'); ?> <input type="text" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::field('geo_token')); ?>" value="<?php echo esc_attr((string)$s['geo_token']); ?>"></label></p>
                            <p class="description"><?php esc_html_e('Used only when the host sends no country. The visitor’s IP address is sent to the service in the background a minute later, never while they wait, and each address is asked once a month at most.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-ipsrc"><?php esc_html_e('Visitor IP comes from', 'sepo-resources'); ?></label></th>
                        <td>
                            <select id="spr-ipsrc" name="<?php echo esc_attr(self::field('ip_source')); ?>">
                                <option value="REMOTE_ADDR" <?php selected($s['ip_source'], 'REMOTE_ADDR'); ?>><?php esc_html_e('The connection (REMOTE_ADDR) — recommended', 'sepo-resources'); ?></option>
                                <option value="HTTP_CF_CONNECTING_IP" <?php selected($s['ip_source'], 'HTTP_CF_CONNECTING_IP'); ?>>CF-Connecting-IP (Cloudflare)</option>
                                <option value="HTTP_X_FORWARDED_FOR" <?php selected($s['ip_source'], 'HTTP_X_FORWARDED_FOR'); ?>>X-Forwarded-For</option>
                                <option value="HTTP_X_REAL_IP" <?php selected($s['ip_source'], 'HTTP_X_REAL_IP'); ?>>X-Real-IP</option>
                                <option value="HTTP_TRUE_CLIENT_IP" <?php selected($s['ip_source'], 'HTTP_TRUE_CLIENT_IP'); ?>>True-Client-IP</option>
                            </select>
                            <p class="description"><?php
                            printf(
                                /* translators: %s: IP address */
                                esc_html__('Pick a header only if your site really sits behind that proxy; visitors can fake it otherwise. Your address as seen now: %s', 'sepo-resources'),
                                '<code>' . esc_html(Visitor::ip() ?: '—') . '</code>'
                            );
                            ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('IP addresses', 'sepo-resources'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::field('keep_ip')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('keep_ip')); ?>" value="1" <?php checked((int)$s['keep_ip'], 1); ?>> <?php esc_html_e('Keep IP addresses in the log', 'sepo-resources'); ?></label>
                            <p class="description"><?php esc_html_e('Off: an address is kept only until its country is found, then deleted. IP addresses are personal data in many countries.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bots and crawlers', 'sepo-resources'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::field('count_bots')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('count_bots')); ?>" value="1" <?php checked((int)$s['count_bots'], 1); ?>> <?php esc_html_e('Count downloads by bots in the totals', 'sepo-resources'); ?></label>
                            <p class="description"><?php esc_html_e('Off: Googlebot, link previews, scripts and other crawlers are logged but left out of download counts.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="spr-ret"><?php esc_html_e('Keep the log for', 'sepo-resources'); ?></label></th>
                        <td>
                            <input id="spr-ret" type="number" min="0" max="3650" class="small-text" name="<?php echo esc_attr(self::field('log_retention')); ?>" value="<?php echo (int)$s['log_retention']; ?>"> <?php esc_html_e('days', 'sepo-resources'); ?>
                            <p class="description"><?php esc_html_e('Older events are deleted daily. 0 keeps them forever. Totals on the library are not affected.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Uninstall', 'sepo-resources'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('When the plugin is deleted', 'sepo-resources'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(self::field('delete_on_uninstall')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::field('delete_on_uninstall')); ?>" value="1" <?php checked((int)$s['delete_on_uninstall'], 1); ?>> <?php esc_html_e('Delete likes, download counts, the activity log and settings', 'sepo-resources'); ?></label>
                            <p class="description"><?php esc_html_e('Documents and their files are never deleted.', 'sepo-resources'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Showing documents elsewhere', 'sepo-resources'); ?></h2>
            <p><?php esc_html_e('Put a listing on any page or post with the shortcode:', 'sepo-resources'); ?></p>
            <p><code>[sepo_resources]</code> &nbsp; <code>[sepo_resources group="annual-reports" per_page="5" search="no"]</code></p>
            <p class="description"><?php esc_html_e('Attributes: group, tag (slugs, comma-separated), ids, per_page, limit, search="no", groups="no".', 'sepo-resources'); ?></p>
            <p>
                <?php esc_html_e('Library page:', 'sepo-resources'); ?>
                <a href="<?php echo esc_url((string)get_post_type_archive_link(PostType::POST_TYPE)); ?>"><?php echo esc_html((string)get_post_type_archive_link(PostType::POST_TYPE)); ?></a>
                <br><?php esc_html_e('Example download link:', 'sepo-resources'); ?> <code><?php echo esc_html(Download::url(123)); ?></code>
            </p>
        </div>
        <?php
    }
}
