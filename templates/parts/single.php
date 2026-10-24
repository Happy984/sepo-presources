<?php
/**
 * Sepo Resources — single document.
 *
 * Override: copy to your-theme/sepo-resources/parts/single.php
 *
 * @var WP_Post                          $post
 * @var array<string,mixed>              $doc
 * @var array{likes:int,downloads:int,unique:int,liked:bool,yours:int} $stats
 * @var bool                             $loggedIn
 * @var string                           $previewUrl
 * @var string                           $cite
 * @var list<array<string,mixed>>        $related
 * @var string                           $body
 */

use SepoResources\Helpers;

if (!defined('ABSPATH')) {
    exit;
}
$citeId = 'spr-cite-' . (int)$doc['id'];
?>
<div class="spr-wrap spr-single">
    <div class="spr-layout">
        <article class="spr-main">

            <div class="spr-head">
                <?php echo Helpers::thumb($doc, 'lg'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <div class="spr-head-text">
                    <?php if ($doc['group'] !== ''): ?>
                        <a class="spr-kicker" href="<?php echo esc_url((string)get_term_link((int)$doc['group_id'])); ?>"><?php echo esc_html($doc['group']); ?></a>
                    <?php endif; ?>
                    <h1 class="spr-title"><?php echo esc_html($doc['title']); ?></h1>
                    <div class="spr-row-meta"><?php echo esc_html(Helpers::stamp($doc)); ?></div>
                    <?php if ($doc['summary'] !== ''): ?>
                        <p class="spr-abstract"><?php echo esc_html($doc['summary']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($doc['author'] !== ''): ?>
                <div class="spr-author">
                    <span class="spr-avatar"<?php echo $doc['author_image'] !== '' ? ' style="background-image:url(\'' . esc_url($doc['author_image']) . '\')"' : ''; ?>></span>
                    <div>
                        <div class="spr-author-name">
                            <?php if ($doc['author_url'] !== ''): ?>
                                <a href="<?php echo esc_url($doc['author_url']); ?>"><?php echo esc_html($doc['author']); ?></a>
                            <?php else: ?>
                                <?php echo esc_html($doc['author']); ?>
                            <?php endif; ?>
                        </div>
                        <?php $sub = trim(implode(' · ', array_filter([$doc['author_role'], $doc['author_org']]))); ?>
                        <?php if ($sub !== ''): ?>
                            <div class="spr-author-sub"><?php echo esc_html($sub); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (trim(wp_strip_all_tags($body)) !== ''): ?>
                <div class="spr-body"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content output ?></div>
            <?php endif; ?>

            <?php if ($previewUrl !== ''): ?>
                <h2 class="spr-section-h"><?php esc_html_e('Preview', 'sepo-resources'); ?></h2>
                <iframe class="spr-preview" src="<?php echo esc_url($previewUrl); ?>#view=FitH" title="<?php echo esc_attr(sprintf(/* translators: %s: document title */ __('%s preview', 'sepo-resources'), $doc['title'])); ?>" loading="lazy"></iframe>
            <?php endif; ?>

            <h2 class="spr-section-h"><?php esc_html_e('Document details', 'sepo-resources'); ?></h2>
            <dl class="spr-facts">
                <dt><?php esc_html_e('Format', 'sepo-resources'); ?></dt><dd><?php echo esc_html($doc['format']); ?><?php echo $doc['size'] !== '' ? ' · ' . esc_html($doc['size']) : ''; ?></dd>
                <?php if ($doc['pages'] !== ''): ?><dt><?php esc_html_e('Pages', 'sepo-resources'); ?></dt><dd><?php echo esc_html($doc['pages']); ?></dd><?php endif; ?>
                <?php if ($doc['version'] !== ''): ?><dt><?php esc_html_e('Version', 'sepo-resources'); ?></dt><dd><?php echo esc_html($doc['version']); ?></dd><?php endif; ?>
                <?php if ($doc['published'] !== ''): ?><dt><?php esc_html_e('Published', 'sepo-resources'); ?></dt><dd><?php echo esc_html(Helpers::when($doc['published'])); ?></dd><?php endif; ?>
                <?php if ($doc['updated'] !== ''): ?><dt><?php esc_html_e('Last updated', 'sepo-resources'); ?></dt><dd><?php echo esc_html(Helpers::when($doc['updated'])); ?></dd><?php endif; ?>
                <?php if ($doc['language'] !== ''): ?><dt><?php esc_html_e('Language', 'sepo-resources'); ?></dt><dd><?php echo esc_html($doc['language']); ?></dd><?php endif; ?>
                <?php if ($doc['licence'] !== ''): ?><dt><?php esc_html_e('Licence', 'sepo-resources'); ?></dt><dd><?php echo esc_html($doc['licence']); ?></dd><?php endif; ?>
                <?php if ($doc['reference'] !== ''): ?><dt><?php esc_html_e('Reference', 'sepo-resources'); ?></dt><dd><?php echo esc_html($doc['reference']); ?></dd><?php endif; ?>
                <?php if ($doc['tags'] !== []): ?>
                    <dt><?php esc_html_e('Keywords', 'sepo-resources'); ?></dt>
                    <dd><?php foreach ($doc['tags'] as $tag): ?><span class="spr-tag"><?php echo esc_html($tag); ?></span> <?php endforeach; ?></dd>
                <?php endif; ?>
            </dl>

            <?php if ($cite !== ''): ?>
                <h2 class="spr-section-h"><?php esc_html_e('Cite this document', 'sepo-resources'); ?></h2>
                <div class="spr-cite">
                    <button type="button" class="spr-copy" data-spr-copy="<?php echo esc_attr($citeId); ?>"><?php esc_html_e('Copy', 'sepo-resources'); ?></button>
                    <span id="<?php echo esc_attr($citeId); ?>"><?php echo esc_html($cite); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($related !== []): ?>
                <section class="spr-related">
                    <h2 class="spr-section-h"><?php esc_html_e('Related documents', 'sepo-resources'); ?></h2>
                    <ul class="spr-list">
                        <?php foreach ($related as $r): ?>
                            <li class="spr-row">
                                <a href="<?php echo esc_url($r['url']); ?>" tabindex="-1" aria-hidden="true"><?php echo Helpers::thumb($r); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
                                <div class="spr-row-main">
                                    <a class="spr-row-title" href="<?php echo esc_url($r['url']); ?>"><?php echo esc_html($r['title']); ?></a>
                                    <div class="spr-row-meta"><?php echo esc_html(Helpers::stamp($r)); ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

        </article>

        <aside class="spr-side">
            <div class="spr-panel">
                <?php echo Helpers::downloadLink($doc, $loggedIn, (int)$stats['downloads']); // phpcs:ignore WordPress.Security.EscapeOutput ?>

                <div class="spr-panel-actions">
                    <?php echo Helpers::likeButton($doc, (int)$stats['likes'], (bool)$stats['liked'], $loggedIn); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php echo Helpers::dlBadge($doc, (int)$stats['downloads']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>

                <ul class="spr-panel-stats">
                    <li><span class="k"><?php esc_html_e('Downloads', 'sepo-resources'); ?></span><span data-spr-dl-n="<?php echo (int)$doc['id']; ?>"><span class="spr-dl-v"><?php echo (int)$stats['downloads']; ?></span></span></li>
                    <li><span class="k"><?php esc_html_e('Likes', 'sepo-resources'); ?></span><span data-spr-like-total="<?php echo (int)$doc['id']; ?>"><?php echo (int)$stats['likes']; ?></span></li>
                    <?php if ($doc['size'] !== ''): ?><li><span class="k"><?php esc_html_e('Size', 'sepo-resources'); ?></span><span><?php echo esc_html($doc['size']); ?></span></li><?php endif; ?>
                    <?php if ($doc['pages'] !== ''): ?><li><span class="k"><?php esc_html_e('Pages', 'sepo-resources'); ?></span><span><?php echo esc_html($doc['pages']); ?></span></li><?php endif; ?>
                    <?php if ($doc['version'] !== ''): ?><li><span class="k"><?php esc_html_e('Version', 'sepo-resources'); ?></span><span><?php echo esc_html($doc['version']); ?></span></li><?php endif; ?>
                    <?php if ($doc['updated'] !== ''): ?><li><span class="k"><?php esc_html_e('Updated', 'sepo-resources'); ?></span><span><?php echo esc_html(Helpers::when($doc['updated'])); ?></span></li><?php endif; ?>
                </ul>

                <?php if ($doc['gated'] && !$loggedIn): ?>
                    <p class="spr-note"><?php esc_html_e('This document is available to signed-in members.', 'sepo-resources'); ?></p>
                <?php elseif ($doc['licence'] !== ''): ?>
                    <p class="spr-note"><?php echo esc_html($doc['licence']); ?></p>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
