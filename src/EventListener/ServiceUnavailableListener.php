<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ProductApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Twig\Environment;

/**
 * Turns an unavailable dependency (products API or database) into a clean 503 page,
 * instead of a stack trace. The technical error is logged, never shown to the user.
 */
#[AsEventListener]
final readonly class ServiceUnavailableListener
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $message = match (true) {
            $exception instanceof ProductApiException => 'Le catalogue est momentanément indisponible. Merci de réessayer dans quelques instants.',
            $exception instanceof \PDOException => 'Les commentaires sont momentanément indisponibles. Merci de réessayer dans quelques instants.',
            default => null,
        };

        if (null === $message) {
            return;
        }

        $this->logger->error($exception->getMessage(), ['exception' => $exception]);

        $event->setResponse(new Response(
            $this->twig->render('error/service_unavailable.html.twig', ['message' => $message]),
            Response::HTTP_SERVICE_UNAVAILABLE,
        ));
    }
}
