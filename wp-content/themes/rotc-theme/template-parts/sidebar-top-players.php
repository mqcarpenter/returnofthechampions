<?php
/**
 * template-parts/sidebar-top-players.php
 * "Top Players" sidebar card -- season fantasy-point leaders with
 * position tabs (All/QB/RB/WR/TE) and the same hover photo card the
 * /manage/ Top Performers page uses. Data: manage/api/wp-top-players.php
 * via rotc_theme_get_top_players(). Renders nothing if the feed is down.
 */
if (!defined('ABSPATH')) exit;

$rotc_tp = rotc_theme_get_top_players();
if (!$rotc_tp) return;
$rotc_tp_base = trailingslashit(home_url()) . 'manage/players/top-performers';
?>
<div class="rotc-card rotc-top-players">
  <h3 class="rotc-section-title" style="font-size:15px;"><?php echo esc_html(sprintf(__('Top Players %d', 'rotc-theme'), $rotc_tp['year'])); ?></h3>
  <div class="rotc-tp-tabs" role="tablist">
    <?php foreach ($rotc_tp['positions'] as $i => $pos): if (empty($rotc_tp['players'][$pos])) continue; ?>
      <button type="button" role="tab" class="rotc-tp-tab<?php echo $i === 0 ? ' is-active' : ''; ?>" data-pos="<?php echo esc_attr($pos); ?>"><?php echo esc_html($pos === 'ALL' ? 'All' : $pos); ?></button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($rotc_tp['positions'] as $i => $pos): if (empty($rotc_tp['players'][$pos])) continue; ?>
    <ol class="rotc-tp-list" data-pos="<?php echo esc_attr($pos); ?>"<?php echo $i === 0 ? '' : ' hidden'; ?>>
      <?php foreach ($rotc_tp['players'][$pos] as $n => $p):
        $stats = '';
        foreach ($p['stats'] as $row) $stats .= esc_html($row[0]) . ': <strong>' . esc_html($row[1]) . '</strong><br>';
      ?>
        <li>
          <span class="rotc-tp-rank"><?php echo (int) ($n + 1); ?></span>
          <img class="rotc-tp-logo" src="<?php echo esc_url($p['logo']); ?>" alt="<?php echo esc_attr($p['team']); ?>" width="20" height="20" loading="lazy">
          <span class="rotc-tp-who">
            <span class="rotc-tp-name rotc-player-hover"
              data-name="<?php echo esc_attr($p['name']); ?>"
              data-photo="<?php echo esc_url($p['photo'] ?? ''); ?>"
              data-bio="<?php echo esc_attr($p['bio']); ?>"
              data-stats="<?php echo esc_attr($stats); ?>"><?php echo esc_html($p['name']); ?></span>
            <span class="rotc-tp-sub"><?php echo esc_html($p['position'] . ' · ' . ($p['owner'] ?: __('Free Agent', 'rotc-theme'))); ?></span>
          </span>
          <span class="rotc-tp-pts"><?php echo esc_html($p['score']); ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endforeach; ?>
  <a class="rotc-tp-more" href="<?php echo esc_url($rotc_tp_base); ?>"><?php esc_html_e('Full Top Performers chart →', 'rotc-theme'); ?></a>
</div>
<div id="rotc-player-card" class="rotc-player-card" hidden>
  <img id="rotc-pc-photo" src="" alt="">
  <div id="rotc-pc-name"></div>
  <div id="rotc-pc-bio"></div>
  <div id="rotc-pc-stats"></div>
</div>
<script>
(function () {
  var root = document.querySelector('.rotc-top-players');
  var card = document.getElementById('rotc-player-card');
  if (!root || !card) return;
  var photo = document.getElementById('rotc-pc-photo');

  root.querySelectorAll('.rotc-tp-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      root.querySelectorAll('.rotc-tp-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
      root.querySelectorAll('.rotc-tp-list').forEach(function (l) { l.hidden = l.dataset.pos !== tab.dataset.pos; });
      card.hidden = true;
    });
  });

  root.querySelectorAll('.rotc-player-hover').forEach(function (el) {
    el.addEventListener('mouseenter', function () {
      document.getElementById('rotc-pc-name').textContent = el.dataset.name || '';
      document.getElementById('rotc-pc-bio').textContent = el.dataset.bio || '';
      document.getElementById('rotc-pc-stats').innerHTML = el.dataset.stats || '';
      if (el.dataset.photo) {
        photo.src = el.dataset.photo;
        photo.style.display = 'block';
        photo.onerror = function () { photo.style.display = 'none'; };
      } else {
        photo.style.display = 'none';
      }
      card.hidden = false;
    });
    el.addEventListener('mousemove', function (e) {
      // Sidebar sits at the right edge, so default to opening LEFT of the cursor.
      var w = card.offsetWidth, h = card.offsetHeight;
      var x = e.clientX - w - 16, y = e.clientY + 16;
      if (x < 8) x = e.clientX + 16;
      if (y + h > window.innerHeight - 8) y = Math.max(8, e.clientY - h - 16);
      card.style.left = x + 'px';
      card.style.top = y + 'px';
    });
    el.addEventListener('mouseleave', function () { card.hidden = true; });
  });
})();
</script>
