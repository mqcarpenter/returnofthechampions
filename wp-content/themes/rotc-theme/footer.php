<?php
/** footer.php — closes the <main> opened in header.php. */
if (!defined('ABSPATH')) exit;
?>
</main>

<footer class="rotc-footer">
  <div class="rotc-wrap">
    <div class="rotc-footer-cols">
      <div>
        <h3 class="rotc-footer-heading"><?php esc_html_e('League', 'rotc-theme'); ?></h3>
        <p><a href="<?php echo esc_url(trailingslashit(home_url()) . 'manage/'); ?>"><?php esc_html_e('Manage', 'rotc-theme'); ?></a></p>
        <p><a href="<?php echo esc_url(trailingslashit(home_url()) . 'manage/scores/standings'); ?>"><?php esc_html_e('Standings', 'rotc-theme'); ?></a></p>
        <p><a href="<?php echo esc_url(home_url('/history/')); ?>"><?php esc_html_e('History', 'rotc-theme'); ?></a></p>
      </div>
      <div>
        <h3 class="rotc-footer-heading"><?php esc_html_e('Community', 'rotc-theme'); ?></h3>
        <p><a href="<?php echo esc_url(rotc_theme_page_url('community')); ?>"><?php esc_html_e('Smack Board', 'rotc-theme'); ?></a></p>
        <p><a href="<?php echo esc_url(rotc_theme_page_url('faq')); ?>"><?php esc_html_e('FAQ', 'rotc-theme'); ?></a></p>
      </div>
      <?php
      // Same accounts + icons as the homepage footer: one list in manage/includes/socials.php.
      $rotcSocials = dirname(ABSPATH) . '/manage/includes/socials.php';
      if (file_exists($rotcSocials)): require_once $rotcSocials; $rotcSocialHtml = rotc_social_icons_html();
        if ($rotcSocialHtml !== ''): ?>
        <div>
          <h3 class="rotc-footer-heading"><?php esc_html_e('Follow', 'rotc-theme'); ?></h3>
          <?php echo $rotcSocialHtml; // built from fixed URLs, already escaped ?>
        </div>
      <?php endif; endif; ?>
      <?php if (has_nav_menu('footer')): ?>
        <div>
          <h3 class="rotc-footer-heading"><?php esc_html_e('More', 'rotc-theme'); ?></h3>
          <?php wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'menu_class' => '', 'depth' => 1]); ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="rotc-footer-bottom">
      &copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?>
    </div>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
