# History JSON API (`/manage/api/history.php`)

Read-only league history as JSON. Built for the ROTC Content Studio, which
used to have only the HTML history pages to work from. Same data sources as
`history/index.php` and `history/hall-of-fame.php`, so the numbers match the
site.

- GET only. Public, like the history pages. `Cache-Control: public, max-age=3600`.
- Reads the `rotchist_` database through `rotchist_db()` (the SELECT-only
  user). Hall of Fame reads MFL through `includes/hall-of-fame.php`.
- Database coverage is 2004-2025 (MFL has no record of 2003). Career totals
  are a floor for franchises that played in 2003.

## Envelope

```json
{ "version": 1, "section": "h2h", "generated_at": "2026-11-19T14:00:00Z",
  "coverage": { "first_season": 2004, "last_season": 2025 }, "data": { } }
```

Errors: `{ "version": 1, "error": true, "message": "..." }` with status
400 (bad parameter), 404 (unknown franchise), 405 (not GET),
500 (query failed, no detail exposed), or 503 (database or config missing).

## Franchise ids

`a`, `b`, and `f` take either a stable history id (`12`) or a current-season
MFL franchise id with a prefix (`mfl:0001`). The MFL id map uses the latest
season in the database (`meta.data.mfl_id_season`).

## Sections

| Request | Returns |
|---|---|
| `?section=meta` | `franchises: [{id, name, mfl_id}]`, `mfl_id_season` |
| `?section=records&limit=15` | Records Hub leaderboards. `limit` 1-50. Groups: `single_game` (most_points, fewest_points, most_in_loss, fewest_in_win, most_combined, closest_games, biggest_blowouts), `season` (most_wins, most_points), `career` (most_wins, most_points), `postseason` (most_wins, top_games), `milestones` (double_century, not limited), `players` (top_starter_games, top_bench_games) |
| `?section=h2h&a=mfl:0004&b=mfl:0014` | `a`, `b`, `games`, `a_wins`, `b_wins`, `ties`, `a_points`, `b_points`, `avg_margin`, `playoff_meetings`, `a_playoff_wins`, `b_playoff_wins`, `streak: {team: "a"\|"b", length}`, `last_meeting`, `meetings` (newest first: season, week, is_playoff, a_score, b_score, margin, winner) |
| `?section=rivalries&min_games=5` | Every pair that has played: `a`, `b`, `games`, `a_wins`, `b_wins`, `ties`, `avg_margin`, `playoff_games`, `last_season`. Most games first |
| `?section=team&f=mfl:0001` | `franchise`, `career` (regular-season W-L-T and points, playoff W-L), `best_season`, `seasons` (per season: wins, losses, ties, points, high_score, playoff_wins, playoff_losses) |
| `?section=hall-of-fame` | Champions newest first: season, champion, runner_up, MFL ids (2017+), final_week, champion_points, runner_up_points, path, source (`bracket` = MFL API 2017+, `league_page` = manual list 1999-2016) |

## Rules kept from the history pages

- A season counts once a game in it has a non-zero score (unplayed 0-0 placeholders are ignored).
- Team names use `rotchist_franchises.current_name`, falling back to the season's MFL name.
- Season leaderboards: `most_wins` is regular season only; `most_points` includes playoff games, as on the Records Hub.
- Closest games skip ties.

## Testing

`api/history.php` was linted with `php -l` and run against a throwaway SQLite copy
of the tables it reads (synthetic rows) to check every section and error path.
It has not been run against the live MySQL database before deploy. After the
first deploy, check each section in a browser:
`/manage/api/history.php?section=meta`, `...=records&limit=3`,
`...=h2h&a=mfl:0004&b=mfl:0014`, `...=rivalries&min_games=5`,
`...=team&f=mfl:0001`, `...=hall-of-fame`.
