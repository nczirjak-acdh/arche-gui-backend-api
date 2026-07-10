<?php

namespace App\Service;

use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use acdhOeaw\arche\lib\SmartSearch;
use quickRdf\DataFactory as DF;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use termTemplates\PredicateTemplate as PT;

class SmartSearchService
{
    private object $sConfig;
    private array $context = [];
    private \acdhOeaw\arche\lib\Schema $schema;
    private string $baseUrl;
    private string $preferredLang = 'en';
    private bool $searchInBinaries = false;
    private string $searchPhrase = '';
    private array $reqFacets = [];
    private string $requestHash = '';
    private \PDO $pdo;
    private $repoDb;
    private $baseConfig;

    public function __construct(private ArcheContext $arche, private TranslatorInterface $translator)
    {
        $this->repoDb = $this->arche->getRepoDb();
        $this->baseConfig = $this->arche->getConfig();
        $this->schema = $this->arche->getSchema();
        $this->pdo = $this->arche->getPdo();

        if (isset($this->baseConfig->asObject()->smartSearch)) {
            $this->sConfig = $this->baseConfig->asObject()->smartSearch;
        } else {
            $this->sConfig = new \stdClass();
            $this->sConfig->facets = [];
        }
    }

    /**
     * The main smart search endpoint ported from arche_core_gui_api.
     */
    public function search(array $postParams): Response
    {
        if (isset($postParams['initialFacets'])) {
            return $this->initialSearch($postParams);
        }

        $this->requestHash = md5(print_r($postParams, true));
        $msg = [];

        try {
            $this->setBasicProperties($postParams);
            $useCache = !((bool) ($postParams['noCache'] ?? false));

            if ($useCache) {
                $cached = $this->getCachedData();
                if ($cached !== null) {
                    return new Response($cached->response, Response::HTTP_OK, [
                        'x-smartsearch-cache' => (string) $cached->created,
                        'Content-Type' => 'application/json',
                    ]);
                }
            }

            $this->setContext();
            $relContext = [
                (string) $this->schema->label => 'title',
                (string) $this->schema->parent => 'parent',
            ];

            $facets = $this->getConfiguredFacets();
            if (!((bool) ($postParams['linkNamedEntities'] ?? true))) {
                $facets = array_values(array_filter($facets, fn($x) => $x->type !== SmartSearch::FACET_LINK));
            }

            $specialFacets = [SmartSearch::FACET_MAP, SmartSearch::FACET_LINK, SmartSearch::FACET_MATCH];
            $searchTerms = [];
            $spatialSearchTerm = null;
            $allowedProperties = [];
            $facetsInUse = [];

            foreach ($facets as $facet) {
                $fid = in_array($facet->type, $specialFacets, true) ? $facet->type : $facet->property;
                $requested = $this->reqFacets[$fid] ?? null;

                if (!is_array($requested) && !($fid === SmartSearch::FACET_MAP && isset($this->reqFacets[$fid]))) {
                    continue;
                }

                $facetsInUse[] = $fid;
                if ($facet->type === SmartSearch::FACET_LINK) {
                    foreach ($requested as $i) {
                        $facet->weights->$i ??= 1.0;
                    }
                    foreach (array_diff(array_keys(get_object_vars($facet->weights)), $requested) as $i) {
                        unset($facet->weights->$i);
                    }
                    $facet->defaultWeight = 0.0;
                    continue;
                }

                if ($facet->type === SmartSearch::FACET_MATCH) {
                    $allowedProperties = $requested;
                    continue;
                }

                if ($facet->type === SmartSearch::FACET_CONTINUOUS) {
                    if (!empty($requested['min'])) {
                        $facet->min = (int) $requested['min'];
                        $searchTerms[] = new SearchTerm($facet->end, $facet->min, '>=', type: SearchTerm::TYPE_NUMBER);
                    }
                    if (!empty($requested['max'])) {
                        $facet->max = (int) $requested['max'];
                        $searchTerms[] = new SearchTerm($facet->start, $facet->max, '<=', type: SearchTerm::TYPE_NUMBER);
                    }
                    if (isset($facet->min) || isset($facet->max)) {
                        foreach ($facet->start as $n => $property) {
                            $this->context[$property] = "|min|$fid|$n";
                        }
                        foreach ($facet->end as $n => $property) {
                            $this->context[$property] = "|max|$fid|$n";
                        }
                    }
                    $facet->distribution = (bool) ($requested['distribution'] ?? false);
                    continue;
                }

                if ($facet->type === SmartSearch::FACET_MAP) {
                    $spatialSearchTerm = new SearchTerm(value: $requested, operator: '&&');
                    continue;
                }

                if (count($requested) > 0) {
                    $type = $facet->type === SmartSearch::FACET_OBJECT ? SearchTerm::TYPE_RELATION : null;
                    $searchTerms[] = new SearchTerm($fid, array_values($requested), type: $type);
                }
            }
            unset($facet);

            $search = $this->repoDb->getSmartSearch();
            $this->setQueryLogIfAvailable($search);
            $search->setExactWeight($this->sConfig->exactMatchWeight ?? 1.5);
            $search->setLangWeight($this->sConfig->langMatchWeight ?? 1.1);
            $search->setFacets($facets);

            $searchIn = $postParams['searchIn'] ?? [];
            $search->search(
                $this->searchPhrase,
                $this->preferredLang,
                $this->searchInBinaries,
                $allowedProperties,
                $searchTerms,
                $spatialSearchTerm,
                $searchIn,
                $this->sConfig->matchesLimit ?? 10000
            );

            $facetsLang = !empty($postParams['labelsLang'])
                ? $postParams['labelsLang']
                : (!empty($postParams['preferredLang']) ? $postParams['preferredLang'] : ($this->sConfig->prefLang ?? 'en'));
            $emptySearch = empty($this->searchPhrase) && count($searchTerms) === 0 && $spatialSearchTerm === null && count($searchIn) === 0;
            $facetStats = $emptySearch
                ? $search->getInitialFacets($facetsLang, $this->sConfig->facetsCache ?? '')
                : $search->getSearchFacets($facetsLang);

            $page = (int) ($postParams['page'] ?? 1);
            $resourcesPerPage = (int) ($postParams['pageSize'] ?? 20);
            $cfg = $this->createSearchConfig($relContext, $postParams);

            $triplesIterator = $search->getSearchPage($page, $resourcesPerPage, $cfg, $this->sConfig->prefLang ?? 'en');
            $totalCount = 0;
            $resources = $this->triples2resourceObjects($triplesIterator, $totalCount);
            $resources = array_filter($resources, fn($x) => isset($x->matchOrder));

            $order = array_map(fn($x) => (int) $x->matchOrder[0], $resources);
            array_multisort($order, $resources);

            $facetsById = array_combine(array_map(fn($x) => $x->property ?? $x->type, $facets), $facets);
            $this->postprocessResources($resources);
            $this->postprocessContinuousFacetMatches($resources, $facetsById);

            uasort($facetStats, fn($a, $b) => in_array($b->property, $facetsInUse, true) <=> in_array($a->property, $facetsInUse, true));

            $searchInRes = null;
            if (count($searchIn) > 0) {
                $searchInRes = $this->getSearchInResources($searchIn, $cfg);
            }

            $allPins = null;
            if ($spatialSearchTerm !== null) {
                $allPins = $search->getInitialFacets($this->preferredLang, $this->sConfig->facetsCache ?? '');
                $allPins = array_filter($allPins, fn($x) => $x->type === SmartSearch::FACET_MAP);
                $allPins = (reset($allPins) ?: null)?->values;
            }

            $messages = $this->buildMessages($facetsLang, $emptySearch);
            if (!empty($messages)) {
                $msg = reset($messages);
            } elseif (empty($msg)) {
                $msg = ['message' => '', 'class' => ''];
            }

            $fullResponse = [
                'facets' => $facetStats,
                'results' => $resources,
                'totalCount' => $emptySearch ? -1 : $totalCount,
                'maxCount' => $emptySearch ? -1 : ($this->sConfig->matchesLimit ?? 10000),
                'page' => $page,
                'messages' => $msg['message'] ?? '',
                'class' => $msg['class'] ?? '',
                'pageSize' => $resourcesPerPage,
                'searchIn' => $searchInRes,
                'allPins' => $allPins,
            ];

            $encoded = json_encode($fullResponse, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                return new Response('Error in search! Failed to encode response', Response::HTTP_INTERNAL_SERVER_ERROR, ['Content-Type' => 'application/json']);
            }

            if ($useCache) {
                $this->cacheResults($encoded);
            }

            return new Response($encoded, Response::HTTP_OK, [
                'x-smartsearch-cache' => 'none',
                'Content-Type' => 'application/json',
            ]);
        } catch (\Throwable $e) {
            return new Response('Error in search! ' . $e->getMessage(), Response::HTTP_NOT_FOUND, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Search input autocomplete.
     */
    public function smartSearchAutoComplete(string $str): JsonResponse
    {
        $response = [];
        $q = $str ?? '';
        if (!empty($q)) {
            $limit = $this->sConfig->autocomplete?->count ?? 10;
            $maxLength = $this->sConfig->autocomplete?->maxLength ?? 50;

            $weights = array_filter($this->getConfiguredFacets(), fn($x) => $x->type === SmartSearch::FACET_MATCH);
            $weights = reset($weights) ?: new \stdClass();
            $weights->weights ??= ['_' => 0.0];
            $weights->defaultWeight ??= 1.0;

            $query = new \zozlak\queryPart\QueryPart('WITH weights (property, weight) AS (VALUES ');
            foreach ($weights->weights as $k => $v) {
                $query->query .= '(?::text, ?::float),';
                $query->param[] = $k;
                $query->param[] = $v;
            }
            $query->query = substr($query->query, 0, -1) . ')';
            $query->query .= "
                SELECT DISTINCT value FROM (
                    SELECT *
                    FROM metadata LEFT JOIN weights USING (property)
                    WHERE value ILIKE ? AND length(value) < ?
                    ORDER BY coalesce(weight, ?) DESC, value
                ) t LIMIT ?
            ";
            $query->param[] = $q . '%';
            $query->param[] = $maxLength;
            $query->param[] = $weights->defaultWeight;
            $query->param[] = $limit;

            $pdoStatement = $this->pdo->prepare($query->query);
            $pdoStatement->execute($query->param);
            $response = $pdoStatement->fetchAll(\PDO::FETCH_COLUMN);

            $limit -= count($response);
            if ($limit > 0) {
                $query->param[count($query->param) - 4] = '%' . $q . '%';
                $pdoStatement->execute($query->param);
                $response = array_merge($response, $pdoStatement->fetchAll(\PDO::FETCH_COLUMN));
            }
        }

        return new JsonResponse($response);
    }

    public function dateFacets(): Response
    {
        try {
            return new JsonResponse($this->sConfig->dateFacets ?? []);
        } catch (\Throwable) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Content-Type' => 'application/json']);
        }
    }

    private function initialSearch(array $postParams): Response
    {
        try {
            $this->setBasicProperties($postParams);
            $search = $this->repoDb->getSmartSearch();
            $search->setFacets($this->getConfiguredFacets());
            $forceRefresh = (bool) ($postParams['noCache'] ?? false);

            $response = [
                'facets' => $search->getInitialFacets($this->preferredLang, $this->sConfig->facetsCache ?? '', $forceRefresh),
                'results' => [],
                'totalCount' => -1,
                'page' => 0,
                'pageSize' => 0,
                'maxCount' => -1,
            ];

            return new JsonResponse($response);
        } catch (\Throwable $e) {
            return new Response('Error in search! ' . $e->getMessage(), Response::HTTP_NOT_FOUND, ['Content-Type' => 'application/json']);
        }
    }

    private function setContext(): void
    {
        $this->context = [
            (string) $this->schema->label => 'title',
            (string) $this->schema->namespaces->rdfs . 'type' => 'class',
            (string) $this->schema->modificationDate => 'availableDate',
            (string) $this->schema->accessRestriction => 'accessRestriction',
            (string) $this->schema->accessRestrictionAgg => 'accessRestrictionSummary',
            (string) $this->schema->ontology->description => 'description',
            (string) $this->schema->searchFts => 'matchHiglight',
            (string) $this->schema->searchMatch => 'matchProperty',
            (string) $this->schema->searchWeight => 'matchWeight',
            (string) $this->schema->searchOrder => 'matchOrder',
            (string) $this->schema->parent => 'parent',
        ];
    }

    private function setBasicProperties(array $postParams): void
    {
        $this->baseUrl = $this->repoDb->getBaseUrl();
        $this->preferredLang = $postParams['preferredLang'] ?? $this->sConfig->prefLang ?? 'en';
        $this->searchInBinaries = (bool) ($postParams['includeBinaries'] ?? false);
        $this->searchPhrase = $postParams['q'] ?? '';
        $this->reqFacets = $postParams['facets'] ?? [];
    }

    private function getConfiguredFacets(): array
    {
        return array_values((array) ($this->sConfig->facets ?? []));
    }

    private function createSearchConfig(array $relContext, array $postParams): SearchConfig
    {
        $cfg = new SearchConfig();
        $cfg->metadataMode = '0_99_1_0';
        $cfg->metadataParentProperty = (string) $this->schema->parent;
        $cfg->resourceProperties = array_keys($this->context);
        $cfg->relativesProperties = array_keys($relContext);
        $cfg->orderBy = [$this->sConfig->fallbackOrderBy ?? (string) $this->schema->label];

        if (isset($postParams['facets']) && count($postParams['facets']) === 1) {
            $rdf = $this->baseConfig->schema->namespaces->rdfs . 'type';
            if (array_key_exists($rdf, $postParams['facets']) &&
                ($postParams['facets'][$rdf][0] ?? null) === $this->baseConfig->schema->classes->topCollection) {
                $cfg->orderBy = ['^' . $this->baseConfig->schema->creationDate];
            }
        }

        return $cfg;
    }

    private function setQueryLogIfAvailable(SmartSearch $search): void
    {
        if (empty($this->sConfig->log) || !class_exists(\zozlak\logging\Log::class)) {
            return;
        }

        if (file_exists($this->sConfig->log)) {
            @unlink($this->sConfig->log);
        }

        $search->setQueryLog(new \zozlak\logging\Log($this->sConfig->log));
    }

    private function postprocessResources(array $resources): void
    {
        foreach ($resources as $resource) {
            $resource->url = $this->baseUrl . $resource->id;
            $resource->matchProperty ??= [];
            $resource->matchHiglight ??= array_fill(0, count($resource->matchProperty), '');
        }
    }

    private function postprocessContinuousFacetMatches(array $resources, array $facets): void
    {
        foreach ($resources as $resource) {
            foreach (get_object_vars($resource) as $property => $value) {
                if (!str_starts_with($property, '|')) {
                    continue;
                }

                $value = array_map(fn($x) => (int) $x, $value);
                list(, $valuePart, $facetId, $n) = explode('|', $property);
                $rangeProperty = $valuePart === 'min' ? 'min' : 'max';
                $facet = $facets[$facetId] ?? null;

                if ($facet !== null && isset($facet->$rangeProperty)) {
                    $propertyPart = $valuePart === 'min' ? 'start' : 'end';
                    $resource->matchProperty[] = $facet->$propertyPart[$n];
                    $resource->matchHiglight[] = $valuePart === 'min' ? min($value) : max($value);
                }

                unset($resource->$property);
            }
        }
    }

    private function triples2resourceObjects(iterable $triplesIterator, ?int &$totalCount = null): array
    {
        $resources = [];
        foreach ($triplesIterator as $triple) {
            if ($triple->property === (string) $this->schema->searchCount) {
                $totalCount = (int) $triple->value;
                continue;
            }

            $property = $this->context[$triple->property] ?? false;
            if (!$property) {
                continue;
            }

            $id = (string) $triple->id;
            $resources[$id] ??= (object) ['id' => $triple->id];
            if ($triple->type === 'REL') {
                $targetId = (string) $triple->value;
                $resources[$targetId] ??= (object) ['id' => (int) $targetId];
                $resources[$id]->{$property}[] = $resources[$targetId];
            } elseif (!empty($triple->lang)) {
                $resources[$id]->{$property}[$triple->lang] = $triple->value;
            } else {
                $resources[$id]->{$property}[] = $triple->value;
            }
        }

        return $resources;
    }

    private function getSearchInResources(array $searchIn, SearchConfig $cfg): array
    {
        $searchInQuery = 'SELECT * FROM (VALUES ' . substr(str_repeat(', (?::bigint)', count($searchIn)), 2) . ') t (id)';
        $searchInQuery = $this->repoDb->getPdoStatementBySqlQuery($searchInQuery, $searchIn, $cfg);
        $searchInResources = $this->triples2resourceObjects($searchInQuery->fetchAll(\PDO::FETCH_OBJ));
        $searchInIds = array_map('strval', $searchIn);
        $searchInResources = array_filter($searchInResources, fn($id) => in_array((string) $id, $searchInIds, true), ARRAY_FILTER_USE_KEY);
        $this->postprocessResources($searchInResources);

        return array_values($searchInResources);
    }

    private function buildMessages(string $facetsLang, bool $emptySearch): array
    {
        $messages = [];
        foreach ($this->sConfig->warnings ?? [] as $warning) {
            $dataset = new \quickRdf\Dataset(false);
            $subject = DF::namedNode('subject');

            foreach ($this->reqFacets as $property => $values) {
                $values = is_array($values) ? $values : [$values];
                $dataset->add(array_map(fn($x) => DF::Quad($subject, DF::namedNode($property), DF::literal($x)), $values));
            }

            $outerMatch = true;
            foreach ($warning->match as $matchGroup) {
                $groupMatch = false;
                foreach ($matchGroup as $property => $value) {
                    if (str_starts_with((string) $value, '!')) {
                        $groupMatch = $groupMatch || !$dataset->copy(new PT($property))->every(new PT($property, substr($value, 1)));
                    } else {
                        $groupMatch = $groupMatch || $dataset->any(new PT($property, $value));
                    }
                }
                $outerMatch = $outerMatch && $groupMatch;
            }

            if ($outerMatch) {
                $message = (array) $warning->message;
                $messages[] = [
                    'message' => $message[$facetsLang] ?? $message['en'] ?? reset($message),
                    'class' => 'bg-' . ($warning->severity ?? 'error'),
                ];
            }
        }

        if ($emptySearch && isset($this->sConfig->emptySearchMessage)) {
            $message = (array) $this->sConfig->emptySearchMessage;
            $messages[] = [
                'message' => $message[$facetsLang] ?? $message['en'] ?? reset($message),
                'class' => 'bg-info',
            ];
        }

        return $messages;
    }

    private function cacheResults(string $result): bool
    {
        try {
            $query = $this->pdo->prepare('INSERT INTO gui.search_cache VALUES (?, ?, now(), now())');
            $query->execute([$this->requestHash, $result]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function getCachedData(): \stdClass|null
    {
        try {
            $query = $this->pdo->prepare('DELETE FROM gui.search_cache WHERE now() - requested > ?::interval');
            $query->execute([$this->sConfig->cacheTimeout ?? '16 hours']);

            $query = $this->pdo->prepare('UPDATE gui.search_cache SET requested = now() WHERE hash = ? RETURNING response, created');
            $query->execute([$this->requestHash]);
            $result = $query->fetchObject();

            if ($result !== false) {
                return $result;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
