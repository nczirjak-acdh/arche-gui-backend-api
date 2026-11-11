<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use InvalidArgumentException;

final class SearchService
{
    public const SCHEMA = 'https://vocabs.acdh.oeaw.ac.at/schema#';
    public const RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    private const TITLE = self::SCHEMA . 'hasTitle';
    private const DESCRIPTION = self::SCHEMA . 'hasDescription';
    private const STOP_WORDS = 'the an and or of in for with find looking show me i want der die das und oder von im mit ich suche';
    private const FIELD_LABELS = [
        'hasTitle' => 'Title',
        'hasAlternativeTitle' => 'Alternative title',
        'hasDescription' => 'Description',
        'hasSubject' => 'Subject',
        'hasKeyword' => 'Keyword',
        'hasAuthor' => 'Author',
        'hasCreator' => 'Creator',
        'hasActor' => 'Actor',
        'hasContributor' => 'Contributor',
        'hasSpatialCoverage' => 'Spatial coverage',
        'hasTemporalCoverage' => 'Temporal coverage',
        'hasCoverageStartDate' => 'Coverage start',
        'hasCoverageEndDate' => 'Coverage end',
        'hasCreatedStartDate' => 'Creation start',
        'hasCreatedEndDate' => 'Creation end',
        'hasLanguage' => 'Language',
        'hasCategory' => 'Category',
        'isPartOf' => 'Parent collection',
        'hasIdentifier' => 'Identifier',
        'hasPid' => 'Persistent identifier',
        'hasLicense' => 'License',
        'hasUpdatedDate' => 'Updated at',
        'type' => 'Type',
    ];

    public function __construct(
        private readonly ArcheClient $client,
        private readonly RdfReader $rdf,
        private readonly Vocabulary $vocabulary,
    ) {
    }

    public function search(SearchInput $input): array
    {
        $started = hrtime(true);
        $vocabulary = $this->vocabulary->analyze($input->query);
        $expansions = $input->mode === 'expanded' ? $vocabulary['expansions'] : [];
        $expression = $input->mode === 'expanded'
            ? $this->expandedExpression($input->query, $expansions)
            : $input->query;
        $properties = array_map(
            static fn (string $property): string => self::SCHEMA . $property,
            SearchInput::SCOPES[$input->scope],
        );
        $offset = ($input->page - 1) * SearchInput::PAGE_SIZE;
        $parameters = $this->metadataParameters() + [
            'operator' => ['@@'],
            'value' => [$expression],
            'property' => [$properties],
            'orderBy' => [self::TITLE],
            'limit' => SearchInput::PAGE_SIZE,
            'offset' => $offset,
            'ftsStartSel' => '[[ARCHE_HIT]]',
            'ftsStopSel' => '[[/ARCHE_HIT]]',
        ];
        if ($input->kind !== '') {
            $parameters['property'][] = self::RDF_TYPE;
            $parameters['operator'][] = '=';
            $parameters['value'][] = self::SCHEMA . $input->kind;
        }

        $graph = $this->fetchGraph('POST', '/search', $parameters);
        $total = null;
        $matches = [];
        foreach ($graph as $uri => $facts) {
            foreach ($facts as $fact) {
                if ($fact['property'] === 'search://count' && ctype_digit($fact['value'])) {
                    $total = (int) $fact['value'];
                }
                if ($fact['property'] === 'search://match' && in_array($fact['value'], ['true', '1'], true)) {
                    $matches[$uri] = true;
                }
            }
        }

        // A missing match list on an in-range page signals an incomplete upstream response.
        if ($total === null || ($total > $offset && $matches === [])) {
            throw new UpstreamError('The ARCHE response is missing its results or total count.');
        }
        $results = array_map(
            fn (string $uri): array => $this->record($uri, $graph, $input->language),
            array_keys($matches),
        );
        usort($results, static fn (array $a, array $b): int =>
            (($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX)) ?: strcmp($a['uri'], $b['uri']));
        $pages = (int) ceil($total / SearchInput::PAGE_SIZE);

        return [
            'results' => $results,
            'total' => $total,
            'page' => $input->page,
            'page_size' => SearchInput::PAGE_SIZE,
            'pages' => $pages,
            'has_previous' => $input->page > 1,
            'has_next' => $input->page < min($pages, SearchInput::MAX_PAGE),
            'milliseconds' => (int) round((hrtime(true) - $started) / 1_000_000),
            'mode' => $input->mode,
            'lang' => $input->language,
            'topics' => $vocabulary['topics'],
            'expansions' => $expansions,
            'expression' => $expression,
            'vocabulary' => $vocabulary,
            'searched_properties' => $properties,
            'api_url' => $this->client->baseUrl . '/search?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            'fetched_at' => gmdate(DATE_ATOM),
            'sort' => 'title',
            'source' => 'ARCHE API',
        ];
    }

    public function metadata(string $id, string $language): array
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new InvalidArgumentException('Invalid record identifier.');
        }
        $graph = $this->fetchGraph('GET', '/' . $id . '/metadata', $this->metadataParameters());
        $uri = $this->client->baseUrl . '/' . $id;
        if (!isset($graph[$uri])) {
            throw new UpstreamError('ARCHE returned no metadata for this record.');
        }
        return $this->record($uri, $graph, $language) + ['fetched_at' => gmdate(DATE_ATOM)];
    }

    private function fetchGraph(string $method, string $path, array $parameters): array
    {
        $body = $this->client->read($method, $path, $parameters);
        try {
            return $this->rdf->graph($body);
        } catch (\Exception $error) {
            throw new UpstreamError('The ARCHE response is not valid N-Triples.', previous: $error);
        }
    }

    private function metadataParameters(): array
    {
        $properties = array_diff(array_keys(self::FIELD_LABELS), ['type']);
        return [
            'readMode' => '0_0_1_0',
            'resourceProperties' => [
                ...array_map(static fn (string $name): string => self::SCHEMA . $name, array_values($properties)),
                self::RDF_TYPE,
            ],
            'relativesProperties' => [self::TITLE],
            'format' => 'application/n-triples',
        ];
    }

    private function expandedExpression(string $query, array $expansions): string
    {
        preg_match_all('/[^\W_]+/u', $query, $matches);
        $words = array_slice(array_values(array_unique($matches[0])), 0, 40);
        $stop = explode(' ', self::STOP_WORDS);
        $contentWords = array_filter($words, static fn (string $word): bool =>
            !in_array(mb_strtolower($word, 'UTF-8'), $stop, true));
        $terms = array_unique(array_merge($contentWords ?: $words, $expansions));
        if ($terms === []) {
            throw new InvalidArgumentException('Enter at least one search term.');
        }
        return implode(' OR ', array_map(
            static fn (string $term): string => '"' . str_replace('"', ' ', $term) . '"',
            $terms,
        ));
    }

    /** Linked resources supply labels. Only matched subjects become result cards. */
    private function record(string $uri, array $graph, string $language): array
    {
        $pattern = '~^' . preg_quote($this->client->baseUrl, '~') . '/([1-9][0-9]{0,17})$~D';
        if (!preg_match($pattern, $uri, $id)) {
            throw new UpstreamError('ARCHE returned an unsupported record identifier.');
        }

        $facts = $titles = $descriptions = $kinds = $languages = $snippets = [];
        $order = PHP_INT_MAX;
        foreach ($graph[$uri] as $fact) {
            $property = $fact['property'];
            $value = $fact['value'];
            if (str_starts_with($property, 'search://')) {
                if ($property === 'search://order') {
                    $order = (int) $value;
                } elseif (preg_match('~^search://fts(Property)?([0-9]+)$~D', $property, $parts)) {
                    $snippets[$parts[2]][$parts[1] === 'Property' ? 'property' : 'text'] = $value;
                }
                continue;
            }

            if ($property === self::TITLE) {
                $titles[] = $fact;
            } elseif ($property === self::DESCRIPTION) {
                $descriptions[] = $fact;
            } elseif ($property === self::RDF_TYPE) {
                $kinds[] = $this->localName($value);
            }
            if ($fact['lang'] !== '') {
                $languages[] = $fact['lang'];
            }
            $fact['field_label'] = $this->fieldLabel($property);
            if ($fact['is_uri']) {
                $labels = array_filter($graph[$value] ?? [], static fn (array $row): bool =>
                    $row['property'] === self::TITLE);
                $fact['label'] = $this->preferred($labels, $language) ?? $this->localName($value);
            }
            $facts[] = $fact;
        }

        $description = $this->preferred($descriptions, $language) ?? '';
        return [
            'id' => $id[1],
            'uri' => $uri,
            'title' => $this->preferred($titles, $language) ?? 'ARCHE record ' . $id[1],
            'description' => $description,
            'excerpt' => mb_substr($description, 0, 500, 'UTF-8'),
            'kind' => implode(', ', array_unique($kinds)),
            'languages' => array_values(array_unique($languages)),
            'matches' => $this->matchEvidence($snippets),
            'facts' => $facts,
            'order' => $order === PHP_INT_MAX ? null : $order,
        ];
    }

    private function matchEvidence(array $snippets): array
    {
        ksort($snippets, SORT_NUMERIC);
        $matches = [];
        foreach ($snippets as $snippet) {
            if (!isset($snippet['text'])) {
                continue;
            }
            $property = $snippet['property'] ?? '';
            $text = str_replace(['[[ARCHE_HIT]]', '[[/ARCHE_HIT]]'], '', $snippet['text']);
            $matches[] = [
                'property' => $property,
                'field_label' => $this->fieldLabel($property),
                'value' => mb_substr(preg_replace('~</?b>~i', '', $text), 0, 1000, 'UTF-8'),
            ];
        }
        return array_slice($matches, 0, 4);
    }

    private function preferred(array $facts, string $language): ?string
    {
        $rank = array_flip([$language, $language === 'en' ? 'de' : 'en', '', 'und']);
        usort($facts, static fn (array $a, array $b): int =>
            ($rank[$a['lang']] ?? 4) <=> ($rank[$b['lang']] ?? 4));
        return $facts[0]['value'] ?? null;
    }

    private function localName(string $uri): string
    {
        return preg_replace('~^.*[/#]~u', '', rtrim($uri, '/'));
    }

    private function fieldLabel(string $property): string
    {
        $name = $this->localName($property);
        return $property === 'BINARY' ? 'Indexed file content' : (self::FIELD_LABELS[$name] ?? $name);
    }
}
