<?php

declare(strict_types=1);

use App\Kernel;
use App\Service\Discovery\ArcheClient;
use App\Service\Discovery\ArcheConfiguration;
use App\Service\Discovery\RdfReader;
use App\Service\Discovery\SearchService;
use App\Service\Discovery\VocabularyBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkDiscovery(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/arche-discovery-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$kernel = new class('test', true, $directory) extends Kernel {
    public function __construct(string $environment, bool $debug, private readonly string $directory)
    {
        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory . '/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(ArcheClient::class)->setPublic(true);
            }
        });
    }
};

try {
    $vocabularyPath = $directory . '/topics.sqlite';
    foreach ([
        'APP_SECRET' => 'discovery-test',
        'DEFAULT_URI' => 'http://localhost',
        'CORS_ALLOW_ORIGIN' => '^http://localhost:5173$',
        'ARCHE_DISCOVERY_VOCABULARY' => $vocabularyPath,
    ] as $key => $value) {
        $_SERVER[$key] = $_ENV[$key] = $value;
    }

    $source = $directory . '/terms.nt';
    file_put_contents($source, <<<'NT'
        <https://example.org/concept> <http://www.w3.org/2004/02/skos/core#prefLabel> "Letters"@en .
        <https://example.org/concept> <http://www.w3.org/2004/02/skos/core#prefLabel> "Briefe"@de .
        <https://example.org/concept> <http://www.w3.org/2004/02/skos/core#prefLabel> "Lettres"@fr .
        <https://example.org/1> <https://vocabs.acdh.oeaw.ac.at/schema#hasSubject> <https://example.org/concept> .
        <https://example.org/2> <https://vocabs.acdh.oeaw.ac.at/schema#hasSubject> <https://example.org/concept> .
        <https://example.org/3> <https://vocabs.acdh.oeaw.ac.at/schema#hasSubject> <https://example.org/concept> .
        <https://example.org/1> <https://vocabs.acdh.oeaw.ac.at/schema#hasKeyword> "Archives"@en .
        <https://example.org/2> <https://vocabs.acdh.oeaw.ac.at/schema#hasKeyword> "Archives"@en .
        <https://example.org/3> <https://vocabs.acdh.oeaw.ac.at/schema#hasKeyword> "Archives"@en .
        NT);
    $builder = new VocabularyBuilder(new RdfReader());
    $stats = $builder->build($source, $vocabularyPath);
    checkDiscovery($stats['terms'] === 3 && $stats['alias_pairs'] === 1, 'Vocabulary import or language selection failed.');

    mkdir($directory . '/src/arche-config', 0700, true);
    file_put_contents($directory . '/src/arche-config/config-gui.yaml', <<<'YAML'
        rest:
            urlBase: 'https://arche.example.test/'
            pathBase: '/configured/api/'
        YAML);
    $baseUrl = 'https://arche.example.test/configured/api';
    $fixture = file_get_contents(__DIR__ . '/fixtures/discovery-search.nt');
    $emptyFixture = sprintf('<%s/> <search://count> "0" .', $baseUrl);
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], $fixture),
        new Response(200, [], $fixture),
        new Response(200, [], $emptyFixture),
        new Response(404),
        new Response(429),
        new Response(200, [], '<html>Unusable upstream response</html>'),
        new Response(200, [], $emptyFixture),
    ]));
    $handler->push(Middleware::history($history));
    $kernel->boot();
    $kernel->getContainer()->set(
        ArcheClient::class,
        new ArcheClient(new ArcheConfiguration($directory), new Client(['handler' => $handler])),
    );

    $request = static function (string $path, int $status = 200, string $method = 'GET', ?string $body = null, string $type = 'application/json') use ($kernel): array {
        $request = Request::create('/api/discovery' . $path, $method, server: [
            'CONTENT_TYPE' => $type,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost:5173',
        ], content: $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        checkDiscovery($response->getStatusCode() === $status, sprintf(
            '%s %s expected HTTP %d, got HTTP %d. %s',
            $method, $path, $status, $response->getStatusCode(), substr($response->getContent(), 0, 300),
        ));
        checkDiscovery($response->headers->get('Access-Control-Allow-Origin') === 'http://localhost:5173', 'CORS header missing.');
        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    };

    $status = $request('/status');
    checkDiscovery($status['endpoint'] === $baseUrl . '/search', 'Status did not report the configured search URL.');
    checkDiscovery($status['languages'] === ['en', 'de'] && $status['vocabulary']['source_name'] === 'terms.nt', 'Status configuration is incorrect.');
    checkDiscovery(!isset($status['vocabulary']['source']), 'Status exposed a local filesystem path.');
    $examples = $request('/examples')['examples'];
    checkDiscovery(count($examples) === 15 && in_array('historische Karten', array_column($examples, 'q'), true), 'Example queries are incomplete.');

    $vocabulary = $request('/vocabulary?q=Letters')['analysis'];
    checkDiscovery(in_array('Briefe', $vocabulary['expansions'], true), 'The German alias was not found.');
    checkDiscovery(in_array('Archives', array_column($vocabulary['related_suggestions'], 'label'), true), 'Related vocabulary is missing.');
    checkDiscovery(!in_array('Archives', $vocabulary['expansions'], true), 'Related terms must not expand the query.');

    $result = $request('/search?q=Letters&lang=de&page=2&kind=TopCollection&scope=main');
    checkDiscovery($result['total'] === 41 && $result['has_next'] && $result['has_previous'], 'Search pagination is incorrect.');
    checkDiscovery(array_column($result['results'], 'id') === ['1', '2'], 'Search returned non-matches or incorrect order.');
    checkDiscovery($result['results'][1]['title'] === 'Zweite Sammlung', 'The German title was not selected.');
    checkDiscovery(str_contains($result['results'][0]['description'], '"quotes", é and 😀'), 'RDF literal escaping or Unicode was lost.');
    checkDiscovery($result['results'][0]['matches'][0]['value'] === 'Letters with quotes', 'Match snippet contains highlight markup.');
    $parent = array_values(array_filter($result['results'][1]['facts'], static fn (array $fact): bool =>
        $fact['property'] === SearchService::SCHEMA . 'isPartOf'))[0];
    checkDiscovery($parent['label'] === 'Related record only' && $parent['is_uri'], 'Linked metadata was not resolved.');

    $upstream = $history[0]['request'];
    parse_str((string) $upstream->getBody(), $parameters);
    checkDiscovery(
        $upstream->getMethod() === 'POST' && (string) $upstream->getUri() === $baseUrl . '/search',
        'The search request did not use the configured ARCHE URL.',
    );
    checkDiscovery(count($parameters['property'][0]) === 5 && $parameters['offset'] === '20', 'Incorrect upstream scope or offset.');
    checkDiscovery($parameters['value'][1] === SearchService::SCHEMA . 'TopCollection', 'Record type was not sent to ARCHE.');
    checkDiscovery(str_contains($parameters['value'][0], '"Briefe"'), 'Vocabulary expansion was not sent to ARCHE.');

    $record = $request('/records/2?lang=en');
    checkDiscovery($record['title'] === 'Second collection' && $record['id'] === '2', 'English record metadata was not returned.');
    $metadataUri = $history[1]['request']->getUri()->withQuery('');
    checkDiscovery((string) $metadataUri === $baseUrl . '/2/metadata', 'Incorrect upstream metadata endpoint.');

    $empty = $request('/search', 200, 'POST', '{"q":"Letters OR Maps","mode":"original","scope":"title"}');
    checkDiscovery($empty['results'] === [] && $empty['pages'] === 0 && $empty['expression'] === 'Letters OR Maps', 'Original mode or empty result handling is incorrect.');
    $request('/search?lang=fr&q=Letters', 400);
    $request('/search?q=Letters&page=1001', 400);
    $request('/search', 400, 'POST', '{');
    $request('/search', 415, 'POST', 'q=Letters', 'application/x-www-form-urlencoded');
    $request('/records/not-an-id', 400);
    $request('/records/999', 404);
    $request('/search?q=Letters', 503);
    $request('/search?q=Letters', 502);

    file_put_contents($source, '<https://example.org/1> <https://vocabs.acdh.oeaw.ac.at/schema#hasSubject> broken .');
    $previousSnapshot = hash_file('sha256', $vocabularyPath);
    $invalidBuildRejected = false;
    try {
        $builder->build($source, $vocabularyPath);
    } catch (RuntimeException) {
        $invalidBuildRejected = true;
    }
    checkDiscovery(
        $invalidBuildRejected && hash_file('sha256', $vocabularyPath) === $previousSnapshot,
        'A failed import must preserve the published snapshot.',
    );

    unlink($vocabularyPath);
    $missing = $request('/vocabulary?q=Letters');
    checkDiscovery($missing['stats'] === null && !$missing['analysis']['available'], 'A missing snapshot did not degrade gracefully.');

    $fallback = $request('/search?q=Letters');
    checkDiscovery(!$fallback['vocabulary']['available'] && $fallback['expression'] === '"Letters"', 'Search must work without a vocabulary snapshot.');

    echo "Discovery API checks passed.\n";
} finally {
    $kernel->shutdown();
    (new Filesystem())->remove($directory);
}
