<?php
/**
 * header.php
 * Includes /manage/'s REAL nav (templates/nav-data.php + templates/nav.php)
 * directly from the filesystem -- both apps share one physical file now,
 * not a separate lookalike copy that could drift. Confirmed no function/
 * constant naming collisions between manage's included files (mfl-api.php,
 * mfl-auth.php, helmets.php) and anything in this theme before wiring
 * this up, and confirmed manage's own header.php byte-for-byte identical
 * before/after the nav-data.php/nav.php split (see that commit).
 *
 * dirname(ABSPATH) is the true document root as of the manage-owns-the-
 * homepage migration: ABSPATH is WordPress's own directory, now /wp/
 * (relocated out of the root), so its parent is where /manage/ actually
 * lives -- correct regardless of how deep this theme file itself sits.
 *
 * mfl26.css (manage's stylesheet, enqueued in functions.php) is what
 * actually styles the borrowed nav -- this theme's own style.css no
 * longer defines .rotc-nav/.rotc-brand/etc. at all, to avoid two
 * different rule sets fighting over the same class names.
 */
if (!defined('ABSPATH')) exit;
$rotc_manage_root = dirname(ABSPATH) . '/manage';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php if (file_exists($rotc_manage_root . '/templates/nav.php')): ?>
  <?php
  require_once $rotc_manage_root . '/templates/nav-data.php';
  include $rotc_manage_root . '/templates/nav.php';
  ?>
<?php else: ?>
  <!-- /manage/ unreachable from this filesystem location -- bare-minimum
       fallback so the page still has SOME way home rather than a fatal. -->
  <header class="rotc-nav-fallback" style="background:#2A1810;padding:14px 16px;">
    <a href="<?php echo esc_url(home_url('/')); ?>" style="color:#FDFBF7;font-family:Georgia,serif;font-size:18px;">Return of the Champions</a>
  </header>
<?php endif; ?>

<main class="rotc-wrap">
