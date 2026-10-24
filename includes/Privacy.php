<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * Hooks into Tools → Export / Erase Personal Data, and suggests text for
 * the site's privacy policy.
 */
final class Privacy
{
    private const PER_PAGE = 200;

    public static function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
        add_action('admin_init', [self::class, 'policy']);
    }

    /** @param array<string,mixed> $list */
    public static function exporters(array $list): array
    {
        $list['sepo-resources'] = [
            'exporter_friendly_name' => __('Sepo Resources activity', 'sepo-resources'),
            'callback'               => [self::class, 'export'],
        ];
        return $list;
    }

    /** @param array<string,mixed> $list */
    public static function erasers(array $list): array
    {
        $list['sepo-resources'] = [
            'eraser_friendly_name' => __('Sepo Resources activity', 'sepo-resources'),
            'callback'             => [self::class, 'erase'],
        ];
        return $list;
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $rows = Activity::forUser((int)$user->ID, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'group_id'    => 'sepo-resources-activity',
                'group_label' => __('Document likes and downloads', 'sepo-resources'),
                'item_id'     => 'spr-activity-' . $r['id'],
                'data'        => [
                    ['name' => __('Document', 'sepo-resources'), 'value' => get_the_title((int)$r['resource_id']) ?: '#' . $r['resource_id']],
                    ['name' => __('Action', 'sepo-resources'), 'value' => Activity::kindLabel((string)$r['kind'])],
                    ['name' => __('Date (UTC)', 'sepo-resources'), 'value' => (string)$r['created']],
                    ['name' => __('Country', 'sepo-resources'), 'value' => Countries::name((string)$r['country'])],
                    ['name' => __('Browser', 'sepo-resources'), 'value' => Activity::browserLabel($r)],
                    ['name' => __('System', 'sepo-resources'), 'value' => (string)$r['os']],
                    ['name' => __('Device', 'sepo-resources'), 'value' => Visitor::deviceLabel((string)$r['device'])],
                    ['name' => __('User agent', 'sepo-resources'), 'value' => (string)$r['user_agent']],
                    ['name' => __('IP address', 'sepo-resources'), 'value' => (string)$r['ip']],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < self::PER_PAGE];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $n = $user ? Activity::eraseUser((int)$user->ID) : 0;
        return ['items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    public static function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $text = '<p>' . esc_html__('When you download or like a document in our library, we record which document, when, the country you were in, and the browser, operating system and type of device you used (read from your browser’s user-agent string). If you are signed in, we also record your account.', 'sepo-resources') . '</p>'
            . '<p>' . esc_html__('To find the country, we may use the country our hosting or network provider sends with your request, or look up your IP address with an IP location service. Your IP address is deleted once the country is known, unless the site keeps it for security.', 'sepo-resources') . '</p>'
            . '<p>' . esc_html__('These records are deleted automatically after the retention period set by the site owner.', 'sepo-resources') . '</p>';
        wp_add_privacy_policy_content(__('Sepo Resources', 'sepo-resources'), wp_kses_post($text));
    }
}
