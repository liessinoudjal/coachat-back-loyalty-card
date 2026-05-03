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

        throw $throwable;
    }
};
