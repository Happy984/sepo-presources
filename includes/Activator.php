<?php

declare(strict_types=1);

namespace SepoResources;

final class Activator
{
    public const DB_VERSION = '2';
    public const CAP = 'manage_sepo_resources';

    public static function activate(): void
    {
        self::install();
        PostType::register();
        Download::addRewrite();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        Activity::unschedule();
        flush_rewrite_rules();
    }

    /** Safe to call on every load; only does work when the schema version moves. */
    public static function maybeUpgrade(): void
    {
        if (get_option('sepo_resources_db') !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        EventStore::createTable();
        Activity::createTable();

        foreach (['administrator', 'editor'] as $roleName) {
            $role = get_role($roleName);
            if ($role && !$role->has_cap(self::CAP)) {
                $role->add_cap(self::CAP);
            }
        }

        Uploads::ensureProtectedDir();

        if (get_option(Settings::OPTION) === false) {
            add_option(Settings::OPTION, Settings::defaults());
        }

        update_option('sepo_resources_db', self::DB_VERSION);
    }
}
