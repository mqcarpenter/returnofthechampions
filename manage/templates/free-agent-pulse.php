<?php
/**
 * templates/free-agent-pulse.php
 * Second sidebar tabbed widget, below templates/sidefeed.php's Smack
 * Feed / Top Adds-Drops. Reuses the SAME .rotc-sidefeed/.rotc-sidefeed-
 * tab(s)/.rotc-sidefeed-panel CSS + tab-switching JS as sidefeed.php --
 * those classes aren't Smack-Feed-specific, and the JS in sidefeed.php
 * now waits for DOMContentLoaded before wiring up tabs, so a second
 * widget added later in the page works with no JS/CSS changes needed.
 *
 * Tabs: Instagram (primary, open by default -- the league's
 * @returnofthechampions feed, which replaced the old Draft Trends tab)
 * and Top Free Agents.
 *
 * $instagram_posts (array) - from includes/instagram-feed.php's
 *   rotc_fetch_instagram_posts(); empty shows a "Follow us" card.
 * $top_free_agents (array) - rows from includes/free-agent-pulse.php's
 *   rotc_fetch_top_free_agents(). Player names use rotc_player_hover_span()
 *   (same hover card every other player list on the site uses) -- the
 *   including page must call rotc_player_hover_widget() once (index.php
 *   already does, for sidefeed.php's rows).
 */
$top_free_agents = $top_free_agents ?? [];
$instagram_posts = $instagram_posts ?? [];
$ig_handle = function_exists('rotc_instagram_handle') ? rotc_instagram_handle() : 'returnofthechampions';
$ig_profile = 'https://www.instagram.com/' . rawurlencode($ig_handle) . '/';

/** One free-agent row: logo, hoverable name, position, Week 1 projected points. */
function rotc_fa_pulse_row(array $r): void {
?>
  <div class="rotc-trending-row">
    <?= rotc_team_logo_img($r['team'], 18) ?>
    <span class="rotc-trending-name"><?= rotc_player_hover_span($r['name'], $r['pd'], ['Wk 1 Proj' => number_format($r['proj'], 1) . ' pts']) ?></span>
    <span class="rotc-trending-pos"><?= htmlspecialchars($r['position']) ?></span>
    <span class="rotc-trending-pct"><?= number_format($r['proj'], 1) ?></span>
  </div>
<?php
}
?>
<div class="rotc-sidefeed">
  <div class="rotc-sidefeed-tabs">
    <button class="rotc-sidefeed-tab active" data-tab="instagram">Instagram</button>
    <button class="rotc-sidefeed-tab" data-tab="topfa">Top Free Agents</button>
  </div>

  <div class="rotc-sidefeed-panel active" data-panel="instagram">
    <div class="rotc-ig-head">
      <a class="rotc-ig-handle" href="<?= htmlspecialchars($ig_profile) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($ig_handle) ?></a>
      <a class="rotc-ig-follow" href="<?= htmlspecialchars($ig_profile) ?>" target="_blank" rel="noopener">Follow</a>
    </div>
    <?php if ($instagram_posts): ?>
      <div class="rotc-ig-grid">
        <?php foreach ($instagram_posts as $p):
          $alt = $p['caption'] !== '' ? mb_strimwidth(preg_replace('/\s+/', ' ', $p['caption']), 0, 120, '…') : 'Instagram post from @' . $ig_handle;
        ?>
          <a class="rotc-ig-tile" href="<?= htmlspecialchars($p['url']) ?>" target="_blank" rel="noopener" title="<?= htmlspecialchars($alt) ?>">
            <img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($alt) ?>" loading="lazy" decoding="async">
            <?php if ($p['type'] === 'VIDEO'): ?><span class="rotc-ig-badge" aria-label="Video">&#9654;</span>
            <?php elseif ($p['type'] === 'CAROUSEL_ALBUM'): ?><span class="rotc-ig-badge" aria-label="Several photos">&#10697;</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="rotc-ig-more"><a href="<?= htmlspecialchars($ig_profile) ?>" target="_blank" rel="noopener">See more on Instagram &rarr;</a></div>
    <?php else: ?>
      <div class="rotc-sidefeed-item">
        <div class="desc">Highlights, trash talk and league moments live on our Instagram. Tap Follow to see the latest.</div>
      </div>
    <?php endif; ?>
  </div>

  <div class="rotc-sidefeed-panel" data-panel="topfa">
    <div class="rotc-trending-group">
      <div class="rotc-trending-heading">Top 20 Available &mdash; Week 1 Projection</div>
      <?php if (!$top_free_agents): ?>
        <div class="rotc-sidefeed-item"><div class="desc">No free agent data available.</div></div>
      <?php else: foreach ($top_free_agents as $r) rotc_fa_pulse_row($r); endif; ?>
    </div>
  </div>
</div>
