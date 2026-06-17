<?php
/**
 * Courier Center — idempotent setup / repair script.
 *
 * (Re)registers ALL OpenCart events and grants admin permissions for every
 * Courier Center route, WITHOUT dropping any data. Safe to run any number of
 * times. Use it after pulling a new version that adds events/routes, or to
 * repair a broken install.
 *
 * Run once from the command line (XAMPP/MySQL must be running):
 *   php C:\xampp\htdocs\opencart4\extension\couriercenter\setup.php
 *
 * (CLI only — it refuses to run over the web for safety.)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This setup script can only be run from the command line.\n");
}

$root = realpath(__DIR__ . '/../../');
if (!$root || !is_file($root . '/admin/config.php')) {
    die("ERROR: Could not locate OpenCart root (admin/config.php).\n");
}
require $root . '/admin/config.php';

try {
    $db = new PDO(
        'mysql:host=' . DB_HOSTNAME . ';port=' . DB_PORT . ';dbname=' . DB_DATABASE . ';charset=utf8',
        DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) {
    die("ERROR: Cannot connect to MySQL (is XAMPP running?). " . $e->getMessage() . "\n");
}

// ── Extension registration + database table ──────────────────────────────────
$er = $db->prepare("SELECT extension_id FROM `" . DB_PREFIX . "extension` WHERE type='shipping' AND code='courier_center' LIMIT 1");
$er->execute();
if (!$er->fetch()) {
    $db->prepare("INSERT INTO `" . DB_PREFIX . "extension` SET `extension`='couriercenter', `type`='shipping', `code`='courier_center'")->execute();
    echo "  extension registered in " . DB_PREFIX . "extension\n";
} else {
    echo "  extension already registered\n";
}

$sqlfile = __DIR__ . '/install.sql';
if (is_file($sqlfile)) {
    $sql = (string)file_get_contents($sqlfile);
    // install.sql hardcodes the "oc_" prefix — rewrite it to the real prefix.
    $sql = str_replace('`oc_', '`' . DB_PREFIX, $sql);
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
        if ($stmt === '') continue;
        try { $db->exec($stmt); } catch (\Throwable $e) { /* CREATE ... IF NOT EXISTS — safe to ignore */ }
    }
    echo "  database table ensured (" . DB_PREFIX . "cc_shipments)\n";
}

// ── Events ───────────────────────────────────────────────────────────────────
$events = [
    ['courier_center_order_panel',   'Courier Center — panel στη σελίδα παραγγελίας',        'admin/view/sale/order_info/after',                  'extension/couriercenter/shipping/courier_center_order.orderPanel',          0],
    ['courier_center_order_list',     'Courier Center — στήλες/bulk στη λίστα παραγγελιών',   'admin/view/sale/order_list/after',                  'extension/couriercenter/shipping/courier_center_order_list.injectColumns',  0],
    ['courier_center_email',          'Courier Center — tracking info σε order emails',       'catalog/model/checkout/order/addHistory/after',     'extension/couriercenter/shipping/courier_center_email.sendTracking',        99],
    ['courier_center_boxnow_widget',  'Courier Center — BOX NOW widget στο checkout',         'catalog/view/checkout/checkout/after',              'extension/couriercenter/shipping/courier_center_boxnow.widget',             0],
    ['courier_center_boxnow_order',   'Courier Center — αποθήκευση BOX NOW locker',           'catalog/model/checkout/order/addOrder/after',       'extension/couriercenter/shipping/courier_center_boxnow.saveToOrder',        0],
    ['courier_center_auto_create',    'Courier Center — auto-create voucher σε αλλαγή status','catalog/model/checkout/order/addHistory/after',     'extension/couriercenter/shipping/courier_center_autocreate.autoCreate',     50],
];

foreach ($events as [$code, $desc, $trigger, $action, $sort]) {
    $q = $db->prepare("SELECT event_id FROM `" . DB_PREFIX . "event` WHERE code = ? LIMIT 1");
    $q->execute([$code]);
    if ($q->fetch()) {
        $db->prepare("UPDATE `" . DB_PREFIX . "event` SET `trigger`=?, `action`=?, `status`=1, `sort_order`=? WHERE code=?")
           ->execute([$trigger, $action, $sort, $code]);
        echo "  event OK (updated): $code\n";
    } else {
        $db->prepare("INSERT INTO `" . DB_PREFIX . "event` SET code=?, description=?, `trigger`=?, `action`=?, `status`=1, `sort_order`=?")
           ->execute([$code, $desc, $trigger, $action, $sort]);
        echo "  event OK (inserted): $code\n";
    }
}

// ── Permissions ──────────────────────────────────────────────────────────────
$routes = [
    'extension/couriercenter/shipping/courier_center',
    'extension/couriercenter/shipping/courier_center_order',
    'extension/couriercenter/shipping/courier_center_manifest',
    'extension/couriercenter/shipping/courier_center_bug',
    'extension/couriercenter/shipping/courier_center_update',
];
$base = 'extension/couriercenter/shipping/courier_center';

$groups = $db->query("SELECT user_group_id, permission FROM `" . DB_PREFIX . "user_group`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $perm = json_decode($g['permission'], true);
    if (!is_array($perm)) continue;
    // Only touch groups that already manage Courier Center (have the base route).
    if (!in_array($base, $perm['access'] ?? [], true)) continue;

    $changed = false;
    foreach (['access', 'modify'] as $type) {
        if (!isset($perm[$type]) || !is_array($perm[$type])) $perm[$type] = [];
        foreach ($routes as $r) {
            if (!in_array($r, $perm[$type], true)) { $perm[$type][] = $r; $changed = true; }
        }
    }
    if ($changed) {
        $db->prepare("UPDATE `" . DB_PREFIX . "user_group` SET permission=? WHERE user_group_id=?")
           ->execute([json_encode($perm), $g['user_group_id']]);
        echo "  permissions OK: user_group #{$g['user_group_id']}\n";
    }
}

echo "\nDone. Courier Center events + permissions are up to date.\n";
