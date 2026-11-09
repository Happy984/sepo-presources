<?php
/**
 * Sepo Resources — library page (/resources/ and group/keyword archives).
 *
 * Override: copy to your-theme/sepo-resources/archive.php
 */

use SepoResources\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

global $wp_query;
$sepo_resources_heading = Frontend::archiveHeading();

Frontend::header();
?>
<main id="primary" class="spr-screen spr-screen--archive">
    <header class="spr-screen-head">
        <h1 class="spr-screen-title"><?php echo esc_html($sepo_resources_heading['title']); ?></h1>
        <?php if ($sepo_resources_heading['intro'] !== ''): ?>
            <div class="spr-screen-intro"><?php echo wp_kses_post($sepo_resources_heading['intro']); ?></div>
        <?php endif; ?>
    </header>
    <?php echo Frontend::renderIndex((array)$wp_query->posts); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</main>
<?php
Frontend::footer();
