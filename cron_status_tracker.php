<?php
/**
 * Courier Center — Status Tracker Cron Script
 *
 * Τρέξε αυτό το script κάθε 2 ώρες μέσω cron (ή Task Scheduler στα Windows).
 *
 * Linux cron (στον VPS):
 *   0 *\/2 * * * php /var/www/html/opencart4/extension/couriercenter/cron_status_tracker.php
 *
 * Windows Task Scheduler (τοπικά για testing):
 *   Action: php.exe C:\xampp\htdocs\opencart4\extension\couriercenter\cron_status_tracker.php
 *   Trigger: Every 2 hours
 *
 * Για manual test:
 *   php C:\xampp\htdocs\opencart4\extension\couriercenter\cron_status_tracker.php
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
// Αν το script βρίσκεται σε extension/couriercenter/, τότε το OpenCart root
// είναι δύο επίπεδα πάνω.
$oc_root = realpath(__DIR__ . '/../../');

if (!$oc_root || !is_file($oc_root . '/admin/config.php')) {
    die("ERROR: Δεν βρέθηκε το OpenCart root / admin/config.php. Έλεγξε το path.\n");
}

// admin/config.php defines all DB_* and DIR_* constants we need.
require_once $oc_root . '/admin/config.php';

if (!defined('DIR_SYSTEM')) {
    die("ERROR: Το OpenCart δεν φαίνεται εγκατεστημένο (λείπουν constants).\n");
}

require_once DIR_SYSTEM . 'startup.php';

// Register the autoloader (startup.php only requires the class, framework.php
// normally instantiates it — for a standalone CLI script we do it ourselves).
$autoloader = new \Opencart\System\Engine\Autoloader();
$autoloader->register('Opencart\System', DIR_SYSTEM);
$autoloader->register('Opencart\Extension', DIR_EXTENSION);

// ── DB ───────────────────────────────────────────────────────────────────────
$db = new \Opencart\System\Library\DB(
    DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT
);

// ── Φόρτωση CCApi ────────────────────────────────────────────────────────────
require_once DIR_EXTENSION . 'couriercenter/library/CCApi.php';

$api = new \Opencart\Extension\Couriercenter\Library\CCApi(
    getSetting($db, 'shipping_courier_center_user_alias'),
    getSetting($db, 'shipping_courier_center_credential_value'),
    getSetting($db, 'shipping_courier_center_api_key'),
    getSetting($db, 'shipping_courier_center_billing_account')
);

// ── Σταθερές ─────────────────────────────────────────────────────────────────
$final_codes  = ['29', '25', '99', '14', '87', '95'];
$min_interval = 3600; // 60 λεπτά μεταξύ checks ανά αποστολή

// ── Εκτέλεση ─────────────────────────────────────────────────────────────────
$rows = $db->query(
    "SELECT * FROM `" . DB_PREFIX . "cc_shipments`
     WHERE `is_voided` = 0
       AND `is_final`  = 0
       AND `voucher_number` != ''"
);

$checked = 0;
$updated = 0;

foreach ($rows->rows as $shipment) {
    $last_check = (int)$shipment['last_checked_at'];

    // Παράλειψη αν ελέγχθηκε πρόσφατα
    if ($last_check > 0 && (time() - $last_check) < $min_interval) {
        echo "  SKIP  order #{$shipment['order_id']} (checked " . round((time() - $last_check) / 60) . " min ago)\n";
        continue;
    }

    $checked++;
    $result = $api->get_shipment_details($shipment['voucher_number']);

    // Αποτυχία API call
    if (isset($result['success']) && $result['success'] === false) {
        echo "  ERROR order #{$shipment['order_id']}: " . $result['error'] . "\n";
        continue;
    }

    $code  = (string)($result['StatusCode']        ?? $result['DeliveryStatus'] ?? '');
    $desc  = (string)($result['StatusDescription'] ?? $result['DeliveryStatusDescription'] ?? '');
    $final = in_array($code, $final_codes) ? 1 : 0;

    $db->query(
        "UPDATE `" . DB_PREFIX . "cc_shipments`
         SET `status_code`      = '" . $db->escape($code) . "',
             `status_desc`      = '" . $db->escape($desc) . "',
             `is_final`         = $final,
             `last_checked_at`  = " . time() . "
         WHERE `order_id` = " . (int)$shipment['order_id']
    );

    // Order history note + auto-complete on delivery (only when status changed).
    if ($code !== '' && $code !== (string)($shipment['status_code'] ?? '')) {
        cc_add_order_history($db, (int)$shipment['order_id'], '📍 [Cron] Courier Center status: ' . $code . ' — ' . $desc);
        if (in_array($code, ['29', '87'], true)) {
            $auto_status = (int)getSetting($db, 'shipping_courier_center_auto_complete_status_id');
            if ($auto_status > 0) {
                cc_add_order_history($db, (int)$shipment['order_id'], '🚚 [Cron] Παραδόθηκε — αυτόματη ολοκλήρωση παραγγελίας.', $auto_status);
            }
        }
    }

    $updated++;
    $final_label = $final ? ' [FINAL]' : '';
    echo "  UPDATE order #{$shipment['order_id']}: $code — $desc$final_label\n";
}

$timestamp = date('Y-m-d H:i:s');

// Record run timestamps so the admin settings page can display cron health.
setSetting($db, 'shipping_courier_center_cron_last_run', $timestamp);
setSetting($db, 'shipping_courier_center_cron_next_run', date('Y-m-d H:i:s', time() + 7200));

// Usage heartbeat to the Courier Center dashboard (throttled to once / 12h).
cc_send_ping($db);

echo "\n[$timestamp] Ολοκλήρωση: checked=$checked, updated=$updated\n";

// ── Helpers ──────────────────────────────────────────────────────────────────
function getSetting(\Opencart\System\Library\DB $db, string $key): string {
    $q = $db->query(
        "SELECT `value` FROM `" . DB_PREFIX . "setting`
         WHERE `key` = '" . $db->escape($key) . "' LIMIT 1"
    );
    return $q->row['value'] ?? '';
}

/**
 * Append a note to an order's History tab (mirrors the admin controller helper).
 * If $new_status_id > 0 and differs from the current status, also changes the order status.
 */
function cc_add_order_history(\Opencart\System\Library\DB $db, int $order_id, string $comment, int $new_status_id = 0): void {
    if ($order_id <= 0) return;

    $cur = $db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
    if (!$cur->num_rows) return;

    $current   = (int)$cur->row['order_status_id'];
    $status_id = $new_status_id > 0 ? $new_status_id : $current;
    if ($status_id <= 0) return;

    $db->query(
        "INSERT INTO `" . DB_PREFIX . "order_history` SET
            `order_id`='" . (int)$order_id . "', `order_status_id`='" . (int)$status_id . "',
            `notify`='0', `comment`='" . $db->escape($comment) . "', `date_added`=NOW()"
    );

    if ($new_status_id > 0 && $new_status_id !== $current) {
        $db->query("UPDATE `" . DB_PREFIX . "order` SET `order_status_id`='" . (int)$new_status_id . "', `date_modified`=NOW() WHERE `order_id`='" . (int)$order_id . "'");
    }
}

/** Fire-and-forget usage ping to the Courier Center dashboard (throttled 12h). */
function cc_send_ping(\Opencart\System\Library\DB $db, bool $force = false): void {
    try {
        $last = (int)getSetting($db, 'shipping_courier_center_ping_sent_at');
        if (!$force && $last > 0 && (time() - $last) < 43200) {
            return;
        }

        $version = '1.0.0';
        $f = DIR_EXTENSION . 'couriercenter/install.json';
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            if (!empty($j['version'])) $version = (string)$j['version'];
        }

        $payload = [
            'site_url'         => defined('HTTP_CATALOG') ? HTTP_CATALOG : '',
            'platform'         => 'OpenCart',
            'opencart_version' => defined('VERSION') ? VERSION : 'N/A',
            'plugin_version'   => $version,
            'php_version'      => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ];

        $ch = curl_init('https://courier-center-dashboard.onrender.com/api/ping');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        curl_close($ch);

        setSetting($db, 'shipping_courier_center_ping_sent_at', (string)time());
    } catch (\Throwable $e) {
        // Telemetry must never break the cron.
    }
}

function setSetting(\Opencart\System\Library\DB $db, string $key, string $value): void {
    $exists = $db->query(
        "SELECT `setting_id` FROM `" . DB_PREFIX . "setting`
         WHERE `store_id` = '0' AND `key` = '" . $db->escape($key) . "' LIMIT 1"
    );
    if ($exists->num_rows) {
        $db->query(
            "UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $db->escape($value) . "'
             WHERE `store_id` = '0' AND `key` = '" . $db->escape($key) . "'"
        );
    } else {
        $db->query(
            "INSERT INTO `" . DB_PREFIX . "setting`
             SET `store_id` = '0', `code` = 'shipping_courier_center', `key` = '" . $db->escape($key) . "',
                 `value` = '" . $db->escape($value) . "', `serialized` = '0'"
        );
    }
}
