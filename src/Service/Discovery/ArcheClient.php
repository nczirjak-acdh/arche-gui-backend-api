<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/** Reads public ARCHE metadata from a server-configured repository. */
final class ArcheClient
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const TIMEOUT_SECONDS = 40;

    public readonly string $baseUrl;
    private readonly ClientInterface $http;

    public function __construct(
        ArcheConfiguration $configuration,
        ?ClientInterface $http = null,
    ) {
        $this->baseUrl = $configuration->baseUrl($configuration->load());
        $this->http = $http ?? new Client();
    }

    public function read(string $method, string $path, array $parameters): string
    {
        $options = [
            'headers' => ['Accept' => 'application/n-triples'],
            'allow_redirects' => false,
            'http_errors' => false,
            'stream' => true,
            'connect_timeout' => 8,
            'timeout' => self::TIMEOUT_SECONDS,
            'read_timeout' => 8,
        ];
        $options[$method === 'POST' ? 'form_params' : 'query'] = $parameters;
        $deadline = hrtime(true) + self::TIMEOUT_SECONDS * 1_000_000_000;

        try {
            $response = $this->http->request($method, $this->baseUrl . $path, $options);
            $body = $response->getBody();
            try {
                $status = $response->getStatusCode();
                if ($method === 'GET' && in_array($status, [404, 410], true)) {
                    throw new UpstreamError('The ARCHE record was not found.', 404);
                }
                if ($status === 429 || $status === 503) {
                    throw new UpstreamError('ARCHE is temporarily unavailable. Please try again later.', 503);
                }
                if ($status !== 200) {
                    throw new UpstreamError(sprintf('ARCHE returned HTTP %d.', $status));
                }

                // Cap decoded bytes even when the server omits Content-Length.
                $content = '';
                while (!$body->eof()) {
                    if (hrtime(true) >= $deadline) {
                        throw new UpstreamError('The ARCHE response timed out.', 504);
                    }
                    $chunk = $body->read(8192);
                    if ($chunk === '' && !$body->eof()) {
                        throw new UpstreamError('Could not finish reading the ARCHE response.');
                    }
                    $content .= $chunk;
                    if (strlen($content) > self::MAX_BYTES) {
                        throw new UpstreamError('The ARCHE response is too large. Narrow your search.');
                    }
                }
                return $content;
            } finally {
                $body->close();
            }
        } catch (UpstreamError $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            throw new UpstreamError('Could not read the ARCHE API response.', previous: $error);
        }
    }
}
