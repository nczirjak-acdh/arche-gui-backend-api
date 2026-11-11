<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use Generator;
use PDO;
use RuntimeException;

final class VocabularyBuilder
{
    private const SKOS = 'http://www.w3.org/2004/02/skos/core#';
    public const SUBJECTS = [SearchService::SCHEMA . 'hasSubject', SearchService::SCHEMA . 'hasKeyword'];
    public const LABELS = [
        SearchService::SCHEMA . 'hasTitle', SearchService::SCHEMA . 'hasAlternativeTitle',
        self::SKOS . 'prefLabel', self::SKOS . 'altLabel',
    ];
    public const EQUIVALENCE = self::SKOS . 'exactMatch';

    public function __construct(private readonly RdfReader $rdf)
    {
    }

    public function build(string $source, string $destination): array
    {
        $source = realpath($source) ?: throw new RuntimeException('RDF source is missing.');

        return $this->publish($this->rdfStatements($source), $destination, [
            'source' => $source,
            'source_size' => filesize($source),
            'source_mtime' => filemtime($source),
        ]);
    }

    public function buildFromPostgres(
        PostgresVocabularySource $source,
        string $destination,
        ?string $configFile = null,
    ): array {
        return $this->publish($source->statements($configFile), $destination, ['source_type' => 'postgresql']);
    }

    /** Publish a complete SQLite snapshot in one rename on the destination filesystem. */
    private function publish(Generator $statements, string $destination, array $sourceStats): array
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the vocabulary directory.');
        }

        $lock = fopen($destination . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the vocabulary build lock.');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('A vocabulary build is already running.');
            }
            $temporary = tempnam($directory, 'topics-');
            if ($temporary === false) {
                throw new RuntimeException('Could not create the vocabulary build file.');
            }
            $started = hrtime(true);
            $db = new PDO('sqlite:' . $temporary);
            $this->createSchema($db);
            $selected = $this->import($db, $statements);
            $this->buildTerms($db);
            $this->buildRelations($db);

            $stats = $sourceStats + $statements->getReturn() + [
                'selected_statements' => $selected,
                'languages' => SearchInput::LANGUAGES,
                'built_at' => gmdate(DATE_ATOM),
                'builder' => 'symfony-discovery-v1',
            ];
            $counts = [
                'terms' => 'SELECT count(*) FROM terms',
                'topic_statements' => 'SELECT count(*) FROM mentions',
                'terms_used_as_subject' => 'SELECT count(*) FROM terms WHERE record_count > 0',
                'topic_records' => 'SELECT count(DISTINCT record) FROM usage',
                'alias_pairs' => 'SELECT count(*) FROM (SELECT DISTINCT a, b FROM aliases)',
                'related_pairs' => 'SELECT count(*) FROM related',
                'unresolved_topic_uris' => "SELECT count(DISTINCT topic_uri) FROM mentions WHERE topic_uri <> '' AND topic_uri NOT IN (SELECT uri FROM labels)",
                'cooccurrence_skipped_records' => 'SELECT count(*) FROM (SELECT record FROM record_terms GROUP BY record HAVING count(*) > 32)',
            ];
            foreach ($counts as $key => $query) {
                $stats[$key] = (int) $db->query($query)->fetchColumn();
            }

            if (($sourceStats['source_type'] ?? null) === 'postgresql' && $stats['terms'] === 0) {
                throw new RuntimeException('No public English or German vocabulary labels were found in PostgreSQL.');
            }

            $db->exec('DROP TABLE labels; DROP TABLE mentions; DROP TABLE links; DROP TABLE record_terms; DROP TABLE bounded_records; VACUUM;');
            $stats['seconds'] = round((hrtime(true) - $started) / 1_000_000_000, 1);
            $insert = $db->prepare('INSERT INTO meta VALUES (?, ?)');
            foreach ($stats as $key => $value) {
                $insert->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
            }
            if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('The generated SQLite snapshot failed its integrity check.');
            }
            unset($insert, $db);

            // tempnam creates an owner-only file. The published snapshot is a read-only input
            // for the web process, which may run under a different account.
            if (!chmod($temporary, 0644) || !rename($temporary, $destination)) {
                throw new RuntimeException('Could not publish the vocabulary snapshot.');
            }
            return $stats;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            fclose($lock);
        }
    }

    private function rdfStatements(string $source): Generator
    {
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Could not open the RDF export.');
        }

        try {
            $wanted = array_fill_keys([...self::SUBJECTS, ...self::LABELS, self::EQUIVALENCE], true);
            $lines = 0;
            // Filter predicates before parsing so unrelated triples are skipped.
            while (($line = fgets($input)) !== false) {
                ++$lines;
                $tokens = preg_split('/\s+/', ltrim($line), 3);
                $property = substr($tokens[1] ?? '', 1, -1);
                if (isset($wanted[$property])) {
                    yield $this->rdf->triple($line, $lines);
                }
            }
            if (!feof($input)) {
                throw new RuntimeException('Could not finish reading the RDF export.');
            }

            return ['lines' => $lines];
        } finally {
            fclose($input);
        }
    }

    /** Both sources use the same vocabulary rules and SQLite schema. */
    private function import(PDO $db, iterable $statements): int
    {
        $label = $db->prepare('INSERT INTO labels VALUES (?, ?, ?, ?)');
        $mention = $db->prepare('INSERT INTO mentions VALUES (?, ?, ?, ?, ?)');
        $link = $db->prepare('INSERT INTO links VALUES (?, ?, ?)');
        $selected = 0;

        $db->beginTransaction();
        foreach ($statements as [$uri, $fact]) {
            ++$selected;
            $property = $fact['property'];
            if ($fact['is_uri']) {
                if (in_array($property, self::SUBJECTS, true)) {
                    $mention->execute([$uri, $property, '', '', $fact['value']]);
                } elseif ($property === self::EQUIVALENCE) {
                    $link->execute([$uri, $fact['value'], $property]);
                }
            } else {
                $text = trim($fact['value']);
                $language = $fact['lang'];
                if ($text !== '' && in_array($language, SearchInput::LANGUAGES, true)) {
                    if (in_array($property, self::SUBJECTS, true)) {
                        $mention->execute([$uri, $property, $text, $language, '']);
                    } elseif (in_array($property, self::LABELS, true)) {
                        $label->execute([$uri, $property, $text, $language]);
                    }
                }
            }

            if ($selected % 10000 === 0) {
                $db->commit();
                $db->beginTransaction();
            }
        }
        $db->commit();

        return $selected;
    }

    private function createSchema(PDO $db): void
    {
        $db->exec(<<<'SQL'
            PRAGMA journal_mode = OFF;
            PRAGMA synchronous = OFF;
            PRAGMA temp_store = FILE;

            CREATE TABLE labels(uri TEXT, property TEXT, label TEXT, lang TEXT);
            CREATE TABLE mentions(record TEXT, property TEXT, term TEXT, lang TEXT, topic_uri TEXT);
            CREATE TABLE links(a TEXT, b TEXT, property TEXT);
            CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT);
            CREATE TABLE terms(
                id INTEGER PRIMARY KEY, label TEXT, lang TEXT, normalized TEXT,
                record_count INTEGER DEFAULT 0, UNIQUE(label, lang)
            );
            CREATE TABLE usage(
                term_id INTEGER, record TEXT, property TEXT, topic_uri TEXT,
                PRIMARY KEY(term_id, record, property, topic_uri)
            );
            CREATE TABLE concept_labels(
                uri TEXT, term_id INTEGER, label_property TEXT, PRIMARY KEY(uri, term_id, label_property)
            );
            CREATE TABLE aliases(
                a INTEGER, b INTEGER, kind TEXT, evidence TEXT, PRIMARY KEY(a, b, kind, evidence)
            );
            CREATE TABLE related(
                a INTEGER, b INTEGER, shared_records INTEGER, confidence REAL, PRIMARY KEY(a, b)
            );
            SQL);
    }

    private function buildTerms(PDO $db): void
    {
        $db->exec(<<<'SQL'
            CREATE INDEX labels_uri ON labels(uri);
            CREATE INDEX mentions_topic ON mentions(topic_uri);

            INSERT OR IGNORE INTO terms(label, lang)
            SELECT DISTINCT term, lang FROM mentions WHERE topic_uri = '';
            INSERT OR IGNORE INTO terms(label, lang)
            SELECT DISTINCT label, lang FROM labels WHERE property IN (
                'http://www.w3.org/2004/02/skos/core#prefLabel',
                'http://www.w3.org/2004/02/skos/core#altLabel'
            );
            INSERT OR IGNORE INTO terms(label, lang)
            SELECT DISTINCT l.label, l.lang FROM labels l JOIN mentions m ON m.topic_uri = l.uri;

            INSERT OR IGNORE INTO usage
            SELECT t.id, m.record, m.property, m.topic_uri
            FROM mentions m JOIN terms t ON t.label = m.term AND t.lang = m.lang
            WHERE m.topic_uri = '';
            INSERT OR IGNORE INTO usage
            SELECT t.id, m.record, m.property, m.topic_uri
            FROM mentions m JOIN labels l ON l.uri = m.topic_uri
            JOIN terms t ON t.label = l.label AND t.lang = l.lang;
            CREATE INDEX usage_record ON usage(record, term_id);

            INSERT OR IGNORE INTO concept_labels
            SELECT DISTINCT l.uri, t.id, l.property
            FROM labels l JOIN terms t ON t.label = l.label AND t.lang = l.lang
            WHERE l.uri IN (SELECT topic_uri FROM mentions WHERE topic_uri <> '')
                OR l.uri IN (
                    SELECT uri FROM labels WHERE property IN (
                        'http://www.w3.org/2004/02/skos/core#prefLabel',
                        'http://www.w3.org/2004/02/skos/core#altLabel'
                    )
                );

            UPDATE terms SET record_count = (
                SELECT count(DISTINCT record) FROM usage WHERE term_id = terms.id
            );
            SQL);

        $update = $db->prepare('UPDATE terms SET normalized = ? WHERE id = ?');
        $db->beginTransaction();
        foreach ($db->query('SELECT id, label FROM terms', PDO::FETCH_ASSOC) as $term) {
            $update->execute([Vocabulary::normalize($term['label']), $term['id']]);
        }
        $db->commit();
        $db->exec('CREATE INDEX terms_normalized ON terms(normalized);');
    }

    private function buildRelations(PDO $db): void
    {
        // Only shared concept URIs and explicit equivalence triples are alias evidence.
        $db->exec(<<<'SQL'
            INSERT OR IGNORE INTO aliases
            SELECT a.term_id, b.term_id, 'same_concept', a.uri
            FROM concept_labels a JOIN concept_labels b ON a.uri = b.uri AND a.term_id < b.term_id;

            INSERT OR IGNORE INTO aliases
            SELECT min(a.term_id, b.term_id), max(a.term_id, b.term_id),
                   'skos_exactMatch', k.a || ' ' || k.property || ' ' || k.b
            FROM links k JOIN concept_labels a ON a.uri = k.a
            JOIN concept_labels b ON b.uri = k.b WHERE a.term_id <> b.term_id;
            CREATE INDEX aliases_b ON aliases(b);

            CREATE TABLE record_terms AS SELECT DISTINCT record, term_id FROM usage;
            CREATE INDEX record_terms_record ON record_terms(record, term_id);
            CREATE TABLE bounded_records AS
                SELECT record FROM record_terms GROUP BY record HAVING count(*) BETWEEN 2 AND 32;
            CREATE UNIQUE INDEX bounded_record_id ON bounded_records(record);

            INSERT INTO related
            SELECT a.term_id, b.term_id, count(*), 0
            FROM record_terms a JOIN record_terms b ON a.record = b.record AND a.term_id < b.term_id
            JOIN bounded_records c ON c.record = a.record
            GROUP BY a.term_id, b.term_id HAVING count(*) >= 3;

            UPDATE related SET confidence = 1.0 * shared_records / (
                SELECT min(a.record_count, b.record_count)
                FROM terms a, terms b WHERE a.id = related.a AND b.id = related.b
            );
            CREATE INDEX related_b ON related(b);
            SQL);
    }
}
