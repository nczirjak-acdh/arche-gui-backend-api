<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Reads the import snapshot. Co-occurrence suggestions do not expand the query. */
final class Vocabulary
{
    public const DEFAULT_PATH = 'var/discovery/topics.sqlite';

    public readonly string $path;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        #[Autowire('%env(default::ARCHE_DISCOVERY_VOCABULARY)%')] ?string $path = null,
    ) {
        $path = $path ?: self::DEFAULT_PATH;
        $this->path = str_starts_with($path, '/') ? $path : $projectDir . '/' . $path;
    }

    public static function normalize(string $text): string
    {
        return preg_replace('/\p{Mn}/u', '', strtr(mb_strtolower(trim($text), 'UTF-8'), [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        ]));
    }

    public function stats(): ?array
    {
        $db = $this->connection();
        if ($db === null) {
            return null;
        }

        $stats = [];
        foreach ($db->query('SELECT key, value FROM meta') as $row) {
            $stats[$row['key']] = json_decode($row['value'], true, flags: JSON_THROW_ON_ERROR);
        }
        // Filesystem paths on the server are not part of the public contract.
        if (isset($stats['source'])) {
            $stats['source_name'] = basename($stats['source']);
            unset($stats['source']);
        }
        return $stats;
    }

    public function analyze(string $query): array
    {
        $db = $this->connection();
        $result = [
            'topics' => [],
            'expansions' => [],
            'evidence' => [],
            'related_suggestions' => [],
            'available' => $db !== null,
        ];
        if ($db === null || $query === '') {
            return $result;
        }

        $lookup = $db->prepare(<<<'SQL'
            SELECT id, label, lang, record_count
            FROM terms
            WHERE normalized = ? AND lang IN ('en', 'de')
            ORDER BY record_count DESC, id
            LIMIT 20
            SQL);
        $matches = [];
        foreach ($this->phrases($query) as $phrase) {
            $lookup->execute([$phrase]);
            foreach ($lookup->fetchAll() as $term) {
                $matches[$term['id']] = $term;
            }
        }

        $suggestions = [];
        $expansions = [];
        $matches = array_slice($matches, 0, 20, true);
        foreach ($matches as $term) {
            $id = (int) $term['id'];
            $result['topics'][] = $term['label'];
            $expansions[$term['label']] = true;
            $result['evidence'][] = $term + [
                'matched_via' => 'source_label',
                'provenance' => [
                    'subject_examples' => $this->rows($db, 'SELECT record, property, topic_uri FROM usage WHERE term_id = ? ORDER BY record LIMIT 3', [$id]),
                    'concept_examples' => $this->rows($db, 'SELECT uri, label_property FROM concept_labels WHERE term_id = ? ORDER BY uri LIMIT 3', [$id]),
                ],
            ];

            foreach ($this->aliases($db, $id) as $alias) {
                if (count($expansions) < 12 && mb_strlen($alias['label'], 'UTF-8') <= 120) {
                    $expansions[$alias['label']] = true;
                }
                $result['evidence'][] = $alias + [
                    'matched_via' => 'rdf_alias',
                    'from_term' => $term['label'],
                ];
            }

            foreach ($this->related($db, $id) as $related) {
                $relatedId = $related['id'];
                if (!isset($matches[$relatedId]) &&
                    $related['shared_records'] > ($suggestions[$relatedId]['shared_records'] ?? 0)) {
                    $suggestions[$relatedId] = $related + [
                        'relation' => 'cooccurrence',
                        'from_term' => $term['label'],
                    ];
                }
            }
        }

        usort($suggestions, static fn (array $a, array $b): int =>
            ($b['shared_records'] <=> $a['shared_records']) ?: ($a['id'] <=> $b['id']));
        $result['topics'] = array_values(array_unique($result['topics']));
        $result['expansions'] = array_slice(array_values(array_filter(
            array_map('strval', array_keys($expansions)),
            static fn (string $term): bool => mb_strlen($term, 'UTF-8') <= 120,
        )), 0, 12);
        $result['evidence'] = array_slice($result['evidence'], 0, 60);
        $result['related_suggestions'] = array_slice($suggestions, 0, 8);
        return $result;
    }

    private function connection(): ?PDO
    {
        if (!is_file($this->path)) {
            return null;
        }
        // Open the current snapshot for each operation so atomic rebuilds are visible.
        $uri = str_replace('%2F', '/', rawurlencode($this->path));
        return new PDO('sqlite:file:' . $uri . '?mode=ro', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function phrases(string $query): array
    {
        $normalized = self::normalize($query);
        preg_match_all('/[^\W_]+/u', $normalized, $matches);
        $words = array_slice($matches[0], 0, 40);
        $phrases = [$normalized];
        for ($start = 0; $start < count($words); ++$start) {
            for ($length = 1; $length <= min(6, count($words) - $start); ++$length) {
                $phrases[] = implode(' ', array_slice($words, $start, $length));
            }
        }
        return array_unique($phrases);
    }

    private function aliases(PDO $db, int $id): array
    {
        return $this->rows($db, <<<'SQL'
            SELECT t.id, t.label, t.lang, t.record_count, a.kind, a.evidence
            FROM aliases a
            JOIN terms t ON t.id = CASE WHEN a.a = ? THEN a.b ELSE a.a END
            WHERE (a.a = ? OR a.b = ?) AND t.lang IN ('en', 'de')
            ORDER BY t.record_count DESC, t.id
            LIMIT 20
            SQL, [$id, $id, $id]);
    }

    private function related(PDO $db, int $id): array
    {
        return $this->rows($db, <<<'SQL'
            SELECT t.id, t.label, t.lang, t.record_count, r.shared_records, r.confidence
            FROM related r
            JOIN terms t ON t.id = CASE WHEN r.a = ? THEN r.b ELSE r.a END
            WHERE (r.a = ? OR r.b = ?) AND t.lang IN ('en', 'de')
            ORDER BY r.confidence DESC, r.shared_records DESC, t.id
            LIMIT 8
            SQL, [$id, $id, $id]);
    }

    private function rows(PDO $db, string $sql, array $parameters): array
    {
        $statement = $db->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }
}
