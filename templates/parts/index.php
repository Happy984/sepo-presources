<?php
/**
 * Sepo Resources — document listing.
 *
 * Override: copy to your-theme/sepo-resources/parts/index.php
 *
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string>           $groupNames   slug => name, in display order
 * @var array<string,int>              $groupCounts  slug => count ('' = ungrouped)
 * @var bool                           $hasGroups
 * @var bool                           $showSearch
 * @var string                         $ungroupedLabel
 * @var int                            $perPage
 * @var array<int,array{likes:int,downloads:int}> $counts
 * @var array<int,int>                 $mine         liked ids, flipped
 * @var bool                           $loggedIn
 */

use SepoResources\Helpers;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="spr-wrap spr-index" data-spr-index data-spr-per-page="<?php echo (int)$perPage; ?>">

    <?php if ($rows === []): ?>
        <div class="spr-empty"><?php esc_html_e('No documents published yet.', 'sepo-resources'); ?></div>
    <?php else: ?>

        <?php if ($showSearch): ?>
            <div class="spr-toolbar">
                <?php $sid = 'spr-search-' . wp_unique_id(); ?>
                <label class="screen-reader-text" for="<?php echo esc_attr($sid); ?>"><?php esc_html_e('Search documents', 'sepo-resources'); ?></label>
                <input type="search" id="<?php echo esc_attr($sid); ?>" class="spr-search" data-spr-search placeholder="<?php esc_attr_e('Search by title, author, keyword…', 'sepo-resources'); ?>" autocomplete="off">
            </div>
        <?php endif; ?>

        <?php if ($hasGroups): ?>
            <div class="spr-groups" data-spr-groups>
                <button type="button" class="spr-group-pill is-on" data-spr-group-filter=""><?php esc_html_e('All', 'sepo-resources'); ?> <span class="spr-group-n"><?php echo count($rows); ?></span></button>
                <?php foreach ($groupNames as $gk => $gname): ?>
                    <button type="button" class="spr-group-pill" data-spr-group-filter="<?php echo esc_attr($gk); ?>">
                        <?php echo esc_html($gname); ?> <span class="spr-group-n"><?php echo (int)($groupCounts[$gk] ?? 0); ?></span>
                    </button>
                <?php endforeach; ?>
                <?php if (($groupCounts[''] ?? 0) > 0): ?>
                    <button type="button" class="spr-group-pill" data-spr-group-filter="~none">
                        <?php echo esc_html($ungroupedLabel); ?> <span class="spr-group-n"><?php echo (int)$groupCounts['']; ?></span>
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="spr-count" data-spr-count aria-live="polite">
            <?php
            /* translators: %d: number of documents */
            echo esc_html(sprintf(_n('%d document', '%d documents', count($rows), 'sepo-resources'), count($rows)));
            ?>
        </div>

        <ul class="spr-list" data-spr-rows>
            <?php if ($hasGroups): ?>
                <?php
                $heads = $groupNames;
                if (($groupCounts[''] ?? 0) > 0) {
                    $heads['~none'] = $ungroupedLabel;
                }
                ?>
                <?php foreach ($heads as $hk => $hname): ?>
                    <li class="spr-group-head" data-spr-group-head="<?php echo esc_attr((string)$hk); ?>" hidden>
                        <?php echo esc_html($hname); ?>
                        <span class="spr-group-n"><?php echo (int)($groupCounts[$hk === '~none' ? '' : $hk] ?? 0); ?></span>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php foreach ($rows as $d): ?>
                <?php
                $c = $counts[$d['id']] ?? ['likes' => 0, 'downloads' => 0];
                $isMine = isset($mine[$d['id']]);
                $hay = mb_strtolower(implode(' ', [
                    $d['title'], $d['summary'], $d['author'], $d['author_org'],
                    $d['group'], $d['format'], $d['reference'], implode(' ', $d['tags']),
                ]));
                ?>
                <li class="spr-row" data-spr-row
                    data-spr-group="<?php echo esc_attr($d['group'] !== '' ? $d['group_slug'] : '~none'); ?>"
                    data-spr-search-text="<?php echo esc_attr($hay); ?>">

                    <a href="<?php echo esc_url($d['url']); ?>" aria-label="<?php echo esc_attr($d['title']); ?>" tabindex="-1"><?php echo Helpers::thumb($d); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>

                    <div class="spr-row-main">
                        <a class="spr-row-title" href="<?php echo esc_url($d['url']); ?>"><?php echo esc_html($d['title']); ?></a>
                        <div class="spr-row-meta"><?php echo esc_html(Helpers::stamp($d)); ?></div>
                        <?php if ($d['summary'] !== ''): ?>
                            <div class="spr-row-sum"><?php echo esc_html($d['summary']); ?></div>
                        <?php endif; ?>
                        <?php if ($d['tags'] !== [] || $d['group'] !== ''): ?>
                            <div class="spr-row-tags">
                                <?php if ($d['group'] !== ''): ?><span class="spr-tag spr-tag--group"><?php echo esc_html($d['group']); ?></span><?php endif; ?>
                                <?php foreach (array_slice($d['tags'], 0, 4) as $tag): ?>
                                    <span class="spr-tag"><?php echo esc_html($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="spr-row-side">
                        <?php echo Helpers::downloadLink($d, $loggedIn, (int)$c['downloads'], 'sm'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <div class="spr-row-stats">
                            <?php echo Helpers::likeButton($d, (int)$c['likes'], $isMine, $loggedIn, 'sm'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <?php echo Helpers::dlBadge($d, (int)$c['downloads']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="spr-empty" data-spr-noresults hidden><?php esc_html_e('No documents match that search.', 'sepo-resources'); ?></div>
        <nav class="spr-pager" data-spr-pager aria-label="<?php esc_attr_e('Document pages', 'sepo-resources'); ?>" hidden></nav>

    <?php endif; ?>
</div>
