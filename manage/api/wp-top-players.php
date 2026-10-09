<?php
/**
 * api/wp-top-players.php
 * Same-origin JSON bridge to the WordPress theme's "Top Players"
 * sidebar widget (wp-content/themes/rotc-theme/template-parts/
 * sidebar-top-players.php) -- the sidebar twin of players/
 * top-performers.php, same data (TYPE=playerScores joined against
 * TYPE=players DETAILS=1) and same hover-card fields as
 * rotc_player_hover_span(), but handed over as plain JSON so the theme
 * can render it with its own markup, same loose-coupling reasoning as
 * api/wp-feed.php.
 *
 * Season totals only (W=YTD) -- a sidebar widget has no room for the
 * week/year pickers the full page offers. Per-position tabs let a
 * reader flip between overall and QB/RB/WR/TE without a page load.
 *
 * Output shape:
 * {
 *   "year": int,
 *   "positions": ["ALL","QB","RB","WR","TE"],
 *   "players": { "ALL": [ <player>, ... ], "QB": [...], ... }   // top 10 each
 * }
 * <player> = { "id","name","position","team","logo","photo","owner",
 *              "score","bio","stats": [[label, value], ...] }
 * Any failure degrades to an empty "players" map, never a 500.
 */

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'config.php not found']);
    exit;
}
require_once $configPath;
require_once __DIR__ . '/../includes/mfl-api.php';
require_once __DIR__ . '/../includes/player-hover.php';

const ROTC_TOP_PLAYERS_LIMIT = 10;
$tabs = ['ALL', 'QB', 'RB', 'WR', 'TE'];
$out = ['year' => (int) MFL_YEAR, 'positions' => $tabs, 'players' => array_fill_keys($tabs, [])];

try {
    // COUNT=300 so every position tab still has 10 entries after the
    // overall list is split up (QBs/TEs sit well below the RB/WR cluster).
    $raw = mfl_cached_get('playerScores', 1800, ['W' => 'YTD', 'COUNT' => 300]);
    $list = mfl_normalize_list($raw['playerScores']['playerScore'] ?? null);
    $list = array_values(array_filter($list, fn($r) => !empty($r['id']) && $r['score'] !== ''));
    usort($list, fn($a, $b) => (float) $b['score'] <=> (float) $a['score']);

    $details = [];
    foreach (array_chunk(array_column($list, 'id'), 250) as $chunk) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) $details[$p['id']] = $p;
    }

    $owners = rotc_owner_map();
    $ranks  = rotc_season_rank_map();
    $byes   = rotc_bye_week_map();

    foreach ($list as $row) {
        $pd = $details[$row['id']] ?? null;
        if (!$pd) continue;
        $pos = $pd['position'] ?? '';
        $keys = in_array($pos, $tabs, true) ? ['ALL', $pos] : ['ALL'];
        $keys = array_filter($keys, fn($k) => count($out['players'][$k]) < ROTC_TOP_PLAYERS_LIMIT);
        if (!$keys) continue;

        $id = (string) $row['id'];
        // Same fields, same order, as rotc_player_hover_span()'s card.
        $stats = [['Fantasy Team', $owners[$id] ?? 'Free Agent']];
        if (isset($ranks[$id])) {
            $stats[] = [(string) MFL_YEAR . ' Total', number_format($ranks[$id]['total'], 2) . ' pts'];
            $stats[] = ['Position Rank', ($pos !== '' ? $pos . ' ' : '') . '#' . $ranks[$id]['rank'] . ' of ' . $ranks[$id]['posCount']];
        }
        $team = $pd['team'] ?? '';
        if ($team !== '' && !empty($byes[$team])) $stats[] = ['Bye Week', (string) $byes[$team]];
        $inj = rotc_injury_detail_text($id);
        if ($inj !== '') $stats[] = ['Status', $inj];

        $entry = [
            'id'       => $id,
            'name'     => $pd['name'] ?? ('Player #' . $id),
            'position' => $pos,
            'team'     => $team,
            'logo'     => rotc_team_logo_url($team),
            'photo'    => rotc_espn_photo($pd),
            'owner'    => $owners[$id] ?? null,
            'score'    => number_format((float) $row['score'], 2),
            'bio'      => implode(' · ', rotc_player_bio_bits($pd)),
            'stats'    => $stats,
        ];
        foreach ($keys as $k) $out['players'][$k][] = $entry;
    }
} catch (Throwable $e) {
    error_log('wp-top-players: ' . $e->getMessage());
}

echo json_encode($out);
