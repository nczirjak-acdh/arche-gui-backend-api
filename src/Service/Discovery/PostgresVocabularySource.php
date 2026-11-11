<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use acdhOeaw\arche\lib\RepoDb;
use Generator;
use PDO;
use PDOException;
use RuntimeException;

/** Streams public ARCHE vocabulary metadata directly from PostgreSQL. */
final class PostgresVocabularySource
{
    private const BATCH_SIZE = 5000;

    public function __construct(
        private readonly ArcheConfiguration $configuration,
    ) {
    }

    /** @return Generator<int, array{string, array{property: string, value: string, lang: string, is_uri: bool}}, mixed, array> */
    public function statements(?string $configFile = null): Generator
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The PHP PDO PostgreSQL driver (pdo_pgsql) is required.');
        }

        $config = $this->configuration->load($configFile);
        $dsn = $config['dbConnStr'] ?? $config['dbConn']['guest'] ?? null;
        if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
            throw new RuntimeException('The ARCHE configuration must contain a PostgreSQL DSN in dbConnStr or dbConn.guest.');
        }

        $acl = $config['accessControl']['schema']['read'] ?? $config['schema']['aclRead'] ?? null;
        $role = $config['accessControl']['publicRole'] ?? $config['publicRole'] ?? null;
        if (!is_string($acl) || trim($acl) === '' || !is_string($role) || trim($role) === '') {
            throw new RuntimeException('The ARCHE configuration must specify the read ACL property and the public role.');
        }

        $url = $this->configuration->baseUrl($config) . '/';

        try {
            $db = new PDO($dsn, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException) {
            // The DSN can contain a password. Keep it out of console errors and snapshots.
            throw new RuntimeException('Could not connect to PostgreSQL using the ARCHE configuration.');
        }

        try {
            $db->beginTransaction();
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            // The import consumes the whole cursor, so PostgreSQL can optimize for total cost.
            $db->exec('SET LOCAL cursor_tuple_fraction = 1.0');
            $this->openCursor($db, $acl, $role);

            do {
                $batch = $db->query('FETCH FORWARD ' . self::BATCH_SIZE . ' FROM discovery_vocabulary');
                $rows = 0;
                while (($row = $batch->fetch(PDO::FETCH_ASSOC)) !== false) {
                    ++$rows;
                    $target = $row['target_id'];
                    yield [$url . $row['id'], [
                        'property' => $row['property'],
                        'value' => $target === null ? (string) $row['value'] : $url . $target,
                        'lang' => $row['lang'] ?? '',
                        'is_uri' => $row['type'] === 'URI',
                    ]];
                }
                $batch->closeCursor();
            } while ($rows === self::BATCH_SIZE);

            $db->commit();
            return [];
        } catch (PDOException $error) {
            throw new RuntimeException(sprintf(
                'PostgreSQL vocabulary import failed (SQLSTATE %s). Check the connection, schema and read permissions.',
                $error->getCode(),
            ));
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }

    private function openCursor(PDO $db, string $acl, string $role): void
    {
        // Direct database access bypasses REST authorization. Require a public read
        // grant for each active subject and for both ends of repository relations.
        $statement = $db->prepare(<<<'SQL'
            DECLARE discovery_vocabulary NO SCROLL CURSOR FOR
            WITH public_resources AS MATERIALIZED (
                SELECT DISTINCT m.id
                FROM metadata m
                JOIN resources r ON r.id = m.id
                WHERE m.property = ? AND m.value = ? AND r.state = ?
            )
            SELECT m.id, m.property, m.value, lower(m.lang) AS lang, m.type, NULL::bigint AS target_id
            FROM metadata m
            JOIN public_resources p ON p.id = m.id
            WHERE (
                m.property IN (?, ?, ?, ?, ?, ?)
                AND m.type <> 'URI'
                AND lower(m.lang) IN (?, ?)
            ) OR (
                m.property IN (?, ?, ?)
                AND m.type = 'URI'
            )

            UNION ALL

            SELECT r.id, r.property, NULL, '', 'URI', r.target_id
            FROM relations r
            JOIN public_resources p ON p.id = r.id
            JOIN public_resources t ON t.id = r.target_id
            WHERE r.property IN (?, ?, ?)
            SQL);

        $relations = [...VocabularyBuilder::SUBJECTS, VocabularyBuilder::EQUIVALENCE];
        $statement->execute([
            $acl,
            $role,
            RepoDb::RES_STATE_ACTIVE,
            ...VocabularyBuilder::SUBJECTS,
            ...VocabularyBuilder::LABELS,
            ...SearchInput::LANGUAGES,
            ...$relations,
            ...$relations,
        ]);
    }
}
