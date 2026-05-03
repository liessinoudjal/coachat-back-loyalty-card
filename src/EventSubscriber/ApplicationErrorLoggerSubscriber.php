<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApplicationErrorLoggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
            ConsoleEvents::ERROR => 'onConsoleError',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $request = $event->getRequest();

        $this->logger->error('Unhandled HTTP exception.', [
            'message' => $throwable->getMessage(),
            'exception_class' => $throwable::class,
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'route' => $request->attributes->get('_route'),
            'client_ip' => $request->getClientIp(),
            'query' => $request->query->all(),
            'request_id' => $request->headers->get('X-Request-Id'),
            'trace' => $throwable->getTraceAsString(),
        ]);
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $throwable = $event->getError();
        $command = $event->getCommand();
        $input = $event->getInput();

        $this->logger->error('Unhandled console exception.', [
            'message' => $throwable->getMessage(),
            'exception_class' => $throwable::class,
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'command' => $command?->getName(),
            'input' => method_exists($input, '__toString') ? (string) $input : null,
            'trace' => $throwable->getTraceAsString(),
        ]);
    }
}