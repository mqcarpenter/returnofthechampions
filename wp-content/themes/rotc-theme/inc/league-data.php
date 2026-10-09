<?php
/**
 * inc/league-data.php
 * Server-side bridge to the fantasy-management app's JSON feed
 * (manage/api/wp-feed.php) -- see that file's doc comment for exactly
 * what it returns and why this goes through one small JSON contract
 * instead of WordPress reaching into that app's HTML/PHP directly.
 *
 * Cached in a transient so a homepage view doesn't cost a fresh HTTP
 * round trip on every single request -- 5 minutes, matching the
 * Cache-Control header wp-feed.php itself sends.
 */

if (!defined('ABSPATH')) exit;

const ROTC_LEAGUE_FEED_TRANSIENT = 'rotc_league_feed';
const ROTC_LEAGUE_FEED_TTL = 300;

/**
 * @return array|null Decoded feed (see manage/api/wp-feed.php's doc
 *   comment for shape), or null if the app is unreachable or the
 *   response wasn't valid JSON -- callers must treat null the same as
 *   "nothing to show" and degrade gracefully, never fatal.
 */
function rotc_theme_get_league_feed(): ?array {
    $cached = get_transient(ROTC_LEAGUE_FEED_TRANSIENT);
    if (is_array($cached)) return $cached;

    // /manage/ is a same-domain subdirectory app, not a separate host --
    // built off home_url() rather than a hardcoded domain so this also
    // works on a staging copy of the site without editing PHP.
    $url = trailingslashit(home_url()) . 'manage/api/wp-feed.php';
    $response = wp_remote_get($url, ['timeout' => 5]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        // Serve a stale copy rather than nothing, same fallback
        // philosophy the source app itself uses for its own MFL calls.
        $stale = get_transient(ROTC_LEAGUE_FEED_TRANSIENT . '_stale');
        return is_array($stale) ? $stale : null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) return null;

    set_transient(ROTC_LEAGUE_FEED_TRANSIENT, $data, ROTC_LEAGUE_FEED_TTL);
    // Kept for a day past its normal TTL purely as an outage fallback --
    // this second transient is intentionally longer-lived than the
    // primary one above.
    set_transient(ROTC_LEAGUE_FEED_TRANSIENT . '_stale', $data, DAY_IN_SECONDS);
    return $data;
}

/**
 * Same feed, for one SPECIFIC past (or current) week -- used by the
 * [rotc_matchup] shortcode embedded in a recap article's body, which
 * needs that particular week's game even after the "current" week (and
 * therefore rotc_theme_get_league_feed()'s result) has moved on.
 * Cached an hour per week requested -- a completed week's result never
 * changes, so this is generous rather than tight, and every post
 * embedding the same week's game shares one cached fetch.
 *
 * @return array|null Same shape as rotc_theme_get_league_feed(), or
 *   null if unreachable -- callers must degrade gracefully.
 */
function rotc_theme_get_week_feed(int $week): ?array {
    if ($week < 1) return null;
    $transientKey = 'rotc_week_feed_' . $week;
    $cached = get_transient($transientKey);
    if (is_array($cached)) return $cached;

    $url = trailingslashit(home_url()) . 'manage/api/wp-feed.php?week=' . $week;
    $response = wp_remote_get($url, ['timeout' => 5]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $stale = get_transient($transientKey . '_stale');
        return is_array($stale) ? $stale : null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) return null;

    set_transient($transientKey, $data, HOUR_IN_SECONDS);
    set_transient($transientKey . '_stale', $data, WEEK_IN_SECONDS);
    return $data;
}

/**
 * Finds one franchise's game within an already-fetched week feed (see
 * rotc_theme_get_week_feed()), by either side's franchise id.
 * @return array|null One entry from the feed's "games" list, or null
 *   if that franchise has no game in this feed (bye week, bad id, or
 *   the feed itself came back empty).
 */
function rotc_theme_find_game(?array $weekFeed, string $teamId): ?array {
    if (!$weekFeed || empty($weekFeed['games'])) return null;
    foreach ($weekFeed['games'] as $game) {
        if ($game['winnerId'] === $teamId || $game['loserId'] === $teamId) return $game;
    }
    return null;
}

/**
 * Top fantasy scorers for the sidebar "Top Players" widget, from
 * manage/api/wp-top-players.php (season totals, top 10 overall and per
 * position, each with the hover-card fields pre-built). Same transient
 * + stale-fallback pattern as rotc_theme_get_league_feed().
 *
 * @return array|null Decoded feed, or null if unreachable / empty --
 *   callers must render nothing in that case, never fatal.
 */
function rotc_theme_get_top_players(): ?array {
    $key = 'rotc_top_players';
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;

    $response = wp_remote_get(trailingslashit(home_url()) . 'manage/api/wp-top-players.php', ['timeout' => 8]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $stale = get_transient($key . '_stale');
        return is_array($stale) ? $stale : null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['players']['ALL'])) return null;

    set_transient($key, $data, ROTC_LEAGUE_FEED_TTL);
    set_transient($key . '_stale', $data, DAY_IN_SECONDS);
    return $data;
}
