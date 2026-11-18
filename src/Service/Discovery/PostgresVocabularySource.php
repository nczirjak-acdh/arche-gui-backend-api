<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use acdhOeaw\arche\lib\RepoDb;
use App\Service\ArcheContext;
use Closure;
use Generator;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Yaml\Exception\ParseException;

/** Streams public ARCHE vocabulary metadata directly from PostgreSQL. */
final class PostgresVocabularySource
{
    private const BATCH_SIZE = 5000;

    public function __construct(
        private readonly ArcheConfiguration $configuration,
        #[AutowireServiceClosure(ArcheContext::class)]
        private readonly Closure $archeContext,
    ) {
    }

    /** @return Generator<int, array{string, array{property: string, value: string, lang: string, is_uri: bool}}, mixed, array> */
    public function statements(?string $configFile = null): Generator
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The PHP PDO PostgreSQL driver (pdo_pgsql) is required.');
        }

        [$db, $config] = $this->connection($configFile);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('The ARCHE context must provide a PostgreSQL connection.');
        }
        if ($db->inTransaction()) {
            throw new RuntimeException('The ARCHE PostgreSQL connection already has an open transaction.');
        }

        $acl = $config['accessControl']['schema']['read'] ?? $config['schema']['aclRead'] ?? null;
        $role = $config['accessControl']['publicRole'] ?? $config['publicRole'] ?? null;
        if (!is_string($acl) || trim($acl) === '' || !is_string($role) || trim($role) === '') {
            throw new RuntimeException('The ARCHE configuration must specify the read ACL property and the public role.');
        }

        $url = $this->configuration->baseUrl($config) . '/';

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

    /** @return array{PDO, array} */
    private function connection(?string $configFile): array
    {
        try {
            // Open the same connection as the backend only when the PostgreSQL mode runs.
            if ($configFile === null) {
                $context = ($this->archeContext)();
                return [$context->getPdo(), $context->getConfig()->asArray()];
            }

            $config = $this->configuration->load($configFile);
            $dsn = $config['dbConnStr'] ?? $config['dbConn']['guest'] ?? null;
            if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
                throw new RuntimeException(
                    'The alternate ARCHE configuration must contain a PostgreSQL DSN in dbConnStr or dbConn.guest.',
                );
            }

            return [new PDO($dsn, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), $config];
        } catch (ParseException) {
            throw new RuntimeException('Could not read the ARCHE YAML configuration. Check the file and its syntax.');
        } catch (PDOException $error) {
            throw new RuntimeException(sprintf(
                'Could not initialize the ARCHE PostgreSQL connection (PDO code %s). %s',
                $error->getCode(),
                $this->connectionFailure($error),
            ));
        }
    }

    private function connectionFailure(PDOException $error): string
    {
        // Match the driver error, then return a fixed message to keep the DSN out of the console.
        $message = strtolower($error->getMessage());
        $sqlState = $error->errorInfo[0] ?? $error->getCode();

        return match (true) {
            str_contains($message, 'pg_hba.conf') =>
                'PostgreSQL client access rules rejected this connection. Check the CLI host and PostgreSQL access rules.',
            str_contains($message, 'no password supplied') =>
                'PostgreSQL requires a password, but this CLI process supplied none. Check the service account and its password configuration.',
            str_contains($message, 'authentication failed') =>
                'PostgreSQL authentication failed. Check the database role and credentials used by this CLI process.',
            preg_match('/\brole\b[^\r\n]*(?:does not exist|not permitted to log in)/', $message) === 1 =>
                'The PostgreSQL role is missing or cannot log in. Check which role the CLI uses.',
            $sqlState === '3D000' || preg_match('/\bdatabase\b[^\r\n]*does not exist/', $message) === 1 =>
                'The configured PostgreSQL database does not exist on the selected server.',
            $sqlState === '42501' =>
                'PostgreSQL denied access. Check the permissions of the role used by the backend.',
            str_contains($message, 'could not translate host name') =>
                'The PostgreSQL hostname could not be resolved. Run the command in the backend network or container.',
            preg_match('/\b(?:ssl|tls|certificate)\b[^\r\n]*(?:failed|error|invalid|does not|could not|no such|required)/', $message) === 1 =>
                'PostgreSQL TLS setup failed. Check the backend TLS settings and certificate files available to the CLI.',
            preg_match('/connection refused|timed out|no route to host|network is unreachable|no such file or directory/', $message) === 1 =>
                'The CLI could not reach the PostgreSQL server or socket. Check the configured host and port from the backend network or container.',
            default =>
                'Check the PostgreSQL server log and run the command in the same environment and as the same service account as the backend.',
        };
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
