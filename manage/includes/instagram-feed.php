<?php
/**
 * includes/instagram-feed.php
 * Latest posts from the league's Instagram account (@returnofthechampions)
 * for the front-page sidebar's Instagram tab (templates/free-agent-pulse.php).
 *
 * Uses the official Instagram API with Instagram Login (graph.instagram.com),
 * which needs a Business or Creator account and a long-lived access token.
 * The token is a secret, so it lives in the server's config.php (outside
 * the docroot, never in git):
 *
 *     define('ROTC_INSTAGRAM_TOKEN', 'IGAA...');
 *
 * (or a ROTC_INSTAGRAM_TOKEN env var). Long-lived tokens expire after 60
 * days unless refreshed, so this file refreshes it itself about once a
 * week and keeps the newest token in a small state file next to config.php
 * (falling back to the temp dir if that folder isn't writable). Pasting a
 * NEW token into config.php always wins over the saved one.
 *
 * Facebook-login tokens (starting "EAA") work too, through graph.facebook.com:
 * the Instagram account must be a professional account linked to a Facebook
 * Page. A Page token (from a long-lived user token) never expires; a user
 * token works while it lasts. Those can't be self-refreshed, so the weekly
 * refresh only runs for Instagram-login tokens.
 *
 * Same server-to-server + file-cache pattern as includes/smack-feed.php:
 * the feed is cached for an hour, a failed fetch serves the last good copy
 * (up to 3 days, after which Instagram's signed image URLs have expired
 * anyway), and with no token or no data the tab shows a "Follow us" card
 * instead. Nothing here can take the front page down.
 */

const ROTC_IG_DEFAULT_HANDLE = 'returnofthechampions';
const ROTC_IG_API = 'https://graph.instagram.com';
const ROTC_FB_API = 'https://graph.facebook.com';
const ROTC_IG_FEED_TTL = 3600;            // 1 hour
const ROTC_IG_STALE_MAX = 3 * 86400;      // serve a stale copy for up to 3 days
const ROTC_IG_REFRESH_EVERY = 7 * 86400;  // refresh the token weekly (it lasts 60 days)

function rotc_instagram_handle(): string {
    $h = getenv('ROTC_INSTAGRAM_HANDLE') ?: (defined('ROTC_INSTAGRAM_HANDLE') ? ROTC_INSTAGRAM_HANDLE : ROTC_IG_DEFAULT_HANDLE);
    return ltrim((string) $h, '@');
}

function rotc_instagram_profile_url(): string {
    return 'https://www.instagram.com/' . rawurlencode(rotc_instagram_handle()) . '/';
}

/** Where the refreshed token is kept: beside config.php if writable, else the temp dir. */
function rotc_instagram_state_file(): string {
    $configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/config.php');
    $dir = dirname($configPath);
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir() . '/rotc-wp-cache';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
    }
    return $dir . '/rotc-instagram-token.json';
}

function rotc_instagram_http_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'ROTC-InstagramFeed/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        if ($body !== false) {
            $err = json_decode($body, true);
            // Log the API's message only, never the URL (it carries the token).
            error_log('instagram feed: HTTP ' . $code . ' ' . ($err['error']['message'] ?? ''));
        }
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * The token to use: the saved (refreshed) one, unless config.php has a
 * different token than the one it was refreshed from (a newly pasted token).
 * Refreshes the saved token when it's a week old.
 */
function rotc_instagram_token(): ?string {
    $configured = getenv('ROTC_INSTAGRAM_TOKEN') ?: (defined('ROTC_INSTAGRAM_TOKEN') ? ROTC_INSTAGRAM_TOKEN : '');
    $configured = trim((string) $configured);
    if ($configured === '') return null;

    $stateFile = rotc_instagram_state_file();
    $state = file_exists($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    $source = hash('sha256', $configured);
    if (!is_array($state) || ($state['source'] ?? '') !== $source || empty($state['token'])) {
        // First run, or a new token was pasted into config.php. Don't refresh
        // straight away: Instagram only refreshes tokens at least 24h old.
        $state = ['source' => $source, 'token' => $configured, 'refreshed_at' => time() - ROTC_IG_REFRESH_EVERY + 86400];
        @file_put_contents($stateFile, json_encode($state), LOCK_EX);
        @chmod($stateFile, 0600);
    }

    if (!rotc_instagram_is_fb_token($state['token']) && time() - (int) ($state['refreshed_at'] ?? 0) >= ROTC_IG_REFRESH_EVERY) {
        $fresh = rotc_instagram_http_get(ROTC_IG_API . '/refresh_access_token?grant_type=ig_refresh_token&access_token=' . rawurlencode($state['token']));
        if (!empty($fresh['access_token'])) {
            $state['token'] = $fresh['access_token'];
        }
        // Success or not, wait a day before trying again rather than on every page view.
        $state['refreshed_at'] = !empty($fresh['access_token']) ? time() : time() - ROTC_IG_REFRESH_EVERY + 86400;
        @file_put_contents($stateFile, json_encode($state), LOCK_EX);
        @chmod($stateFile, 0600);
    }
    return $state['token'];
}

function rotc_instagram_is_fb_token(string $token): bool {
    return strncmp($token, 'EAA', 3) === 0;
}

/**
 * For a Facebook-login token: the Instagram account id linked to the Page.
 * A Page token answers /me directly; a user token lists its Pages, and we
 * take the one whose Instagram username matches the handle (else the first
 * Page that has an Instagram account). Cached for a day.
 */
function rotc_instagram_fb_account_id(string $token): ?string {
    $cacheFile = sys_get_temp_dir() . '/rotc-wp-cache/instagram-fbid-' . substr(hash('sha256', $token), 0, 16) . '.txt';
    if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 86400) {
        $id = trim((string) file_get_contents($cacheFile));
        if ($id !== '') return $id;
    }
    $f = 'instagram_business_account{id,username}';
    $id = null;
    $me = rotc_instagram_http_get(ROTC_FB_API . '/me?fields=' . rawurlencode($f) . '&access_token=' . rawurlencode($token));
    if (!empty($me['instagram_business_account']['id'])) {
        $id = (string) $me['instagram_business_account']['id'];
    } else {
        $pages = rotc_instagram_http_get(ROTC_FB_API . '/me/accounts?fields=' . rawurlencode($f) . '&limit=100&access_token=' . rawurlencode($token));
        foreach (($pages['data'] ?? []) as $pg) {
            $ig = $pg['instagram_business_account'] ?? null;
            if (empty($ig['id'])) continue;
            if ($id === null) $id = (string) $ig['id'];
            if (strcasecmp((string) ($ig['username'] ?? ''), rotc_instagram_handle()) === 0) { $id = (string) $ig['id']; break; }
        }
    }
    if ($id === null) {
        error_log('instagram feed: this Facebook token has no Page with a linked Instagram professional account');
        return null;
    }
    @file_put_contents($cacheFile, $id, LOCK_EX);
    return $id;
}

/**
 * Latest posts, newest first, as
 * [['url' => permalink, 'image' => src, 'type' => 'IMAGE'|'VIDEO'|'CAROUSEL_ALBUM', 'caption' => str, 'time' => unix]].
 * Empty when there's no token or nothing could be fetched.
 */
function rotc_fetch_instagram_posts(int $count = 9): array {
    try {
        $cacheDir = sys_get_temp_dir() . '/rotc-wp-cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
        $cacheFile = $cacheDir . '/instagram-' . md5(rotc_instagram_handle() . '|' . $count) . '.json';
        $age = file_exists($cacheFile) ? time() - filemtime($cacheFile) : PHP_INT_MAX;

        if ($age < ROTC_IG_FEED_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $token = rotc_instagram_token();
        $posts = null;
        if ($token !== null) {
            $fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp';
            $q = '/media?fields=' . $fields . '&limit=' . (int) $count . '&access_token=' . rawurlencode($token);
            $data = null;
            if (rotc_instagram_is_fb_token($token)) {
                $igId = rotc_instagram_fb_account_id($token);
                if ($igId !== null) $data = rotc_instagram_http_get(ROTC_FB_API . '/' . rawurlencode($igId) . $q);
            } else {
                $data = rotc_instagram_http_get(ROTC_IG_API . '/me' . $q);
            }
            if (isset($data['data']) && is_array($data['data'])) {
                $posts = [];
                foreach ($data['data'] as $m) {
                    $type = (string) ($m['media_type'] ?? 'IMAGE');
                    $image = $type === 'VIDEO' ? ($m['thumbnail_url'] ?? '') : ($m['media_url'] ?? '');
                    $url = (string) ($m['permalink'] ?? '');
                    // Only Instagram links/images, so a bad payload can't point visitors elsewhere.
                    if ($image === '' || !preg_match('#^https://#', $image) || !preg_match('#^https://(www\.)?instagram\.com/#', $url)) continue;
                    $posts[] = [
                        'url'     => $url,
                        'image'   => $image,
                        'type'    => $type,
                        'caption' => trim((string) ($m['caption'] ?? '')),
                        'time'    => isset($m['timestamp']) ? (int) strtotime($m['timestamp']) : 0,
                    ];
                    if (count($posts) >= $count) break;
                }
                @file_put_contents($cacheFile, json_encode($posts), LOCK_EX);
            }
        }

        if ($posts === null && $age < ROTC_IG_STALE_MAX) {
            $stale = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($stale)) return $stale;
        }
        return $posts ?? [];
    } catch (Throwable $e) {
        error_log('instagram feed: ' . $e->getMessage());
        return [];
    }
}
