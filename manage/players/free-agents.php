<?php
/**
 * free-agents.php
 * Free agent listing (sortable, paginated), matching Players ->
 * Complete Free Agent Listing on the MFL-hosted page. Uses
 * TYPE=freeAgents (league-scoped, gives just player IDs) joined
 * against:
 *   - TYPE=players               name / position / NFL team / status
 *   - TYPE=nflByeWeeks           bye week per NFL team
 *   - TYPE=nflSchedule (W=cur)   current-week opponent per NFL team
 *   - TYPE=projectedScores (W=cur) current-week projected fantasy points
 *   - TYPE=playerScores (2025 season, W=YTD) prior-year total points
 *
 * Sorting happens across the FULL filtered result set, then the sorted
 * list is paginated -- sorting only the 50 rows on screen would silently
 * lie about who's actually #1 in a given column once you're on page 2+.
 * That means every player row (not just the current page) needs its
 * joined data built before we can sort, so the player lookup below
 * covers the whole free-agent set for the current position filter, not
 * just the current page's 50 ids.
 *
 * NOT included: MFL's own ADD PCT / OWN PCT columns. The only
 * ownership-percentage data the export API exposes is TYPE=topAdds /
 * topOwns, and both are curated "top ~50 most owned/added players
 * league-wide" lists -- by definition almost never free agents. Wiring
 * that in would show 0% for virtually every row, which would misrepresent
 * the data rather than actually reproduce MFL's column. Flagging this
 * rather than faking it.
 */

$page_title = 'Free Agents — Return of the Champions';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$PER_PAGE = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$posFilter = $_GET['pos'] ?? '';
$positions = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S'];

$SORTABLE = [
    'name'     => ['label' => 'Player',   'type' => 'text'],
    'position' => ['label' => 'Pos',      'type' => 'text'],
    'team'     => ['label' => 'NFL Team', 'type' => 'text'],
    'status'   => ['label' => 'Status',   'type' => 'text'],
    'bye'      => ['label' => 'Bye',      'type' => 'num'],
    'opp'      => ['label' => 'Opp',      'type' => 'text'],
    'proj'     => ['label' => 'Proj',     'type' => 'num'],
    'pts2025'  => ['label' => '2025 Pts', 'type' => 'num'],
];
$sort = $_GET['sort'] ?? '';
if (!isset($SORTABLE[$sort])) $sort = '';
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/player-hover.php';

    $params = $posFilter ? ['POSITION' => $posFilter] : [];
    $curYear = (int) MFL_YEAR;
    $faWeek  = rotc_current_nfl_week();
    $SORTABLE['opp']['label']  = 'Wk ' . $faWeek . ' Opp';
    $SORTABLE['proj']['label'] = 'Wk ' . $faWeek . ' Proj';
    mfl_prefetch([
        ['type' => 'freeAgents',      'year' => $curYear,     'ttl' => 900,    'params' => $params],
        ['type' => 'players',         'year' => $curYear,     'ttl' => 43200,  'params' => ['DETAILS' => 1], 'includeLeague' => false],
        ['type' => 'nflByeWeeks',     'year' => $curYear,     'ttl' => 86400,  'params' => [], 'includeLeague' => false],
        ['type' => 'nflSchedule',     'year' => $curYear,     'ttl' => 86400,  'params' => ['W' => $faWeek], 'includeLeague' => false],
        ['type' => 'projectedScores', 'year' => $curYear,     'ttl' => 3600,   'params' => ['W' => $faWeek, 'COUNT' => 3000]],
        ['type' => 'playerScores',    'year' => $curYear - 1, 'ttl' => 604800, 'params' => ['W' => 'YTD', 'COUNT' => 3000]],
        ['type' => 'playerScores',    'year' => $curYear,     'ttl' => 1800,   'params' => ['W' => 'YTD', 'COUNT' => 3000]],
        ['type' => 'rosters',         'year' => $curYear,     'ttl' => 1800,   'params' => []],
        ['type' => 'injuries',        'year' => $curYear,     'ttl' => 1800,   'params' => [], 'includeLeague' => false],
    ]);

    $faRaw = mfl_cached_get('freeAgents', 900, $params); // 15 min -- waiver activity changes this
    $allIds = array_column(mfl_normalize_list($faRaw['freeAgents']['leagueUnit']['player'] ?? null), 'id');
    $total = count($allIds);

    // Resolve all player bios from the shared full-player-DB index (0 extra HTTP calls).
    $players = rotc_resolve_players($allIds, null, true);

    // Bye weeks -- team abbrev -> bye week number.
    $byeByTeam = rotc_bye_week_map();

    // Current-week opponent -- team abbrev -> opponent abbrev.
    $schedRaw = mfl_cached_get('nflSchedule', 86400, ['W' => $faWeek], false);
    $oppByTeam = [];
    foreach (mfl_normalize_list($schedRaw['nflSchedule']['matchup'] ?? null) as $m) {
        $teams = mfl_normalize_list($m['team'] ?? null);
        if (count($teams) === 2) {
            $oppByTeam[$teams[0]['id']] = $teams[1]['id'];
            $oppByTeam[$teams[1]['id']] = $teams[0]['id'];
        }
    }

    // Current-week projected points -- player id -> score.
    $projRaw = mfl_cached_get('projectedScores', 3600, ['W' => $faWeek, 'COUNT' => 3000]);
    $projById = [];
    foreach (mfl_normalize_list($projRaw['projectedScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $projById[$row['id']] = $row['score'] ?? '';
    }

    // Prior-season total points -- cached long since last year's totals never change.
    $prevYearRaw = mfl_cached_get_year('playerScores', $curYear - 1, 604800, ['W' => 'YTD', 'COUNT' => 3000]);
    $prevPtsById = [];
    foreach (mfl_normalize_list($prevYearRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $prevPtsById[$row['id']] = $row['score'] ?? '';
    }

    // Build the full joined row set (whole filtered list, not just this page).
    $allRows = [];
    foreach ($allIds as $id) {
        $p = $players[$id] ?? null;
        if (!$p) continue;
        $team = $p['team'] ?? '';
        $allRows[] = [
            'pd'       => $p,
            'name'     => $p['name'] ?? ('Player #' . $id),
            'position' => $p['position'] ?? '',
            'team'     => $team,
            'status'   => $p['status'] ?? '',
            'bye'      => $byeByTeam[$team] ?? '',
            'opp'      => $oppByTeam[$team] ?? '',
            'proj'     => $projById[$id] ?? '',
            'pts2025'  => $prevPtsById[$id] ?? '',
        ];
    }

    if ($sort !== '') {
        $type = $SORTABLE[$sort]['type'];
        usort($allRows, function ($a, $b) use ($sort, $type, $dir) {
            $av = $a[$sort];
            $bv = $b[$sort];
            if ($type === 'num') {
                // Blank values sort last regardless of direction -- an
                // empty bye/proj/2025-pts isn't "zero", it's "unknown".
                $aBlank = $av === '' || $av === null;
                $bBlank = $bv === '' || $bv === null;
                if ($aBlank && $bBlank) return 0;
                if ($aBlank) return 1;
                if ($bBlank) return -1;
                $cmp = (float) $av <=> (float) $bv;
            } else {
                $cmp = strnatcasecmp((string) $av, (string) $bv);
            }
            return $dir === 'desc' ? -$cmp : $cmp;
        });
    }

    $totalPages = max(1, (int) ceil($total / $PER_PAGE));
    $page = min($page, $totalPages);
    $rows = array_slice($allRows, ($page - 1) * $PER_PAGE, $PER_PAGE);
}

function rotc_qs(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}

function rotc_sort_link(string $col, string $label, string $sort, string $dir): string {
    $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($sort === $col) $arrow = $dir === 'asc' ? ' &uarr;' : ' &darr;';
    $href = rotc_qs(['sort' => $col, 'dir' => $nextDir, 'page' => 1]);
    return '<a href="' . $href . '" style="color:inherit;text-decoration:none;">' . htmlspecialchars($label) . $arrow . '</a>';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Free agent list isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Free Agents</h2>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 16px;">
          <a href="<?= rotc_qs(['pos' => '', 'page' => 1]) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === '' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">All</a>
          <?php foreach ($positions as $pos): ?>
            <a href="<?= rotc_qs(['pos' => $pos, 'page' => 1]) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === $pos ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $pos ?></a>
          <?php endforeach; ?>
        </div>

        <p style="color:var(--muted);font-size:13px;"><?= number_format($total) ?> free agents<?= $posFilter ? ' at ' . htmlspecialchars($posFilter) : '' ?> — page <?= $page ?> of <?= $totalPages ?></p>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr>
            <th></th>
            <?php foreach ($SORTABLE as $col => $meta): ?>
              <th><?= rotc_sort_link($col, $meta['label'], $sort, $dir) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= rotc_team_logo_img($r['team']) ?></td>
                <td><?= rotc_player_hover_span($r['name'], $r['pd'], ['2025 Total' => $r['pts2025'] !== '' ? $r['pts2025'] . ' pts' : '', 'Wk ' . $faWeek . ' Proj' => $r['proj'] !== '' ? $r['proj'] . ' pts' : '', 'Bye Week' => $r['bye'], 'Wk ' . $faWeek . ' Opp' => $r['opp']]) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars($r['bye']) ?></td>
                <td><?= htmlspecialchars($r['opp']) ?></td>
                <td><?= htmlspecialchars($r['proj']) ?></td>
                <td><?= htmlspecialchars($r['pts2025']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="9">No free agents found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>

        <p style="color:var(--muted);font-size:12px;margin-top:10px;">
          Add % / Own % aren't shown — the only ownership data MFL's API exposes (topAdds/topOwns) only covers the ~50 most-owned players league-wide, which by definition excludes free agents.
        </p>

        <div style="display:flex;gap:8px;margin-top:16px;">
          <?php if ($page > 1): ?><a href="<?= rotc_qs(['page' => $page - 1]) ?>">&laquo; Prev</a><?php endif; ?>
          <?php if ($page < $totalPages): ?><a href="<?= rotc_qs(['page' => $page + 1]) ?>">Next &raquo;</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php if (!$fetchError) rotc_player_hover_widget(); ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
