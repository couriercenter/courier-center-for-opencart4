<?php
/**
 * "Cron without a cron job" (the equivalent of WordPress's WP-Cron, which the WooCommerce plugin relies on):
 * when a visitor / admin page is served and the status tracker has not run for ~2 hours, the page fires one
 * short, non-blocking request to the extension's own cron URL (secret key) and goes on. The cron endpoint runs
 * to the end after the page has been served (ignore_user_abort). A real cron job (CLI or URL) still works
 * and simply keeps the tracker fresh, so this never fires. Shared by OpenCart 3 and 4, PHP 7.2+.
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCPseudoCron {

    /** The tracker runs every 2 hours. */
    const INTERVAL = 7200;
    /** Wait this much longer before firing, so an external cron every 2 hours is never doubled. */
    const SLACK = 300;
    /** After a trigger, do not fire again for this long (the run itself takes a while). */
    const RETRY = 900;

    /**
     * @param int  $now       current time
     * @param int  $last_run  unix time of the last finished run (0 = never)
     * @param int  $last_fire unix time of the last trigger (0 = never)
     * @param bool $enabled   the setting "automatic status updates without a cron job"
     */
    public static function due(int $now, int $last_run, int $last_fire, bool $enabled): bool {
        if (!$enabled) {
            return false;
        }
        if ($last_run > 0 && ($now - $last_run) < self::INTERVAL + self::SLACK) {
            return false;
        }
        return $last_fire <= 0 || ($now - $last_fire) >= self::RETRY;
    }

    /**
     * One GET that is not waited for. Redirects (http → https) are followed; every error is ignored.
     * @return bool true when the request was sent (not that it succeeded)
     */
    public static function fire(string $url): bool {
        if ($url === '' || !function_exists('curl_init') || strpos($url, 'http') !== 0) {
            return false;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_CONNECTTIMEOUT_MS => 800,
            CURLOPT_TIMEOUT_MS     => 1200,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'CourierCenter-PseudoCron',
        ]);
        curl_exec($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        return true;
    }
}
