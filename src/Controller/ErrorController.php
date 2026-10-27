<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class ErrorController
{
    public function __construct(
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public function show(Request $request, \Throwable $exception): Response
    {
        $error = FlattenException::createFromThrowable($exception);
        $statusCode = $error->getStatusCode();
        $statusText = Response::$statusTexts[$statusCode] ?? 'Error';
        $description = match ($statusCode) {
            404 => 'The requested page could not be found. Please check the address and try again.',
            403 => 'You do not have permission to access this resource.',
            401 => 'Please sign in to access this resource.',
            405 => 'This request method is not supported for this resource.',
            429 => 'Too many requests. Please wait a moment and try again.',
            default => $statusCode >= 500
                ? 'We could not complete your request. Please try again later.'
                : 'We could not process your request. Please check your request and try again.',
        };
        $message = $this->debug ? $exception->getMessage() : $description;
        $headers = $error->getHeaders();
        $headers['Cache-Control'] = 'no-store';

        if (in_array($request->getPreferredFormat('html'), ['json', 'jsonld', 'problem', 'jsonapi'], true)) {
            $response = new JsonResponse([
                'status' => $statusCode,
                'title' => $statusText,
                'detail' => $message,
            ], $statusCode, $headers);
            $response->headers->set('Content-Type', 'application/problem+json');
            return $response;
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        try {
            require dirname(__DIR__, 2).'/templates/error/error.html.php';
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        return new Response($html, $statusCode, $headers);
    }
}
