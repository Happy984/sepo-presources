<?php
/**
 * Sepo Resources — single document page.
 *
 * Override: copy to your-theme/sepo-resources/single.php
 */

use SepoResources\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

Frontend::header();
?>
<main id="primary" class="spr-screen spr-screen--single">
    <?php
    while (have_posts()) {
        the_post();
        if (post_password_required()) {
            echo '<div class="spr-wrap">' . get_the_password_form() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
            continue;
        }
        echo Frontend::renderSingle(get_post()); // phpcs:ignore WordPress.Security.EscapeOutput
    }
    ?>
</main>
<?php
Frontend::footer();
