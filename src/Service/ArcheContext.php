<?php

namespace App\Service;

use acdhOeaw\arche\lib\Config;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\schema\Ontology;
use acdhOeaw\arche\lib\Schema;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ArcheContext {

    private Config $config;
    private RepoDb $repoDb;
    private Ontology $ontology;
    private PDO $pdo;
    private Schema $schema;

    public function __construct(
            #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $user = $_ENV['DB_USER'];
        $pass = $_ENV['DB_PASS'];
        
        $configFile = $projectDir . '/src/arche-config/config-gui.yaml';
        $this->config = Config::fromYaml($configFile);

        $this->pdo = new PDO($this->config->dbConnStr, $user, $pass);

        $baseUrl = $this->config->rest->urlBase . $this->config->rest->pathBase;
        $this->schema = new Schema($this->config->schema);
        $headers = new Schema($this->config->rest->headers);
        $nonRelProp = $this->config->metadataManagment->nonRelationProperties ?? [];

        $this->repoDb = new RepoDb($baseUrl, $this->schema, $headers, $this->pdo, $nonRelProp);
        $this->ontology = Ontology::factoryDb(
                        $this->pdo,
                        $this->schema,
                        $this->config->ontologyCacheFile ?? '',
                        $this->config->ontologyCacheTtl ?? 600
        );
    }

    public function getConfig(): Config {
        return $this->config;
    }

    public function getRepoDb(): RepoDb {
        return $this->repoDb;
    }

    public function getOntology(): Ontology {
        return $this->ontology;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function getSchema(): Schema {
        return $this->schema;
    }
}
