<?php
/**
 * OpenCart 4: events, admin permissions, tables and internal state of the extension.
 * Idempotent: used by install(), by the settings page after an update (version change) and by setup.php.
 */

namespace Opencart\Extension\Couriercenter\Library\Oc4;

use Opencart\Extension\Couriercenter\Library\CCDbSchema;

class CCOc4Setup {

    const STATE_CODE = 'courier_center_state';
    const BASE_ROUTE = 'extension/couriercenter/shipping/courier_center';

    /** code => [trigger, action, sort_order, description] */
    public static function events(): array {
        $b = 'extension/couriercenter/shipping/';
        return [
            'courier_center_order_panel'   => ['admin/view/sale/order_info/after', $b . 'courier_center_order.orderPanel', 0, 'Courier Center — panel στη σελίδα παραγγελίας'],
            'courier_center_order_list'    => ['admin/view/sale/order_list/after', $b . 'courier_center_order_list.injectColumns', 0, 'Courier Center — στήλες/μαζικές ενέργειες στη λίστα παραγγελιών'],
            'courier_center_update_notice' => ['admin/view/common/header/after', $b . 'courier_center_update.notice', 0, 'Courier Center — ειδοποίηση διαθέσιμης ενημέρωσης'],
            'courier_center_returns_menu'  => ['admin/view/common/column_left/before', $b . 'courier_center_returns.menu', 0, 'Courier Center — Επιστροφές στο μενού Πωλήσεις'],
            'courier_center_auto_create'   => ['catalog/model/checkout/order/addHistory/after', $b . 'courier_center_autocreate.autoCreate', 50, 'Courier Center — αυτόματη δημιουργία voucher σε αλλαγή status'],
            'courier_center_mail_invoice'  => ['catalog/view/mail/order_invoice/after', $b . 'courier_center_email.orderInvoice', 0, 'Courier Center — AWB στο email επιβεβαίωσης παραγγελίας'],
            'courier_center_mail_history'  => ['catalog/view/mail/order_history/after', $b . 'courier_center_email.orderHistory', 0, 'Courier Center — AWB στο email αλλαγής status'],
            'courier_center_boxnow_widget' => ['catalog/view/checkout/checkout/after', $b . 'courier_center_boxnow.widget', 0, 'Courier Center — BOX NOW widget στο checkout'],
            'courier_center_boxnow_order'  => ['catalog/model/checkout/order/addOrder/after', $b . 'courier_center_boxnow.saveToOrder', 0, 'Courier Center — αποθήκευση BOX NOW locker στην παραγγελία'],
            'courier_center_boxnow_edit'   => ['catalog/model/checkout/order/editOrder/before', $b . 'courier_center_boxnow.keepOnEdit', 0, 'Courier Center — BOX NOW locker όταν ξαναγράφεται η παραγγελία'],
            'courier_center_boxnow_clear'  => ['catalog/controller/checkout/success/before', $b . 'courier_center_boxnow.clearSession', 0, 'Courier Center — καθαρισμός επιλογής BOX NOW μετά την παραγγελία'],
            'courier_center_returns_order' => ['catalog/view/account/order_info/after', $b . 'courier_center_returns.orderInfo', 0, 'Courier Center — επιστροφές στη σελίδα παραγγελίας του πελάτη'],
            'courier_center_returns_orders' => ['catalog/view/account/order_list/after', $b . 'courier_center_returns.orderList', 0, 'Courier Center — κουμπί «Αίτημα Επιστροφής» στη λίστα παραγγελιών του πελάτη'],
            'courier_center_pseudo_cron'   => ['catalog/view/common/footer/after', $b . 'courier_center_cron.tick', 5, 'Courier Center — αυτόματη ενημέρωση status χωρίς cron job'],
            'courier_center_returns_note_dash' => ['admin/view/common/dashboard/after', $b . 'courier_center_returns.notice', 0, 'Courier Center — ειδοποίηση αιτημάτων επιστροφής (Πίνακας ελέγχου)'],
            'courier_center_returns_note_list' => ['admin/view/sale/order/after', $b . 'courier_center_returns.notice', 0, 'Courier Center — ειδοποίηση αιτημάτων επιστροφής (Παραγγελίες)'],
            'courier_center_returns_note_info' => ['admin/view/sale/order_info/after', $b . 'courier_center_returns.notice', 1, 'Courier Center — ειδοποίηση αιτημάτων επιστροφής (Παραγγελία)'],
        ];
    }

    /** Codes of older versions, removed on repair (1.0.x: separate tracking email). */
    public static function obsoleteEvents(): array {
        return ['courier_center_email'];
    }

    /** Admin routes that need access/modify permission. */
    public static function routes(): array {
        $out = [];
        foreach (['', '_order', '_order_list', '_manifest', '_bug', '_update', '_docs', '_returns'] as $suffix) {
            $out[] = self::BASE_ROUTE . $suffix;
        }
        return $out;
    }

    /**
     * Tables + events + permissions (for every group that already has the base route, plus $group_id).
     * @return string[] what was done
     */
    public static function repair($db, string $prefix, int $group_id = 0): array {
        $done = CCDbSchema::ensure($db, $prefix);
        $codes = [];
        foreach (array_merge(array_keys(self::events()), self::obsoleteEvents()) as $code) {
            $codes[] = "'" . $db->escape($code) . "'";
        }
        $db->query("DELETE FROM `" . $prefix . "event` WHERE `code` IN (" . implode(',', $codes) . ")");
        foreach (self::events() as $code => $e) {
            $db->query("INSERT INTO `" . $prefix . "event` SET `code` = '" . $db->escape($code) . "', `description` = '" . $db->escape($e[3]) . "', `trigger` = '" . $db->escape($e[0]) . "', `action` = '" . $db->escape($e[1]) . "', `status` = '1', `sort_order` = '" . (int)$e[2] . "'");
        }
        $done[] = count(self::events()) . ' events';
        $granted = 0;
        foreach ($db->query("SELECT `user_group_id`, `permission` FROM `" . $prefix . "user_group`")->rows as $g) {
            $perm = json_decode((string)$g['permission'], true);
            if (!is_array($perm)) {
                continue;
            }
            if ((int)$g['user_group_id'] !== $group_id && !in_array(self::BASE_ROUTE, $perm['access'] ?? [], true)) {
                continue;
            }
            $changed = false;
            foreach (['access', 'modify'] as $type) {
                if (!isset($perm[$type]) || !is_array($perm[$type])) {
                    $perm[$type] = [];
                }
                foreach (self::routes() as $r) {
                    if (!in_array($r, $perm[$type], true)) {
                        $perm[$type][] = $r;
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $db->query("UPDATE `" . $prefix . "user_group` SET `permission` = '" . $db->escape(json_encode($perm)) . "' WHERE `user_group_id` = '" . (int)$g['user_group_id'] . "'");
                $granted++;
            }
        }
        if ($granted) {
            $done[] = 'permissions: ' . $granted . ' group(s)';
        }
        self::ensureCronKey($db, $prefix);
        self::setState($db, $prefix, 'installed_version', self::version());
        self::setState($db, $prefix, 'installed_build', self::eventsSignature());
        $done[] = 'version ' . self::version();
        return $done;
    }

    /** repair() when the files are newer than the last repair (update without re-install, or a new zip of the same version). */
    public static function repairIfUpdated($db, string $prefix): array {
        if (self::getState($db, $prefix, 'installed_version') === self::version() && self::getState($db, $prefix, 'installed_build') === self::eventsSignature()) {
            return [];
        }
        return self::repair($db, $prefix);
    }

    /** Changes when the event list changes. */
    public static function eventsSignature(): string {
        return md5(json_encode(self::events()));
    }

    public static function version(): string {
        $f = dirname(__DIR__, 2) . '/install.json';
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($j) && !empty($j['version']) ? (string)$j['version'] : '0';
    }

    public static function getState($db, string $prefix, string $key): string {
        $q = $db->query("SELECT `value` FROM `" . $prefix . "setting` WHERE `store_id` = '0' AND `key` = '" . $db->escape('shipping_courier_center_' . $key) . "' LIMIT 1");
        return $q->num_rows ? (string)$q->row['value'] : '';
    }

    /** Internal keys live under their own code: the settings Save (editSetting) never deletes them. */
    public static function setState($db, string $prefix, string $key, string $value): void {
        $k = $db->escape('shipping_courier_center_' . $key);
        $db->query("DELETE FROM `" . $prefix . "setting` WHERE `store_id` = '0' AND `key` = '" . $k . "'");
        $db->query("INSERT INTO `" . $prefix . "setting` SET `store_id` = '0', `code` = '" . self::STATE_CODE . "', `key` = '" . $k . "', `value` = '" . $db->escape($value) . "', `serialized` = '0'");
    }

    /** Write ONE setting of the extension's own code (Test & Auto-fill), leaving the others alone. */
    public static function setSetting($db, string $prefix, string $key, string $value): void {
        $k = $db->escape('shipping_courier_center_' . $key);
        $db->query("DELETE FROM `" . $prefix . "setting` WHERE `store_id` = '0' AND `key` = '" . $k . "'");
        $db->query("INSERT INTO `" . $prefix . "setting` SET `store_id` = '0', `code` = 'shipping_courier_center', `key` = '" . $k . "', `value` = '" . $db->escape($value) . "', `serialized` = '0'");
    }

    public static function ensureCronKey($db, string $prefix): string {
        $key = self::getState($db, $prefix, 'cron_key');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            self::setState($db, $prefix, 'cron_key', $key);
        }
        return $key;
    }

    public static function cronKeyMatches(string $expected, $given): bool {
        return is_string($given) && preg_match('/^[a-f0-9]{32}$/', $expected) === 1 && hash_equals($expected, $given);
    }
}
