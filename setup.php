<?php
/**
 * Courier Center — idempotent setup / repair script (CLI).
 * (Re)registers the events, grants the admin permissions and creates/updates the tables, WITHOUT dropping
 * any data. The same repair runs by itself on the next admin page after an update, and from the settings
 * page («Επιδιόρθωση εγκατάστασης»); this script is for shops without admin access.
 *   php /path/to/opencart/extension/couriercenter/setup.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This setup script can only be run from the command line.\n");
}
$root = realpath(__DIR__ . '/../../');
if (!$root || !is_file($root . '/admin/config.php')) {
    exit("ERROR: Could not locate OpenCart root (admin/config.php).\n");
}
require $root . '/admin/config.php';
require_once DIR_SYSTEM . 'startup.php';
$autoloader = new \Opencart\System\Engine\Autoloader();
$autoloader->register('Opencart\System', DIR_SYSTEM);
require_once __DIR__ . '/library/oc4/loader.php';

$db = new \Opencart\System\Library\DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$ext = $db->query("SELECT `extension_id` FROM `" . DB_PREFIX . "extension` WHERE `type` = 'shipping' AND `code` = 'courier_center' LIMIT 1");
if (!$ext->num_rows) {
    $db->query("INSERT INTO `" . DB_PREFIX . "extension` SET `extension` = 'couriercenter', `type` = 'shipping', `code` = 'courier_center'");
    echo "  extension registered\n";
}
foreach (\Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup::repair($db, DB_PREFIX) as $line) {
    echo '  ' . $line . "\n";
}
echo "\nDone. Courier Center events, permissions and tables are up to date.\n";
