<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Controller\ErrorController;
use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$request = Request::create('/missing', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
$exception = new NotFoundHttpException('<script>alert("test")</script>');
$html = (new ErrorController(true))->show($request, $exception);
check($html->getStatusCode() === 404, 'HTML must preserve 404');
check(str_contains($html->getContent(), '<h1>Error</h1>'), 'Custom layout missing');
check(str_contains($html->getContent(), '<details>'), 'Disclosure missing');
check(!str_contains($html->getContent(), '<script>'), 'Exception must be escaped');
check(str_contains($html->getContent(), '&lt;script&gt;'), 'Debug message missing');

foreach ([403, 404, 405, 429, 500, 503] as $code) {
    $response = (new ErrorController(false))->show($request, new HttpException($code, 'PRIVATE_DATABASE_PASSWORD', null, ['Retry-After' => '60']));
    check($response->getStatusCode() === $code, 'Status changed');
    check(!str_contains($response->getContent(), 'PRIVATE_DATABASE_PASSWORD'), 'Production leaked exception');
    check($response->headers->get('Retry-After') === '60', 'Exception header lost');
}
foreach (['application/json', 'application/problem+json'] as $accept) {
    $jsonRequest = Request::create('/missing', 'GET', [], [], [], ['HTTP_ACCEPT' => $accept]);
    $response = (new ErrorController(false))->show($jsonRequest, $exception);
    check($response->headers->get('Content-Type') === 'application/problem+json', 'JSON negotiation failed');
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    check($body['status'] === 404 && !str_contains($body['detail'], '<script>'), 'Invalid production JSON');
}

// Exercise routing and the real error subrequest without needing the repository database.
$_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'error-page-test';
$_SERVER['DEFAULT_URI'] = $_ENV['DEFAULT_URI'] = 'http://localhost';
$_SERVER['CORS_ALLOW_ORIGIN'] = $_ENV['CORS_ALLOW_ORIGIN'] = '^https?://localhost$';
foreach ([true, false] as $debug) {
    $kernel = new Kernel('test', $debug);
    foreach (['text/html', 'application/json'] as $accept) {
        $request = Request::create('/__missing_error_page_test__', 'GET', [], [], [], ['HTTP_ACCEPT' => $accept]);
        $response = $kernel->handle($request);
        check($response->getStatusCode() === 404, 'Kernel status mismatch');
        check($accept === 'text/html'
            ? str_contains($response->getContent(), '<h1>Error</h1>')
            : json_decode($response->getContent(), true)['status'] === 404, 'Kernel error controller not used');
        $kernel->terminate($request, $response);
    }
    $kernel->shutdown();
}
echo "Error page checks passed.\n";
