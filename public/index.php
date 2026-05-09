<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    try {
        return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
    } catch (\Throwable $throwable) {
        $logDir = dirname(__DIR__).'/var/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $message = sprintf(
            "[%s] HTTP boot failure: %s in %s:%d\n%s\n\n",
            date(DATE_ATOM),
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
            $throwable->getTraceAsString(),
        );

        @file_put_contents($logDir.'/app_error.log', $message, FILE_APPEND);

        // Symfony container is not available at boot time — use native mail().
        $to       = $_ENV['SIGNUP_ALERT_EMAIL'] ?? getenv('SIGNUP_ALERT_EMAIL') ?: 'contact@coachat.fr';
        $from     = $_ENV['SIGNUP_ALERT_FROM_EMAIL'] ?? getenv('SIGNUP_ALERT_FROM_EMAIL') ?: 'noreply@coachat.fr';
        $fromName = $_ENV['SIGNUP_ALERT_FROM_NAME'] ?? getenv('SIGNUP_ALERT_FROM_NAME') ?: 'Coachat';
        $subject  = '[ERREUR BOOT] '.$throwable::class.' — '.mb_substr($throwable->getMessage(), 0, 120);
        $headers  = implode("\r\n", [
            "From: {$fromName} <{$from}>",
            'Content-Type: text/plain; charset=utf-8',
            'X-Mailer: PHP/'.PHP_VERSION,
        ]);

        @mail($to, $subject, $message, $headers);

        throw $throwable;
    }
};
