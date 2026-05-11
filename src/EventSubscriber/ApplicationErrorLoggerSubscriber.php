<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class ApplicationErrorLoggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly string $errorAlertEmail,
        private readonly string $fromEmail,
        private readonly string $fromName,
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

        // Only alert on server errors (5xx), not on client errors (4xx).
        if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500) {
            return;
        }

        $subject = '[Alerte plateforme] Incident côté application';

        $incidentSummary = trim((string) mb_substr($throwable->getMessage(), 0, 180));
        if ($incidentSummary === '') {
            $incidentSummary = 'Un incident est survenu sans message détaillé.';
        }

        $body = implode("\n", [
            'Une anomalie a été détectée sur la plateforme et peut impacter un parcours client ou marchand.',
            '',
            sprintf('Parcours concerné : %s', $request->attributes->get('_route') ?? $request->getUri()),
            sprintf('Niveau d\'impact : %s', $throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500 ? 'modéré' : 'élevé'),
            sprintf('Résumé : %s', $incidentSummary),
            sprintf('Identifiant de suivi : %s', $request->headers->get('X-Request-Id') ?? 'n/a'),
            '',
            'Action recommandée : consulter les logs applicatifs pour le détail technique et corriger rapidement.',
        ]);

        $this->sendAlert($subject, $body);
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

        $subject = '[Alerte plateforme] Incident sur une tâche automatique';

        $incidentSummary = trim((string) mb_substr($throwable->getMessage(), 0, 180));
        if ($incidentSummary === '') {
            $incidentSummary = 'Une tâche a échoué sans message détaillé.';
        }

        $body = implode("\n", [
            'Une anomalie a été détectée pendant l\'exécution d\'une tâche automatique.',
            '',
            sprintf('Tâche concernée : %s', $command?->getName() ?? 'n/a'),
            sprintf('Résumé : %s', $incidentSummary),
            sprintf('Contexte d\'exécution : %s', method_exists($input, '__toString') ? (string) $input : 'n/a'),
            '',
            'Action recommandée : relancer la tâche après vérification des logs applicatifs.',
        ]);

        $this->sendAlert($subject, $body);
    }

    private function sendAlert(string $subject, string $body): void
    {
        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($this->errorAlertEmail)
                ->subject($subject)
                ->text($body);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send error alert email.', [
                'exception' => $e->getMessage(),
                'original_subject' => $subject,
            ]);
        }
    }
}