<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

function checkCors(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'cors-test';
$_SERVER['DEFAULT_URI'] = $_ENV['DEFAULT_URI'] = 'http://localhost';
$_SERVER['CORS_ALLOW_ORIGIN'] = $_ENV['CORS_ALLOW_ORIGIN'] = '^https?://(localhost|127\\.0\\.0\\.1)(:[0-9]+)?$';

foreach ([true, false] as $debug) {
    $kernel = new class('test', $debug) extends Kernel {
        private ?string $corsCacheDir = null;

        public function getCacheDir(): string
        {
            return $this->corsCacheDir ??= sys_get_temp_dir() . '/arche-cors-' . bin2hex(random_bytes(6));
        }
    };
    // Preflight must finish before controllers open database connections.
    foreach (['/smartsearch', '/expert/1/en', '/refetchAllFacets/en'] as $path) {
        $request = Request::create($path, 'OPTIONS', server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, authorization',
        ]);
        $response = $kernel->handle($request);
        checkCors($response->isSuccessful(), "Preflight failed: $path");
        checkCors($response->headers->get('Access-Control-Allow-Origin') === 'http://localhost:3000', "Origin missing: $path");
        checkCors(str_contains($response->headers->get('Access-Control-Allow-Methods', ''), 'POST'), 'POST missing');
        checkCors(str_contains(strtolower($response->headers->get('Access-Control-Allow-Headers', '')), 'authorization'), 'Authorization missing');
        $kernel->terminate($request, $response);
    }

    // Regular responses, including errors, must also carry CORS headers.
    foreach (['http://localhost:3000', 'http://127.0.0.1:5173', 'https://untrusted.example', null] as $origin) {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }
        $request = Request::create('/__missing_cors_test__', 'GET', server: $server);
        $response = $kernel->handle($request);
        checkCors($response->getStatusCode() === 404, 'Expected error response');
        $allowed = $origin !== null && $origin !== 'https://untrusted.example';
        checkCors($response->headers->get('Access-Control-Allow-Origin') === ($allowed ? $origin : null), 'Incorrect origin policy');
        if ($allowed) {
            checkCors($response->headers->get('Access-Control-Expose-Headers') === 'link, content-type', 'Incorrect exposed headers');
        }
        $kernel->terminate($request, $response);
    }
    $kernel->shutdown();
}

echo "CORS checks passed (debug and production mode).\n";
