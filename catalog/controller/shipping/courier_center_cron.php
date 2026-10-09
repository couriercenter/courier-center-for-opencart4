<?php
namespace Opencart\Catalog\Controller\Extension\Couriercenter\Shipping;

require_once DIR_EXTENSION . 'couriercenter/library/oc4/loader.php';

use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Cron;
use Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Setup;

/** Courier Center status tracker through a URL with a secret key (for hosts without a CLI cron). */
class CourierCenterCron extends \Opencart\System\Engine\Controller {
    use \Opencart\Extension\Couriercenter\Library\Oc4\CCOc4Controller;

    public function index(): void {
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        if (!CCOc4Setup::cronKeyMatches(CCOc4Setup::getState($this->db, DB_PREFIX, 'cron_key'), $this->request->get['key'] ?? null)) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }
        @set_time_limit(0);
        $site = (string)$this->config->get('config_url');
        $r = CCOc4Cron::run($this->db, DB_PREFIX, $this->config, DIR_CACHE, function ($event, $id) {
            $this->ccSendReturnMail((string)$event, (int)$id);
        });
        $this->response->setOutput($r['message'] . "\n" . implode("\n", $r['lines']) . "\n");
    }
}
