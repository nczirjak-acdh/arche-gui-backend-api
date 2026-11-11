<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Discovery\ArcheClient;
use App\Service\Discovery\SearchInput;
use App\Service\Discovery\SearchService;
use App\Service\Discovery\UpstreamError;
use App\Service\Discovery\Vocabulary;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/discovery', name: 'arche_discovery_', format: 'json')]
final class DiscoveryController
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly Vocabulary $vocabulary,
        private readonly ArcheClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Search public ARCHE records with optional vocabulary expansion. */
    #[Route('/search', name: 'search', methods: ['GET', 'POST'])]
    public function search(Request $request): JsonResponse
    {
        if ($request->isMethod('POST') && $request->getContentTypeFormat() !== 'json') {
            return $this->json(['error' => 'Send an application/json request body.'], 415);
        }

        return $this->respond(function () use ($request): array {
            $input = $request->isMethod('POST') ? $request->toArray() : $request->query->all();
            return $this->searchService->search(SearchInput::fromArray($input));
        });
    }

    /** Retrieve metadata for an ARCHE record, using the selected display language. */
    #[Route('/records/{id}', name: 'record', methods: ['GET'])]
    public function record(string $id, Request $request): JsonResponse
    {
        return $this->respond(fn (): array =>
            $this->searchService->metadata($id, SearchInput::language($request->query->all())));
    }

    /** Inspect recognized vocabulary terms, their provenance, and related suggestions. */
    #[Route('/vocabulary', name: 'vocabulary', methods: ['GET'])]
    public function vocabulary(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $query = trim(SearchInput::string($request->query->all(), 'q', ''));
            return [
                'stats' => $this->vocabulary->stats(),
                'analysis' => $this->vocabulary->analyze($query),
            ];
        });
    }

    /** Return supported filters and the current vocabulary snapshot information. */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json([
            'source' => 'ARCHE API',
            'endpoint' => $this->client->baseUrl . '/search',
            'kinds' => SearchInput::KINDS,
            'modes' => SearchInput::MODES,
            'scopes' => array_keys(SearchInput::SCOPES),
            'languages' => SearchInput::LANGUAGES,
            'page_size' => SearchInput::PAGE_SIZE,
            'max_page' => SearchInput::MAX_PAGE,
            'vocabulary' => $this->vocabulary->stats(),
        ]);
    }

    /** Supply the curated query labels for a separate client application. */
    #[Route('/examples', name: 'examples', methods: ['GET'])]
    public function examples(): JsonResponse
    {
        $groups = [
            'en' => [
                'Letters' => 'Letters',
                'Pottery fragments' => 'pottery fragments',
                'Pre-Islamic inscriptions' => 'Pre-Islamic inscriptions',
                'Ancient South Arabic languages' => 'Ancient South Arabic languages',
                'Historic administrative units' => 'historic administrative units',
                'Musical sources' => 'musical sources',
                'Multilingualism' => 'multilingualism',
                'Second World War' => 'Second world war',
            ],
            'de' => [
                'Historische Karten' => 'historische Karten',
                'Manuskripte' => 'Manuskripte (Dokumente)',
                'Archäologie' => 'Archäologie',
                'Geschichte' => 'Geschichte',
                'Dokumentarfotografien' => 'Dokumentarfotografien',
                'Geschäftsbriefe' => 'Geschäftsbriefe',
                'Aufmaßzeichnungen' => 'Aufmaßzeichnungen',
            ],
        ];

        $examples = [];
        foreach ($groups as $language => $queries) {
            foreach ($queries as $label => $query) {
                $examples[] = ['label' => $label, 'q' => $query, 'lang' => $language];
            }
        }

        return $this->json(['examples' => $examples]);
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return $this->json($operation());
        } catch (JsonException) {
            return $this->json(['error' => 'Request body must be a valid JSON object.'], 400);
        } catch (InvalidArgumentException $error) {
            return $this->json(['error' => $error->getMessage()], 400);
        } catch (UpstreamError $error) {
            $this->logger->warning('ARCHE discovery request failed.', [
                'status' => $error->status,
                'exception' => $error,
            ]);
            return $this->json(['error' => $error->getMessage()], $error->status);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(status: $status, headers: ['Cache-Control' => 'no-store']);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $response->setData($data);
    }
}
