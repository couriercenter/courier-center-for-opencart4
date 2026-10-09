<?php
/** One cron pass for OpenCart 4, shared by the CLI script and the URL endpoint. */

namespace Opencart\Extension\Couriercenter\Library\Oc4;

use Opencart\Extension\Couriercenter\Library\CCReturnsService;
use Opencart\Extension\Couriercenter\Library\CCReturnsTracker;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;

class CCOc4Cron {
    const LOCK_TTL = 1800;

    /**
     * @param object        $config   OpenCart Config (or any object with get())
     * @param string        $lock_dir writable folder (DIR_CACHE)
     * @param callable|null $notifier returns emails (event, id); the CLI has no mail: null
     * @return array ['ran' => bool, 'message' => string, 'lines' => string[]]
     */
    public static function run($db, string $prefix, $config, string $lock_dir, $notifier = null): array {
        $lock = rtrim(str_replace('\\', '/', $lock_dir), '/') . '/cc_cron.lock';
        if (is_file($lock) && (time() - (int)@filemtime($lock)) < self::LOCK_TTL) {
            return ['ran' => false, 'message' => 'Another run is in progress (' . basename($lock) . ')', 'lines' => []];
        }
        @file_put_contents($lock, (string)time());
        try {
            $svc = new CCVoucherService($db, $config, $prefix);
            $report = $svc->tracker()->run();
            $returns = new CCReturnsService($db, $prefix, $config, $svc->api(), $notifier);
            $lines = array_merge($report['lines'], (new CCReturnsTracker($returns))->cron());
            CCOc4Setup::setState($db, $prefix, 'cron_last_run', date('Y-m-d H:i:s'));
            CCOc4Setup::setState($db, $prefix, 'cron_next_run', date('Y-m-d H:i:s', time() + 7200));
            return [
                'ran'     => true,
                'message' => sprintf('checked=%d, updated=%d, skipped=%d, failed=%d', $report['checked'], $report['updated'], $report['skipped'], $report['failed']),
                'lines'   => $lines,
            ];
        } finally {
            @unlink($lock);
        }
    }
}
