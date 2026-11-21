<?php
/**
 * functions.php
 * Theme setup for ROTC Theme. Kept deliberately small: this is a
 * from-scratch news-site theme (not a child of seos-football), so
 * there's no parent functions.php to lean on -- everything the theme
 * needs is registered here or in inc/.
 */

if (!defined('ABSPATH')) exit;

define('ROTC_THEME_VERSION', '0.2.3');

function rotc_theme_setup(): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('automatic-feed-links');
    add_theme_support('align-wide');

    // No 'primary' menu location anymore -- header.php includes
    // /manage/'s own real nav directly (see that file's doc comment),
    // not a WordPress-managed menu. 'footer' stays; footer.php still
    // offers it as an optional extra column.
    register_nav_menus([
        'footer' => __('Footer Menu', 'rotc-theme'),
    ]);
}
add_action('after_setup_theme', 'rotc_theme_setup');

function rotc_theme_assets(): void {
    wp_enqueue_style('rotc-theme-style', get_stylesheet_uri(), [], ROTC_THEME_VERSION);
    wp_enqueue_style('rotc-theme-fonts', 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Condensed:wght@600;700&display=swap', [], null);
    // mfl26.css is what actually styles the shared nav header.php now
    // includes from /manage/ -- this theme's own style.css deliberately
    // no longer defines .rotc-nav/.rotc-brand/etc. at all, to avoid two
    // rule sets fighting over the same class names. Cache-busted the
    // same way manage's own header.php does (filemtime, not a static
    // version string), since this file changes independently of theme
    // releases.
    $manageRoot = dirname(ABSPATH) . '/manage';
    $mfl26Path = $manageRoot . '/assets/mfl26.css';
    if (file_exists($mfl26Path)) {
        $mfl26Ver = @filemtime($mfl26Path) ?: ROTC_THEME_VERSION;
        wp_enqueue_style('rotc-manage-nav', trailingslashit(home_url()) . 'manage/assets/mfl26.css', [], $mfl26Ver);
    }
}

/**
 * This theme doesn't offer commenting (per Matteo's call -- the site
 * already has wpforo for discussion, a second comment surface on
 * every post/page was redundant). Force it closed at the theme level
 * rather than relying on every individual post's setting being right,
 * and hide any pre-existing counts so nothing invites a form that
 * isn't there.
 */
add_filter('comments_open', '__return_false');
add_filter('pings_open', '__return_false');
add_filter('comments_array', '__return_empty_array');

/**
 * Same reasoning, different surface: a "Latest Comments" block widget
 * was left over in a footer widget area from before this theme was
 * active (confirmed live -- it was rendering real comment data via
 * dynamic_sidebar('rotc-footer') on the front page). Removing it in
 * Appearance > Widgets would work too, but suppressing the block
 * itself here means it can never resurface site-wide (post content,
 * any widget area, a future page) no matter how it gets re-added --
 * consistent with "no comments anywhere" rather than "no comments in
 * the one spot someone happened to notice."
 */
add_filter('render_block_core/latest-comments', '__return_empty_string');
// The classic (non-block) Recent Comments widget, same reasoning.
function rotc_theme_unregister_widgets(): void {
    unregister_widget('WP_Widget_Recent_Comments');
}
add_action('widgets_init', 'rotc_theme_unregister_widgets', 11);

add_action('wp_enqueue_scripts', 'rotc_theme_assets');

/**
 * Social share buttons for single posts. Plain share-intent links, inline
 * SVG, no third-party widgets or tracking. Rendered by single.php.
 */
function rotc_theme_share_buttons(): void {
    if (!is_singular('post')) return;
    $url   = get_permalink();
    $title = wp_strip_all_tags(get_the_title());
    $u = rawurlencode($url);
    $t = rawurlencode($title);
    $ut = rawurlencode($title . ' ' . $url);
    $p = function ($d) { return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="' . $d . '"/></svg>'; };
    $stroke = function ($inner) { return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>'; };
    $links = [
        ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $u, $p('M13.5 22v-8.2h2.8l.5-3.3h-3.3V8.4c0-.9.3-1.6 1.7-1.6H17V3.9c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.4H7.5v3.3h2.8V22h3.2z')],
        ['X', 'https://x.com/intent/post?url=' . $u . '&text=' . $t, $p('M17.8 3h3.1l-6.8 7.7L22 21h-6.3l-4.9-6.4L5.2 21H2.1l7.3-8.3L2 3h6.4l4.4 5.9L17.8 3zm-1.1 16.2h1.7L7.4 4.7H5.6l11.1 14.5z')],
        ['Bluesky', 'https://bsky.app/intent/compose?text=' . $ut, $p('M5.2 4.1C7.7 6 10.4 9.800 12 12.500c1.600-2.700 4.300-6.500 6.800-8.400 1.800-1.400 4.700-2.400 4.700.9 0 .7-.4 5.500-.6 6.300-.8 2.700-3.500 3.400-5.900 3 4.200.7 5.300 3.100 3 5.500-4.400 4.500-6.300-1.100-6.800-2.500-.1-.3-.1-.4-.2-.4s-.1.100-.2.400c-.5 1.400-2.400 7-6.800 2.500-2.300-2.400-1.200-4.800 3-5.500-2.400.4-5.100-.3-5.900-3C.9 10.500.5 5.700.5 5c0-3.300 2.900-2.300 4.700-.9z')],
        ['Reddit', 'https://www.reddit.com/submit?url=' . $u . '&title=' . $t, $p('M22 12.100c0-1.200-1-2.200-2.200-2.200-.6 0-1.100.2-1.500.6-1.500-1-3.500-1.700-5.700-1.800l1-4.500 3.100.7c0 .8.700 1.500 1.500 1.500s1.500-.7 1.500-1.500S18.100 3.400 17.300 3.400c-.6 0-1.100.3-1.300.8l-3.500-.8c-.2 0-.4.100-.4.300l-1.100 5.100c-2.200.1-4.200.7-5.800 1.800-.4-.4-.9-.6-1.500-.6C2.600 10 1.600 11 1.600 12.200c0 .9.500 1.600 1.200 2v.6c0 3.100 3.600 5.600 8.100 5.600s8.100-2.500 8.100-5.600v-.6c.8-.4 1.300-1.200 1.300-2.100zM7.500 13.500c0-.8.700-1.500 1.500-1.500s1.500.7 1.500 1.500-.7 1.500-1.500 1.500-1.500-.7-1.500-1.500zm8.100 3.900c-1 1-2.900 1.100-3.600 1.100s-2.600-.1-3.600-1.100c-.1-.1-.1-.4 0-.5.1-.1.4-.1.500 0 .6.600 2 .9 3.100.9s2.400-.3 3.100-.9c.1-.1.4-.1.500 0 .1.100.1.400 0 .5zm-.3-2.400c-.8 0-1.500-.7-1.500-1.500s.7-1.500 1.500-1.500 1.500.7 1.500 1.500-.7 1.500-1.500 1.500z')],
        ['Threads', 'https://www.threads.net/intent/post?text=' . $ut, $p('M12.200 22h-.1c-3.600 0-6.300-1.200-8.100-3.600C2.500 16.400 1.700 13.600 1.700 12S2.500 7.600 4 5.600C5.800 3.200 8.500 2 12.100 2c3.600 0 6.400 1.200 8.200 3.500.9 1.100 1.500 2.500 1.900 4.100l-2 .5c-.3-1.200-.8-2.300-1.500-3.100-1.300-1.700-3.400-2.500-6.200-2.500-2.800 0-5 .9-6.300 2.600C5 8.700 4.400 10.300 4.400 12s.6 3.300 1.800 4.800c1.300 1.700 3.400 2.500 6.200 2.500 2.500 0 4.100-.6 5.500-1.900 1.600-1.500 1.600-3.400 1.100-4.500-.3-.7-.8-1.200-1.500-1.600-.2 1.300-.5 2.300-1.100 3.100-.8 1-1.900 1.600-3.500 1.700-1.200.1-2.300-.2-3.200-.8-1-.7-1.600-1.800-1.700-3-.1-2.400 1.800-4.100 4.700-4.300 1-.1 2-.0 2.900.1-.1-.7-.3-1.200-.7-1.600-.5-.5-1.300-.8-2.400-.8-1 0-2.200.3-2.800 1.200l-1.700-1.100c.9-1.400 2.500-2.100 4.500-2.100 3.400 0 5.200 2.100 5.400 5.700.8.400 1.500.9 2 1.600.8 1.100 1 2.800.2 4.400-.5 1-1.200 1.900-2 2.600-1.800 1.600-3.800 2.300-6.700 2.300zm.9-8.700c-1.800.1-2.400.9-2.400 1.600.1 1 1.100 1.500 2.200 1.500 1.500-.1 2.800-.7 3-3.100-.9-.1-1.800-.1-2.800 0z')],
        ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u, $p('M4.500 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM3 8.500h3V21H3V8.500zm5.500 0h2.900v1.700c.4-.8 1.500-1.900 3.400-1.900 3.600 0 4.200 2.400 4.200 5.400V21h-3v-6.600c0-1.600 0-3.500-2.200-3.500s-2.500 1.700-2.500 3.400V21h-3V8.500z')],
        ['Email', 'mailto:?subject=' . $t . '&body=' . $ut, $stroke('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>')],
    ];
    ?>
    <div class="rotc-article-share" role="group" aria-label="<?php esc_attr_e('Share this post', 'rotc-theme'); ?>">
      <span class="rotc-share-label"><?php esc_html_e('Share', 'rotc-theme'); ?></span>
      <?php foreach ($links as $l): ?>
        <a class="rotc-share-btn" href="<?php echo esc_url($l[1], ['http', 'https', 'mailto']); ?>" <?php echo $l[0] === 'Email' ? '' : 'target="_blank" rel="noopener noreferrer"'; ?> aria-label="<?php echo esc_attr('Share on ' . $l[0]); ?>" title="<?php echo esc_attr($l[0]); ?>"><?php echo $l[2]; // static SVG ?></a>
      <?php endforeach; ?>
      <button type="button" class="rotc-share-btn rotc-copy-link" data-url="<?php echo esc_url($url); ?>" aria-label="<?php esc_attr_e('Copy link', 'rotc-theme'); ?>" title="<?php esc_attr_e('Copy link', 'rotc-theme'); ?>">
        <?php echo $stroke('<path d="M10 13a5 5 0 0 0 7.100 0l3-3a5 5 0 0 0-7.100-7.100l-1.700 1.700"/><path d="M14 11a5 5 0 0 0-7.100 0l-3 3a5 5 0 0 0 7.100 7.100l1.700-1.700"/>'); ?>
        <span class="rotc-share-copied" aria-live="polite"></span>
      </button>
      <button type="button" class="rotc-share-btn rotc-native-share" hidden data-url="<?php echo esc_url($url); ?>" data-title="<?php echo esc_attr($title); ?>" aria-label="<?php esc_attr_e('Share via device', 'rotc-theme'); ?>" title="<?php esc_attr_e('Share', 'rotc-theme'); ?>">
        <?php echo $stroke('<path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M12 15V3"/><path d="m7 8 5-5 5 5"/>'); ?>
      </button>
    </div>
    <script>
    (function(){
      var g = document.querySelector('.rotc-article-share'); if (!g) return;
      var copy = g.querySelector('.rotc-copy-link'), nat = g.querySelector('.rotc-native-share');
      if (nat && navigator.share) {
        nat.hidden = false;
        nat.addEventListener('click', function(){
          navigator.share({title: nat.getAttribute('data-title'), url: nat.getAttribute('data-url')}).catch(function(){});
        });
      }
      if (copy) copy.addEventListener('click', function(){
        var note = copy.querySelector('.rotc-share-copied');
        var done = function(){ note.textContent = 'Copied!'; copy.classList.add('is-copied'); setTimeout(function(){ note.textContent = ''; copy.classList.remove('is-copied'); }, 1800); };
        var url = copy.getAttribute('data-url');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(done, function(){});
        } else {
          var ta = document.createElement('textarea');
          ta.value = url; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
          document.body.removeChild(ta);
        }
      });
    })();
    </script>
    <?php
}

/**
 * Open Graph / Twitter card fallback for single posts. SiteSEO (plugin in
 * wp-content/plugins) already emits these when active, so only fire when
 * it is absent, to avoid duplicate tags.
 */
function rotc_theme_social_meta(): void {
    if (defined('SITESEO_VERSION') || !is_singular('post')) return;
    $id = get_queried_object_id();
    $desc = wp_strip_all_tags(get_the_excerpt($id));
    $img = get_the_post_thumbnail_url($id, 'large');
    $tags = [
        'og:type' => 'article', 'og:site_name' => get_bloginfo('name'),
        'og:title' => wp_strip_all_tags(get_the_title($id)), 'og:description' => $desc,
        'og:url' => get_permalink($id),
        'twitter:card' => $img ? 'summary_large_image' : 'summary',
        'twitter:title' => wp_strip_all_tags(get_the_title($id)), 'twitter:description' => $desc,
    ];
    if ($img) { $tags['og:image'] = $img; $tags['twitter:image'] = $img; }
    foreach ($tags as $k => $v) {
        $attr = strpos($k, 'og:') === 0 ? 'property' : 'name';
        echo '<meta ' . $attr . '="' . esc_attr($k) . '" content="' . esc_attr($v) . '">' . "\n";
    }
}
add_action('wp_head', 'rotc_theme_social_meta', 5);

/**
 * NOTE: this theme used to register a 'rotc-footer' widget area here.
 * Removed -- confirmed live it was a dead end: Appearance > Widgets
 * showed that area as completely empty, yet dynamic_sidebar('rotc-footer')
 * was still rendering a stale "Latest Comments" block on every page,
 * wrapped in this sidebar's own before_widget markup. That's a real
 * WordPress quirk (the block-based Widgets screen not reflecting a
 * leftover classic widget assignment in the sidebars_widgets option),
 * not something fixable from the admin UI. Simplest, most reliable fix:
 * the theme never calls dynamic_sidebar() on it at all now (see
 * front-page.php/page.php/footer.php), so there is nothing left for
 * that stale assignment to render into, regardless of what's still
 * sitting in the database.
 */

require get_template_directory() . '/inc/league-data.php';
require get_template_directory() . '/inc/epkb-compat.php';
require get_template_directory() . '/inc/wpforo-compat.php';
require get_template_directory() . '/inc/template-tags.php';

/**
 * [rotc_stat number="20.38" label="points Cam Ward scored from the bench"]
 * A big-numeral callout box for the one stat a recap wants to land --
 * this content is stats-heavy (weekly point totals, margins) that
 * currently has zero visual weight, sitting as plain paragraph text
 * identical to everything else on the page. Drop this shortcode
 * anywhere in the post body; no block/plugin dependency, so it works
 * the same whether a post is hand-written in the block editor or
 * inserted via a script (wp_insert_post / REST) the way the recap
 * articles are.
 */
function rotc_theme_stat_shortcode($atts): string {
    $a = shortcode_atts(['number' => '', 'label' => ''], $atts, 'rotc_stat');
    if ($a['number'] === '') return '';
    return '<div class="rotc-stat-callout">'
        . '<div class="rotc-stat-callout-num">' . esc_html($a['number']) . '</div>'
        . ($a['label'] !== '' ? '<div class="rotc-stat-callout-label">' . esc_html($a['label']) . '</div>' : '')
        . '</div>';
}
add_shortcode('rotc_stat', 'rotc_theme_stat_shortcode');

/**
 * [rotc_matchup week="2" team="0002"]
 * The one thing a generic blog can't do: a real embedded score bug for
 * the ACTUAL matchup a recap article is about, sourced live from
 * manage/api/wp-feed.php (inc/league-data.php) -- same franchise ids,
 * helmet art, and scores the /manage/ app itself uses, not something
 * hand-typed into the post that can drift from the real result.
 * `team` is either side's franchise id (e.g. "0002") -- a franchise
 * plays exactly one game a week, so one id is enough to find the game;
 * the shortcode renders both sides regardless of which one was passed.
 * Silently renders nothing if the week/team don't resolve to a real
 * game (bad id, bye week, feed unreachable) rather than showing a
 * broken box in the middle of an article.
 */
function rotc_theme_matchup_shortcode($atts): string {
    $a = shortcode_atts(['week' => '', 'team' => ''], $atts, 'rotc_matchup');
    $week = (int) $a['week'];
    $team = trim((string) $a['team']);
    if ($week < 1 || $team === '') return '';

    $game = rotc_theme_find_game(rotc_theme_get_week_feed($week), $team);
    if (!$game) return '';

    ob_start();
    ?>
    <div class="rotc-matchup-embed">
      <div class="rotc-matchup-embed-week">Week <?php echo (int) $week; ?><?php echo $game['isGameOfWeek'] ? ' &middot; Game of the Week' : ''; ?></div>
      <div class="rotc-matchup-embed-teams">
        <div class="rotc-matchup-embed-side win">
          <?php if ($game['helmet']): ?><img src="<?php echo esc_url($game['helmet']); ?>" alt="" class="rotc-matchup-embed-helmet<?php echo $game['helmetFlip'] ? ' flip' : ''; ?>"><?php endif; ?>
          <div class="rotc-matchup-embed-name"><?php echo esc_html($game['winner']); ?></div>
          <div class="rotc-matchup-embed-score"><?php echo esc_html(number_format((float) $game['winnerScore'], 2)); ?></div>
        </div>
        <div class="rotc-matchup-embed-final">FINAL</div>
        <div class="rotc-matchup-embed-side">
          <?php if ($game['loserHelmet']): ?><img src="<?php echo esc_url($game['loserHelmet']); ?>" alt="" class="rotc-matchup-embed-helmet<?php echo $game['loserHelmetFlip'] ? ' flip' : ''; ?>"><?php endif; ?>
          <div class="rotc-matchup-embed-name"><?php echo esc_html($game['loser']); ?></div>
          <div class="rotc-matchup-embed-score"><?php echo esc_html(number_format((float) $game['loserScore'], 2)); ?></div>
        </div>
      </div>
    </div>
    <?php
    return trim((string) ob_get_clean());
}
add_shortcode('rotc_matchup', 'rotc_theme_matchup_shortcode');
