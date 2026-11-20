<?php
/**
 * includes/player-hover.php
 * Shared "hover a player's name, see a photo + bio card" widget, first
 * built for rosters.php and pulled out here so any page listing
 * players (free agents, ADP/AAV reports, trade bait, etc) can reuse
 * the same card instead of re-implementing it.
 *
 * Photo: ESPN's public headshot CDN, keyed off the espn_id that MFL's
 * players API (DETAILS=1) cross-references -- verified this resolves
 * to real photos.
 *
 * Deliberately limited to bio fields (position/team/college/height/
 * weight) plus whatever fantasy-scoring stat lines the calling page
 * passes in (2025 total points, bye week, etc). Never shows real
 * in-game NFL stat lines -- MFL's own terms of service forbid exposing
 * raw NFL player stats via the API, so this stays on the safe side of
 * that line everywhere it's used.
 */

/**
 * NFL team logo (ESPN's public team-logo CDN), keyed off MFL's team
 * abbreviation (the `team` field on players/byeWeeks/schedule records).
 * MFL and ESPN don't always use the same code for the same team --
 * confirmed live against TYPE=nflByeWeeks (lists every MFL code:
 * GBP, JAC, KCC, LVR, NEP, NOS, SFO, TBB, WAS differ from ESPN's gb,
 * jax, kc, lv, ne, no, sf, tb, wsh) and verified each mapped ESPN URL
 * below returns 200. Everything not listed matches MFL's code
 * lowercased (they're the same for the other 23 teams).
 */
const ROTC_ESPN_TEAM_MAP = [
    'GBP' => 'gb', 'JAC' => 'jax', 'KCC' => 'kc', 'LVR' => 'lv',
    'NEP' => 'ne', 'NOS' => 'no', 'SFO' => 'sf', 'TBB' => 'tb', 'WAS' => 'wsh',
];

// ESPN's NFL league shield -- used as the logo for players with no
// current NFL team (released vets still shown in the free agent pool,
// mainly). Confirmed live this URL returns 200.
const ROTC_NFL_SHIELD_LOGO = 'https://a.espncdn.com/i/teamlogos/leagues/500/nfl.png';

function rotc_team_logo_url(?string $mflTeamCode): string {
    // MFL represents "not currently on an NFL roster" as the literal
    // string "FA" (confirmed live on free-agents.php), not a blank
    // value -- ESPN's CDN has no team logo file for "fa" (confirmed
    // 404), so that string was silently falling through to a broken
    // image before this check. Both blank AND "FA" mean no team.
    if (!$mflTeamCode || strtoupper($mflTeamCode) === 'FA') return ROTC_NFL_SHIELD_LOGO;
    $code = ROTC_ESPN_TEAM_MAP[$mflTeamCode] ?? strtolower($mflTeamCode);
    return 'https://a.espncdn.com/i/teamlogos/nfl/500/' . $code . '.png';
}

/**
 * <img> tag for a team logo, sized for a table cell. Falls back to the
 * generic NFL shield for a player with no team code (e.g. a free agent
 * not currently on an NFL roster) rather than showing nothing. Fails
 * silently (hides itself via onerror) if ESPN's CDN itself hiccups.
 */
function rotc_team_logo_img(?string $mflTeamCode, int $size = 20): string {
    $url = rotc_team_logo_url($mflTeamCode);
    $alt = $mflTeamCode ?: 'FA';
    return '<img class="rotc-team-logo" src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($alt) . '"'
        . ' width="' . $size . '" height="' . $size . '"'
        . ' loading="lazy" decoding="async"'
        . ' onerror="this.style.display=\'none\'">';
}

function rotc_espn_photo(?array $pd): ?string {
    if (!$pd || empty($pd['espn_id'])) return null;
    return 'https://a.espncdn.com/i/headshots/nfl/players/full/' . $pd['espn_id'] . '.png';
}

/**
 * Builds the bio line ("QB · BUF · Wyoming · 77" · 237 lbs") from a
 * players(DETAILS=1) record.
 */
function rotc_player_bio_bits(?array $pd): array {
    if (!$pd) return [];
    $bits = [];
    if (!empty($pd['position'])) $bits[] = $pd['position'];
    if (!empty($pd['team'])) $bits[] = $pd['team'];
    if (!empty($pd['college'])) $bits[] = $pd['college'];
    if (!empty($pd['height'])) $bits[] = $pd['height'] . '"';
    if (!empty($pd['weight'])) $bits[] = $pd['weight'] . ' lbs';
    return $bits;
}

/* ============================================================
   INJURY STATUS TAGS
   MFL prints a short coloured code after a player's name -- (Q), (O),
   (IR) -- everywhere it lists players, and this reproduces that so the
   same signal is available site-wide instead of only on the injury
   report. Wired into rotc_player_hover_span() below, which means every
   page that renders a player name through the shared helper picks it up
   for free (rosters, free agents, lineups, trades, drops, transactions,
   trending, recaps, the auction page).

   The data behind it -- rotc_injury_map() and rotc_injury_badge() --
   lives in includes/mfl-api.php, so the live-scoring feed can read
   statuses without pulling in any rendering code. This is only the
   display half.
   ============================================================ */

/**
 * "Questionable (Hamstring — back Sep 13, 2026)" -- the full readable
 * injury line, shared by rotc_injury_tag()'s tooltip below AND the
 * hover card's injury field, so the two never drift out of sync.
 * '' when $status is blank/unrecognized.
 */
function rotc_injury_compose_detail(string $status, string $details, string $expReturn): string {
    $badge = rotc_injury_badge($status);
    if (!$badge) return '';
    // "back Feb 15, 2027" on a RETIRED player is nonsense -- MFL parks a
    // placeholder date on the statuses that aren't a real return. Only
    // the ones someone is actually waiting on get a return date.
    if ($badge['key'] === 'gone') $expReturn = '';
    if ($expReturn !== '') $details = trim($details . ($details !== '' ? ' — ' : '') . 'back ' . $expReturn);
    return trim($status . ($details !== '' ? ' (' . $details . ')' : ''));
}

/**
 * The full injury line for a player id (see rotc_injury_compose_detail
 * above), or '' when they're healthy / have no injury row. For the
 * hover card -- rotc_injury_tag() below is the compact "(Q)" tag that
 * sits outside it.
 */
function rotc_injury_detail_text(?string $playerId): string {
    if (!$playerId) return '';
    $row = rotc_injury_map()[$playerId] ?? null;
    if (!$row) return '';
    return rotc_injury_compose_detail($row['status'], $row['details'], $row['exp_return']);
}

/**
 * The rendered "(Q)" tag for a player id, or '' when they're healthy.
 * Always returns safe HTML (or an empty string), so call sites can
 * concatenate it straight onto a name with no further escaping.
 *
 * Pass $status directly to skip the lookup -- players/injury-report.php
 * already has the row in hand and shouldn't re-resolve it.
 */
function rotc_injury_tag(?string $playerId, ?string $status = null): string {
    if ($status === null) {
        if ($playerId === null || $playerId === '') return '';
        $row = rotc_injury_map()[$playerId] ?? null;
        if (!$row) return '';
        $status = $row['status'];
        $detail = $row['details'];
        $return = $row['exp_return'];
    } else {
        $detail = '';
        $return = '';
    }
    $badge = rotc_injury_badge((string) $status);
    if (!$badge) return '';
    $title = rotc_injury_compose_detail((string) $status, $detail, $return);
    return ' <span class="rotc-inj rotc-inj-' . $badge['key'] . '"'
         . ' title="' . htmlspecialchars($title) . '">'
         . '(' . htmlspecialchars($badge['abbr']) . ')</span>';
}

/* ============================================================
   MFL PLAYER PROFILE LINK
   This app deliberately never shows real in-game NFL stat lines (see
   the file doc comment -- MFL's API terms forbid it), and MFL's export
   API has no per-player "recent news" data at all (only `siteNews`,
   which is league-wide commissioner/trade activity, not NFL player
   news) -- confirmed against MFL's own API reference doc
   (docs/api_info-2026-07-17.html has no player-scoped news export).
   So there's no data this app can use to flag "this specific player
   has news right now" -- this icon is a constant, always-available
   link out to MFL's own player page instead, which is where real NFL
   stats and news both actually live. Shown on every player uniformly
   rather than faking a "has news" condition this app can't detect.

   Reuses news_articles?P=<id> -- the same MFL URL pattern already live
   in templates/nav-data.php's "Player News" nav item (P=* there is a
   wildcard; a real player id works the same way) -- and the same
   MFL-popup window technique already used for every other MFL link in
   this app (templates/nav-data.php's rotc_nav_sub_item()).
   SEASON ROLLOVER: same as nav-data.php -- find-and-replace the /2026/
   path segment here once a year.
   ============================================================ */
function rotc_mfl_player_url(string $playerId): string {
    return 'https://www42.myfantasyleague.com/2026/news_articles?L=' . MFL_LEAGUE_ID . '&P=' . urlencode($playerId);
}

/**
 * Small icon link to a player's MFL profile (news + real NFL stats),
 * opened in MFL's own popup window. Returns '' if $playerId is empty
 * (e.g. a row rendered without a resolved MFL id).
 */
function rotc_mfl_player_link(?string $playerId): string {
    if (!$playerId) return '';
    $url = rotc_mfl_player_url($playerId);
    // Icon is rendered via CSS .rotc-player-mfl-link::before and popup
    // window is opened via a single delegated click handler in
    // rotc_player_hover_widget() (target="_blank" acts as fallback).
    return ' <a href="' . htmlspecialchars($url) . '" class="rotc-player-mfl-link"'
         . ' title="Open this player on MFL (news + NFL stats)"'
         . ' aria-label="Open player on MFL"'
         . ' target="_blank" rel="noopener"></a>';
}

/**
 * Wraps $displayName in the hoverable span. $statLines is an
 * associative array of label => value (e.g. ['2025 Total' => '413.20
 * pts', 'Bye Week' => '7']); blank/empty values are skipped.
 */
function rotc_player_hover_span(string $displayName, ?array $pd, array $statLines = []): string {
    $espnId = ($pd && !empty($pd['espn_id'])) ? (string) $pd['espn_id'] : '';
    $bio = implode(' · ', rotc_player_bio_bits($pd));
    $id = isset($pd['id']) ? (string) $pd['id'] : null;

    // Fetch each request-scoped map once into a local variable to avoid
    // repeated function-call overhead in tight loops (e.g. 432-row rosters).
    $leagueLines = [];
    if ($id !== null) {
        $owners = rotc_owner_map();
        $ranks  = rotc_season_rank_map();
        $byes   = rotc_bye_week_map();

        $leagueLines['Fantasy Team'] = $owners[$id] ?? 'Free Agent';
        $rank = $ranks[$id] ?? null;
        if ($rank) {
            $leagueLines[(string) MFL_YEAR . ' Total'] = number_format($rank['total'], 2) . ' pts';
            $pos = $pd['position'] ?? '';
            $leagueLines['Position Rank'] = ($pos !== '' ? $pos . ' ' : '') . '#' . $rank['rank'] . ' of ' . $rank['posCount'];
        }
        $team = $pd['team'] ?? '';
        $bye = $team !== '' ? ($byes[$team] ?? '') : '';
        if ($bye !== '') $leagueLines['Bye Week'] = $bye;
        $injDetail = rotc_injury_detail_text($id);
        if ($injDetail !== '') $leagueLines['Status'] = $injDetail;
    }

    // Compact newline-delimited "Label\tValue" encoding -- avoids repeating
    // HTML-escaped &lt;strong&gt;/&lt;br&gt; tags hundreds of times per page
    // and renders safely via DOM nodes in JS.
    $lines = [];
    foreach ($leagueLines + $statLines as $label => $value) {
        if ($value === '' || $value === null) continue;
        $cleanLabel = str_replace(["\r", "\n", "\t"], ' ', (string) $label);
        $cleanValue = str_replace(["\r", "\n", "\t"], ' ', (string) $value);
        $lines[] = $cleanLabel . "\t" . $cleanValue;
    }
    $inj = rotc_injury_tag($id);
    $mflLink = rotc_mfl_player_link($id);

    $attrs = 'class="rotc-player-hover"';
    if ($espnId !== '') $attrs .= ' data-espn="' . htmlspecialchars($espnId) . '"';
    if ($bio !== '')    $attrs .= ' data-bio="' . htmlspecialchars($bio) . '"';
    if ($lines)         $attrs .= ' data-s="' . htmlspecialchars(implode("\n", $lines)) . '"';

    return '<span ' . $attrs . '>' . htmlspecialchars($displayName) . '</span>' . $inj . $mflLink;
}

/**
 * Echo this once near the bottom of any page that uses
 * rotc_player_hover_span() -- outputs the floating card markup plus
 * delegated JS listeners (O(1) listeners regardless of player count).
 */
function rotc_player_hover_widget(): void {
?>
<div id="rotc-player-card" style="display:none;position:fixed;z-index:999;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 12px 28px rgba(0,0,0,.22);padding:12px;width:220px;pointer-events:none;">
  <img id="rotc-pc-photo" src="" alt="" style="width:100%;height:140px;object-fit:cover;border-radius:8px;background:var(--sand);display:none;">
  <div id="rotc-pc-name" style="font-weight:700;font-family:'Roboto Condensed',sans-serif;margin-top:8px;"></div>
  <div id="rotc-pc-bio" style="color:var(--muted);font-size:12px;margin-top:2px;"></div>
  <div id="rotc-pc-stats" style="font-size:13px;margin-top:8px;border-top:1px solid var(--line);padding-top:8px;"></div>
</div>
<script>
(function () {
  var card = document.getElementById('rotc-player-card');
  var photo = document.getElementById('rotc-pc-photo');
  var nameEl = document.getElementById('rotc-pc-name');
  var bioEl = document.getElementById('rotc-pc-bio');
  var statsEl = document.getElementById('rotc-pc-stats');
  if (!card) return;

  var ESPN_BASE = 'https://a.espncdn.com/i/headshots/nfl/players/full/';
  var activeEl = null;

  function renderStats(el) {
    statsEl.textContent = '';
    if (el.dataset.s) {
      var rows = el.dataset.s.split('\n');
      for (var i = 0; i < rows.length; i++) {
        if (i > 0) statsEl.appendChild(document.createElement('br'));
        var tab = rows[i].indexOf('\t');
        if (tab >= 0) {
          statsEl.appendChild(document.createTextNode(rows[i].slice(0, tab) + ': '));
          var strong = document.createElement('strong');
          strong.textContent = rows[i].slice(tab + 1);
          statsEl.appendChild(strong);
        } else {
          statsEl.appendChild(document.createTextNode(rows[i]));
        }
      }
    } else if (el.dataset.stats) {
      statsEl.innerHTML = el.dataset.stats;
    }
  }

  document.addEventListener('mouseover', function (e) {
    var el = e.target.closest && e.target.closest('.rotc-player-hover');
    if (!el || el === activeEl) return;
    activeEl = el;
    nameEl.textContent = el.dataset.name || el.textContent || '';
    bioEl.textContent = el.dataset.bio || '';
    renderStats(el);
    var src = el.dataset.espn ? (ESPN_BASE + el.dataset.espn + '.png') : (el.dataset.photo || '');
    if (src) {
      photo.src = src;
      photo.style.display = 'block';
      photo.onerror = function () { photo.style.display = 'none'; };
    } else {
      photo.style.display = 'none';
    }
    card.style.display = 'block';
  });

  document.addEventListener('mousemove', function (e) {
    if (!activeEl) return;
    var x = e.clientX + 16, y = e.clientY + 16;
    if (x + 236 > window.innerWidth) x = e.clientX - 236;
    if (y + 260 > window.innerHeight) y = e.clientY - 260;
    card.style.left = x + 'px';
    card.style.top = y + 'px';
  });

  document.addEventListener('mouseout', function (e) {
    if (!activeEl) return;
    var rel = e.relatedTarget;
    if (rel && activeEl.contains(rel)) return;
    activeEl = null;
    card.style.display = 'none';
  });

  document.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('.rotc-player-mfl-link');
    if (!link) return;
    e.preventDefault();
    window.open(link.href, 'rotc_mfl', 'width=1200,height=900,resizable=yes,scrollbars=yes');
  });
})();
</script>
<?php
}
