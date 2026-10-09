<?php
/**
 * Courier Center — shipment status tracker (CLI). Every 2 hours, e.g. cPanel > Cron Jobs:
 *   0 *\/2 * * *  php /home/USER/public_html/extension/couriercenter/cron_status_tracker.php
 * The same job can run from a URL with a secret key (see the extension settings page).
 * Checks at most 200 active shipments per run (oldest check first), skips cancelled orders,
 * then verifies / tracks the customer returns.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
$oc_root = realpath(__DIR__ . '/../../');
if (!$oc_root || !is_file($oc_root . '/config.php')) {
    fwrite(STDERR, "ERROR: OpenCart config.php not found in $oc_root\n");
    exit(1);
}
require $oc_root . '/config.php';
require_once DIR_SYSTEM . 'startup.php';
$autoloader = new \Opencart\System\Engine\Autoloader();
$autoloader->register('Opencart\System', DIR_SYSTEM);
require_once __DIR__ . '/library/oc4/loader.php';

$db = new \Opencart\System\Library\DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$config = new \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4ConfigSnapshot($db, DB_PREFIX);
$result = \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Cron::run($db, DB_PREFIX, $config, DIR_CACHE);
foreach ($result['lines'] as $line) {
    echo '  ' . $line . "\n";
}
echo '[' . date('Y-m-d H:i:s') . '] ' . $result['message'] . "\n";
exit(0);
