<?php
/**
 * single.php — single news post.
 *
 * Rebuilt 2026-09 (was the default WP skeleton: plain title, a small
 * inset thumbnail, unstyled paragraphs, a bare-text "More News" list):
 * - Full-bleed hero with the title/byline overlaid on the image itself
 *   (falls back to a plain header when a post has no featured image).
 * - Byline now shows the author's avatar + a reading-time estimate,
 *   not just a bare date string.
 * - Category kicker removed entirely -- every post on this site is
 *   still "Uncategorized" in practice, so the badge was pure noise,
 *   not real navigation. (This was the ONLY genuinely category-driven
 *   kicker in the theme; 404.php's and homepage-content.php's uses of
 *   the same CSS class are unrelated badges, not category display, and
 *   are untouched.)
 * - Share links (X, Facebook, copy-link) next to the byline.
 * - "More News" sidebar now shows a thumbnail per item, not bare text.
 * - "Top Players" sidebar widget (season leaders, position tabs, hover
 *   photo cards) between Scores and More News.
 * - A related-posts grid (reusing the same card partial the homepage/
 *   archive use) now runs below the article body -- previously nothing
 *   followed the post at all.
 */
if (!defined('ABSPATH')) exit;
get_header();
?>
<div class="rotc-layout">
  <main>
    <?php while (have_posts()): the_post();
      $hasHero = has_post_thumbnail();
    ?>
      <article <?php post_class('rotc-card'); ?>>
        <?php if ($hasHero): ?>
          <div class="rotc-article-hero">
            <?php the_post_thumbnail('large', ['class' => 'rotc-article-hero-img']); ?>
            <div class="rotc-article-hero-scrim"></div>
            <header class="rotc-article-header rotc-article-header-overlay">
              <h1 class="rotc-article-title"><?php the_title(); ?></h1>
              <div class="rotc-article-meta">
                <?php echo get_avatar(get_the_author_meta('ID'), 28, '', '', ['class' => 'rotc-article-avatar']); ?>
                <span><?php echo rotc_theme_posted_on(); ?> &middot; <?php echo esc_html(rotc_theme_reading_time()); ?></span>
              </div>
            </header>
          </div>
        <?php else: ?>
          <header class="rotc-article-header">
            <h1 class="rotc-article-title"><?php the_title(); ?></h1>
            <div class="rotc-article-meta">
              <?php echo get_avatar(get_the_author_meta('ID'), 24, '', '', ['class' => 'rotc-article-avatar']); ?>
              <span><?php echo rotc_theme_posted_on(); ?> &middot; <?php echo esc_html(rotc_theme_reading_time()); ?></span>
            </div>
          </header>
        <?php endif; ?>

        <div class="rotc-article-share">
          <?php $permalink = esc_url(get_permalink()); $titleEnc = rawurlencode(get_the_title()); ?>
          <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode($permalink); ?>&text=<?php echo $titleEnc; ?>" target="_blank" rel="noopener">Share on X</a>
          <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($permalink); ?>" target="_blank" rel="noopener">Share on Facebook</a>
          <button type="button" class="rotc-copy-link" data-url="<?php echo $permalink; ?>">Copy Link</button>
        </div>

        <div class="rotc-article-body">
          <?php the_content(); ?>
        </div>
        <?php
        $tags = get_the_tags();
        if ($tags):
        ?>
          <div class="rotc-tags">
            <?php foreach ($tags as $tag): ?>
              <a href="<?php echo esc_url(get_tag_link($tag)); ?>">#<?php echo esc_html($tag->name); ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>

      <?php
      $related = new WP_Query([
          'post_type' => 'post', 'posts_per_page' => 3,
          'post__not_in' => [get_the_ID()], 'ignore_sticky_posts' => true,
      ]);
      if ($related->have_posts()):
      ?>
        <section class="rotc-related">
          <h2 class="rotc-section-title">More From Return of the Champions</h2>
          <div class="rotc-news-grid">
            <?php while ($related->have_posts()): $related->the_post(); ?>
              <?php get_template_part('template-parts/content', 'post'); ?>
            <?php endwhile; wp_reset_postdata(); ?>
          </div>
        </section>
      <?php endif; ?>
    <?php endwhile; ?>
  </main>

  <aside class="rotc-sidebar">
    <?php get_template_part('template-parts/sidebar-scores'); ?>
    <?php get_template_part('template-parts/sidebar-top-players'); ?>
    <div class="rotc-card">
      <h3 class="rotc-section-title" style="font-size:15px;"><?php esc_html_e('More News', 'rotc-theme'); ?></h3>
      <?php
      $recent = new WP_Query(['post_type' => 'post', 'posts_per_page' => 5, 'post__not_in' => [get_the_ID()]]);
      if ($recent->have_posts()):
      ?>
        <ul class="rotc-sidebar-news-list">
          <?php while ($recent->have_posts()): $recent->the_post(); ?>
            <li>
              <a href="<?php the_permalink(); ?>">
                <?php if (has_post_thumbnail()): ?>
                  <span class="rotc-sidebar-news-thumb"><?php the_post_thumbnail('thumbnail'); ?></span>
                <?php endif; ?>
                <span class="rotc-sidebar-news-title"><?php the_title(); ?></span>
              </a>
            </li>
          <?php endwhile; wp_reset_postdata(); ?>
        </ul>
      <?php endif; ?>
    </div>
  </aside>
</div>
<script>
(function(){
  var btn = document.querySelector('.rotc-copy-link');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var url = btn.getAttribute('data-url');
    var done = function(){ var t = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = t; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = url; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      document.body.removeChild(ta);
    }
  });
})();
</script>
<?php get_footer(); ?>
