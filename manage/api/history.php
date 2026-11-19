<?php
/**
 * api/history.php
 * Read-only JSON view of league history for the ROTC Content Studio (and
 * anything else that wants history as data instead of HTML). Same sources
 * as the history pages, so the numbers match what the site shows:
 *   - rotchist_mfl_* tables via rotchist_db() (the SELECT-only DB user,
 *     see includes/rotchist-db.php) for records, head to head, rivalries,
 *     and team careers. Coverage 2004-2025 per history/index.php.
 *   - includes/hall-of-fame.php for champions (MFL brackets 2017+, the
 *     manual list for 1999-2016).
 *
 * Contract: docs/history-api.md. Every response is
 *   { "version": 1, "section": "...", "generated_at": ISO-8601,
 *     "coverage": {"first_season", "last_season"} | null, "data": ... }
 * or, on failure, { "version": 1, "error": true, "message": "..." } with a
 * 4xx/5xx status. Nothing here writes to the database or calls an MFL
 * import -- GET only.
 *
 * Sections (?section=):
 *   meta                       franchises with ids, coverage
 *   records[&limit=N]          the Records Hub leaderboards (limit 1-50, default 15)
 *   h2h&a=&b=                  two franchises, lifetime series + every meeting
 *   rivalries[&min_games=N]    every pair that has played, with per-side wins
 *   team&f=                    one franchise: career totals + season by season
 *   hall-of-fame               champions, newest first
 *
 * Franchise ids (a, b, f): either a rotchist franchise id ("12") or a
 * current-season MFL franchise id with an "mfl:" prefix ("mfl:0001"),
 * which is what the Content Studio knows.
 */

const ROTC_HISTORY_API_VERSION = 1;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

function rotc_history_fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['version' => ROTC_HISTORY_API_VERSION, 'error' => true, 'message' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    rotc_history_fail(405, 'GET only');
}

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/config.php');
if (file_exists($configPath)) require_once $configPath;
require_once __DIR__ . '/../includes/rotchist-db.php';

$section = (string) ($_GET['section'] ?? 'meta');
$allowed = ['meta', 'records', 'h2h', 'rivalries', 'team', 'hall-of-fame'];
if (!in_array($section, $allowed, true)) {
    rotc_history_fail(400, 'Unknown section. Use one of: ' . implode(', ', $allowed));
}

/** Int query param inside [min, max], or the default. Rejects junk rather than guessing. */
function rotc_history_int(string $key, int $default, int $min, int $max): int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') return $default;
    $v = (string) $_GET[$key];
    if (!ctype_digit($v)) rotc_history_fail(400, "$key must be a whole number");
    $n = (int) $v;
    if ($n < $min || $n > $max) rotc_history_fail(400, "$key must be between $min and $max");
    return $n;
}

/** Cast numeric strings from PDO to real numbers so the JSON carries numbers, not "123.45". */
function rotc_history_numbers(array $rows): array {
    $ints = ['season', 'week', 'is_playoff', 'wins', 'losses', 'ties', 'games', 'last_season', 'first_season', 'id', 'franchise_id'];
    foreach ($rows as &$r) {
        foreach ($r as $k => $v) {
            if ($v === null || !is_string($v) || !is_numeric($v)) continue;
            $r[$k] = in_array($k, $ints, true) ? (int) $v : round((float) $v, 2);
        }
    }
    return $rows;
}

function rotc_history_out(string $section, $data, ?array $coverage): void {
    echo json_encode([
        'version' => ROTC_HISTORY_API_VERSION,
        'section' => $section,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'coverage' => $coverage,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------
// Hall of Fame needs MFL (brackets), not the history DB.
// ---------------------------------------------------------------------
if ($section === 'hall-of-fame') {
    if (!defined('MFL_YEAR')) rotc_history_fail(503, 'config.php not loaded');
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/hall-of-fame.php';
    $franchises = mfl_franchises();
    $rows = [];
    foreach (rotc_hall_of_fame_champions(1999, (int) MFL_YEAR) as $c) {
        $rows[] = [
            'season' => (int) $c['year'],
            'champion' => rotc_hof_resolve_name($c['championId'], $c['championName'], $franchises),
            'champion_mfl_id' => $c['championId'],
            'runner_up' => ($c['runnerUpId'] !== null || $c['runnerUpName'] !== null)
                ? rotc_hof_resolve_name($c['runnerUpId'], $c['runnerUpName'], $franchises) : null,
            'runner_up_mfl_id' => $c['runnerUpId'],
            'final_week' => $c['finalWeek'],
            'champion_points' => $c['finalChampPts'],
            'runner_up_points' => $c['finalRunnerUpPts'],
            'path' => array_map(fn($p) => [
                'week' => $p['week'],
                'opponent' => rotc_hof_team_name((string) $p['opponentId'], $franchises),
                'champion_points' => $p['champPts'],
                'opponent_points' => $p['oppPts'],
                'is_final' => $p['isFinal'],
            ], $c['path']),
            'source' => $c['source'],  // "bracket" (MFL API, 2017+) or "league_page" (manual list)
        ];
    }
    $years = array_column($rows, 'season');
    rotc_history_out($section, $rows, $years ? ['first_season' => min($years), 'last_season' => max($years)] : null);
}

// ---------------------------------------------------------------------
// Everything else reads the rotchist_ history database.
// ---------------------------------------------------------------------
$db = rotchist_db();
if (!$db) rotc_history_fail(503, 'History database not configured or not reachable');

try {
    $cov = $db->query("
        SELECT MIN(season) AS first_season, MAX(season) AS last_season FROM rotchist_mfl_games
        WHERE franchise1_score > 0 OR franchise2_score > 0
    ")->fetch();
    $coverage = $cov && $cov['last_season'] !== null
        ? ['first_season' => (int) $cov['first_season'], 'last_season' => (int) $cov['last_season']]
        : null;
    $lastSeason = $coverage['last_season'] ?? null;

    // Stable franchise id -> current name, and -> MFL id in the latest season on record.
    $names = [];
    foreach ($db->query("SELECT id, current_name FROM rotchist_franchises")->fetchAll() as $f) {
        $names[(int) $f['id']] = $f['current_name'];
    }
    $mflByFranchise = [];
    $franchiseByMfl = [];
    $mapSeason = $lastSeason ?? (int) $db->query("SELECT MAX(season) FROM rotchist_mfl_franchises")->fetchColumn();
    if ($mapSeason) {
        $stmt = $db->prepare("SELECT franchise_id, mfl_franchise_id FROM rotchist_mfl_franchises
                              WHERE season = :s AND franchise_id IS NOT NULL");
        $stmt->execute(['s' => $mapSeason]);
        foreach ($stmt->fetchAll() as $r) {
            $mflByFranchise[(int) $r['franchise_id']] = $r['mfl_franchise_id'];
            $franchiseByMfl[$r['mfl_franchise_id']] = (int) $r['franchise_id'];
        }
    }

    /** "12" or "mfl:0001" -> rotchist franchise id, or a 400. */
    $resolve = function (string $key) use ($names, $franchiseByMfl): int {
        $raw = (string) ($_GET[$key] ?? '');
        if ($raw === '') rotc_history_fail(400, "$key is required");
        if (strncmp($raw, 'mfl:', 4) === 0) {
            $mfl = substr($raw, 4);
            if (!preg_match('/^\d{4}$/', $mfl)) rotc_history_fail(400, "$key: MFL ids look like mfl:0001");
            if (!isset($franchiseByMfl[$mfl])) rotc_history_fail(404, "$key: no franchise with MFL id $mfl");
            return $franchiseByMfl[$mfl];
        }
        if (!ctype_digit($raw)) rotc_history_fail(400, "$key must be a franchise id or mfl:NNNN");
        if (!isset($names[(int) $raw])) rotc_history_fail(404, "$key: no franchise $raw");
        return (int) $raw;
    };

    $teamName = "COALESCE(rf.current_name, mf.franchise_name, 'Unknown')";

    switch ($section) {
    case 'meta':
        $rows = [];
        foreach ($names as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name, 'mfl_id' => $mflByFranchise[$id] ?? null];
        }
        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
        rotc_history_out($section, ['franchises' => $rows, 'mfl_id_season' => $mapSeason ?: null], $coverage);

    case 'records':
        $limit = rotc_history_int('limit', 15, 1, 50);
        $sides = "
            SELECT id, season, week, is_playoff, franchise1_mfl_id AS mfl_id, franchise1_id AS fid,
                   franchise1_score AS score, franchise1_result AS result FROM rotchist_mfl_games
            UNION ALL
            SELECT id, season, week, is_playoff, franchise2_mfl_id, franchise2_id,
                   franchise2_score, franchise2_result FROM rotchist_mfl_games";
        $single = "
            SELECT g.season, g.week, g.is_playoff, side.score, side.result, $teamName AS team_name
            FROM rotchist_mfl_games g
            JOIN ($sides) side ON side.id = g.id AND side.season = g.season AND side.week = g.week
            LEFT JOIN rotchist_franchises rf ON rf.id = side.fid
            LEFT JOIN rotchist_mfl_franchises mf ON mf.season = side.season AND mf.mfl_franchise_id = side.mfl_id
            WHERE side.score IS NOT NULL";
        $pair = "
            SELECT g.season, g.week, g.is_playoff,
                   COALESCE(rf1.current_name, mf1.franchise_name, 'Unknown') AS team1, g.franchise1_score AS score1,
                   COALESCE(rf2.current_name, mf2.franchise_name, 'Unknown') AS team2, g.franchise2_score AS score2,
                   ABS(g.franchise1_score - g.franchise2_score) AS margin,
                   (g.franchise1_score + g.franchise2_score) AS combined
            FROM rotchist_mfl_games g
            LEFT JOIN rotchist_franchises rf1 ON rf1.id = g.franchise1_id
            LEFT JOIN rotchist_mfl_franchises mf1 ON mf1.season = g.season AND mf1.mfl_franchise_id = g.franchise1_mfl_id
            LEFT JOIN rotchist_franchises rf2 ON rf2.id = g.franchise2_id
            LEFT JOIN rotchist_mfl_franchises mf2 ON mf2.season = g.season AND mf2.mfl_franchise_id = g.franchise2_mfl_id
            WHERE g.franchise1_score IS NOT NULL AND g.franchise2_score IS NOT NULL";
        // $bySeason adds the season column, for single-season leaderboards.
        $career = fn(string $where, bool $bySeason = false) => "
            SELECT " . ($bySeason ? "side.season, " : "") . "side.fid AS franchise_id, COALESCE(rf.current_name, 'Unknown') AS team_name,
                   SUM(CASE WHEN side.result = 'W' THEN 1 ELSE 0 END) AS wins,
                   SUM(CASE WHEN side.result = 'L' THEN 1 ELSE 0 END) AS losses,
                   SUM(CASE WHEN side.result = 'T' THEN 1 ELSE 0 END) AS ties,
                   ROUND(SUM(side.score), 2) AS points
            FROM (
                SELECT season, is_playoff, franchise1_id AS fid, franchise1_result AS result, franchise1_score AS score
                  FROM rotchist_mfl_games WHERE franchise1_score IS NOT NULL
                UNION ALL
                SELECT season, is_playoff, franchise2_id, franchise2_result, franchise2_score
                  FROM rotchist_mfl_games WHERE franchise2_score IS NOT NULL
            ) side
            JOIN rotchist_franchises rf ON rf.id = side.fid
            WHERE $where";
        $q = fn(string $sql) => rotc_history_numbers($db->query($sql . " LIMIT $limit")->fetchAll());
        rotc_history_out($section, [
            'single_game' => [
                'most_points' => $q("$single ORDER BY side.score DESC"),
                'fewest_points' => $q("$single ORDER BY side.score ASC"),
                'most_in_loss' => $q("$single AND side.result = 'L' ORDER BY side.score DESC"),
                'fewest_in_win' => $q("$single AND side.result = 'W' ORDER BY side.score ASC"),
                'most_combined' => $q("$pair ORDER BY combined DESC"),
                'closest_games' => $q("$pair AND g.franchise1_result <> 'T' ORDER BY margin ASC"),
                'biggest_blowouts' => $q("$pair ORDER BY margin DESC"),
            ],
            'season' => [
                'most_wins' => $q($career('side.is_playoff = 0', true) . " GROUP BY side.season, side.fid ORDER BY wins DESC, points DESC"),
                'most_points' => $q($career('1=1', true) . " GROUP BY side.season, side.fid ORDER BY points DESC"),
            ],
            'career' => [
                'most_wins' => $q($career('1=1') . " GROUP BY side.fid ORDER BY wins DESC"),
                'most_points' => $q($career('1=1') . " GROUP BY side.fid ORDER BY points DESC"),
            ],
            'postseason' => [
                'most_wins' => $q($career('side.is_playoff = 1') . " GROUP BY side.fid ORDER BY wins DESC"),
                'top_games' => $q("$single AND g.is_playoff = 1 ORDER BY side.score DESC"),
            ],
            'milestones' => [
                'double_century' => rotc_history_numbers(
                    $db->query("$single AND side.score >= 200 ORDER BY side.score DESC")->fetchAll()),
            ],
            'players' => [
                'top_starter_games' => $q("
                    SELECT pg.season, pg.week, p.name AS player_name, p.position, p.nfl_team,
                           $teamName AS fantasy_team, pg.score
                    FROM rotchist_mfl_player_games pg
                    JOIN rotchist_mfl_players p ON p.id = pg.player_id
                    LEFT JOIN rotchist_franchises rf ON rf.id = pg.franchise_id
                    LEFT JOIN rotchist_mfl_franchises mf ON mf.season = pg.season AND mf.mfl_franchise_id = pg.franchise_mfl_id
                    WHERE pg.status = 'starter' ORDER BY pg.score DESC"),
                'top_bench_games' => $q("
                    SELECT pg.season, pg.week, p.name AS player_name, p.position, p.nfl_team,
                           $teamName AS fantasy_team, pg.score
                    FROM rotchist_mfl_player_games pg
                    JOIN rotchist_mfl_players p ON p.id = pg.player_id
                    LEFT JOIN rotchist_franchises rf ON rf.id = pg.franchise_id
                    LEFT JOIN rotchist_mfl_franchises mf ON mf.season = pg.season AND mf.mfl_franchise_id = pg.franchise_mfl_id
                    WHERE pg.status = 'nonstarter' ORDER BY pg.score DESC"),
            ],
            'limit' => $limit,
        ], $coverage);

    case 'h2h':
        $a = $resolve('a');
        $b = $resolve('b');
        if ($a === $b) rotc_history_fail(400, 'a and b must be different franchises');
        $stmt = $db->prepare("
            SELECT season, week, is_playoff, franchise1_id, franchise1_score, franchise2_id, franchise2_score
            FROM rotchist_mfl_games
            WHERE ((franchise1_id = :a AND franchise2_id = :b) OR (franchise1_id = :b2 AND franchise2_id = :a2))
              AND franchise1_score IS NOT NULL AND franchise2_score IS NOT NULL
            ORDER BY season, week");
        $stmt->execute(['a' => $a, 'b' => $b, 'a2' => $a, 'b2' => $b]);
        $meetings = [];
        $aw = $bw = $t = 0;
        $ap = $bp = 0.0;
        $pa = $pb = 0;
        foreach ($stmt->fetchAll() as $g) {
            $aIsF1 = (int) $g['franchise1_id'] === $a;
            $as = (float) ($aIsF1 ? $g['franchise1_score'] : $g['franchise2_score']);
            $bs = (float) ($aIsF1 ? $g['franchise2_score'] : $g['franchise1_score']);
            $winner = $as > $bs ? 'a' : ($bs > $as ? 'b' : null);
            if ($winner === 'a') { $aw++; if ($g['is_playoff']) $pa++; }
            elseif ($winner === 'b') { $bw++; if ($g['is_playoff']) $pb++; }
            else $t++;
            $ap += $as;
            $bp += $bs;
            $meetings[] = [
                'season' => (int) $g['season'], 'week' => (int) $g['week'], 'is_playoff' => (bool) $g['is_playoff'],
                'a_score' => round($as, 2), 'b_score' => round($bs, 2),
                'margin' => round(abs($as - $bs), 2), 'winner' => $winner,
            ];
        }
        // Current streak, newest meeting backward (same rule as history/index.php).
        $streakTeam = null;
        $streakLen = 0;
        for ($i = count($meetings) - 1; $i >= 0; $i--) {
            $w = $meetings[$i]['winner'];
            if ($i === count($meetings) - 1) { $streakTeam = $w; $streakLen = $w ? 1 : 0; continue; }
            if ($w !== null && $w === $streakTeam) $streakLen++; else break;
        }
        $n = count($meetings);
        rotc_history_out($section, [
            'a' => ['id' => $a, 'name' => $names[$a], 'mfl_id' => $mflByFranchise[$a] ?? null],
            'b' => ['id' => $b, 'name' => $names[$b], 'mfl_id' => $mflByFranchise[$b] ?? null],
            'games' => $n,
            'a_wins' => $aw, 'b_wins' => $bw, 'ties' => $t,
            'a_points' => round($ap, 2), 'b_points' => round($bp, 2),
            'avg_margin' => $n ? round(array_sum(array_column($meetings, 'margin')) / $n, 2) : null,
            'playoff_meetings' => count(array_filter($meetings, fn($m) => $m['is_playoff'])),
            'a_playoff_wins' => $pa, 'b_playoff_wins' => $pb,
            'streak' => $streakTeam ? ['team' => $streakTeam, 'length' => $streakLen] : null,
            'last_meeting' => $n ? $meetings[$n - 1] : null,
            'meetings' => array_reverse($meetings),  // newest first
        ], $coverage);

    case 'rivalries':
        $minGames = rotc_history_int('min_games', 1, 1, 200);
        $stmt = $db->prepare("
            SELECT LEAST(franchise1_id, franchise2_id) AS fa, GREATEST(franchise1_id, franchise2_id) AS fb,
                   COUNT(*) AS games,
                   SUM(CASE WHEN (franchise1_id < franchise2_id AND franchise1_score > franchise2_score)
                              OR (franchise2_id < franchise1_id AND franchise2_score > franchise1_score) THEN 1 ELSE 0 END) AS fa_wins,
                   SUM(CASE WHEN (franchise1_id > franchise2_id AND franchise1_score > franchise2_score)
                              OR (franchise2_id > franchise1_id AND franchise2_score > franchise1_score) THEN 1 ELSE 0 END) AS fb_wins,
                   SUM(CASE WHEN franchise1_score = franchise2_score THEN 1 ELSE 0 END) AS ties,
                   ROUND(AVG(ABS(franchise1_score - franchise2_score)), 2) AS avg_margin,
                   SUM(is_playoff) AS playoff_games,
                   MAX(season) AS last_season
            FROM rotchist_mfl_games
            WHERE franchise1_id IS NOT NULL AND franchise2_id IS NOT NULL
              AND franchise1_score IS NOT NULL AND franchise2_score IS NOT NULL
            GROUP BY fa, fb
            HAVING COUNT(*) >= :min
            ORDER BY games DESC, avg_margin ASC");
        $stmt->bindValue(':min', $minGames, PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $fa = (int) $r['fa'];
            $fb = (int) $r['fb'];
            $rows[] = [
                'a' => ['id' => $fa, 'name' => $names[$fa] ?? null, 'mfl_id' => $mflByFranchise[$fa] ?? null],
                'b' => ['id' => $fb, 'name' => $names[$fb] ?? null, 'mfl_id' => $mflByFranchise[$fb] ?? null],
                'games' => (int) $r['games'],
                'a_wins' => (int) $r['fa_wins'], 'b_wins' => (int) $r['fb_wins'], 'ties' => (int) $r['ties'],
                'avg_margin' => (float) $r['avg_margin'],
                'playoff_games' => (int) $r['playoff_games'],
                'last_season' => (int) $r['last_season'],
            ];
        }
        rotc_history_out($section, $rows, $coverage);

    case 'team':
        $f = $resolve('f');
        $stmt = $db->prepare("
            SELECT season,
                   SUM(CASE WHEN is_playoff = 0 AND result = 'W' THEN 1 ELSE 0 END) AS wins,
                   SUM(CASE WHEN is_playoff = 0 AND result = 'L' THEN 1 ELSE 0 END) AS losses,
                   SUM(CASE WHEN is_playoff = 0 AND result = 'T' THEN 1 ELSE 0 END) AS ties,
                   ROUND(SUM(CASE WHEN is_playoff = 0 THEN score ELSE 0 END), 2) AS points,
                   ROUND(MAX(score), 2) AS high_score,
                   SUM(CASE WHEN is_playoff = 1 AND result = 'W' THEN 1 ELSE 0 END) AS playoff_wins,
                   SUM(CASE WHEN is_playoff = 1 AND result = 'L' THEN 1 ELSE 0 END) AS playoff_losses
            FROM (
                SELECT season, is_playoff, franchise1_result AS result, franchise1_score AS score
                  FROM rotchist_mfl_games WHERE franchise1_id = :f AND franchise1_score IS NOT NULL
                UNION ALL
                SELECT season, is_playoff, franchise2_result, franchise2_score
                  FROM rotchist_mfl_games WHERE franchise2_id = :f2 AND franchise2_score IS NOT NULL
            ) side
            GROUP BY season ORDER BY season");
        $stmt->execute(['f' => $f, 'f2' => $f]);
        $seasons = rotc_history_numbers($stmt->fetchAll());
        $sum = fn(string $k) => array_sum(array_column($seasons, $k));
        $best = $seasons;
        usort($best, fn($x, $y) => [$y['wins'], $y['points']] <=> [$x['wins'], $x['points']]);
        rotc_history_out($section, [
            'franchise' => ['id' => $f, 'name' => $names[$f], 'mfl_id' => $mflByFranchise[$f] ?? null],
            'career' => [
                'seasons' => count($seasons),
                'wins' => $sum('wins'), 'losses' => $sum('losses'), 'ties' => $sum('ties'),
                'points' => round($sum('points'), 2),
                'playoff_wins' => $sum('playoff_wins'), 'playoff_losses' => $sum('playoff_losses'),
                'note' => 'Regular season totals. Playoff wins and losses are separate.',
            ],
            'best_season' => $best[0] ?? null,
            'seasons' => $seasons,
        ], $coverage);
    }
} catch (Throwable $e) {
    // Never echo SQL or driver detail to the public.
    error_log('api/history.php: ' . $e->getMessage());
    rotc_history_fail(500, 'History query failed');
}
