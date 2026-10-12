<?php
/**
 * Sends one email with the shop's own mail settings (OpenCart 4 sends nothing without a mail engine).
 * Used by the web controllers and by the cron job from the command line.
 */

namespace Opencart\Extension\Couriercenter\Library\Oc4;

class CCOc4Mailer {

    /**
     * @param object      $config OpenCart Config or CCOc4ConfigSnapshot (get())
     * @param string|null $error  the failure text (null on success)
     */
    public static function send($config, string $to, string $subject, string $html, &$error = null): bool {
        $error = null;
        $engine = trim((string)$config->get('config_mail_engine'));
        if ($engine === '') {
            return false; // no mail engine configured: nothing is sent, as OpenCart itself does
        }
        try {
            $option = [
                'parameter'     => $config->get('config_mail_parameter'),
                'smtp_hostname' => $config->get('config_mail_smtp_hostname'),
                'smtp_username' => $config->get('config_mail_smtp_username'),
                'smtp_password' => html_entity_decode((string)$config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
                'smtp_port'     => $config->get('config_mail_smtp_port'),
                'smtp_timeout'  => $config->get('config_mail_smtp_timeout'),
            ];
            $mail = new \Opencart\System\Library\Mail($engine, $option);
            $mail->setTo($to);
            $mail->setFrom((string)$config->get('config_email'));
            $mail->setSender(html_entity_decode((string)$config->get('config_name'), ENT_QUOTES, 'UTF-8'));
            $mail->setSubject($subject);
            $mail->setHtml($html);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }
}
