<?php

// src/Service/MetadataService.php

namespace App\Service;

use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Contracts\Translation\TranslatorInterface;

class SmartSearchService {

    protected $helper;
    private $repoDb;
    private $sConfig;
    private $baseConfig;
    protected \PDO $pdo;

    public function __construct(private ArcheContext $arche, private TranslatorInterface $translator) {
        $this->helper = $this->helper = new \App\Helper\ArcheCoreHelper();
        $this->repoDb = $this->arche->getRepoDb();
        $this->baseConfig = $this->arche->getConfig();
        
        if (isset($this->baseConfig->asArray()['smartSearch'])) {
            $this->sConfig = json_decode(json_encode($this->baseConfig->asArray()['smartSearch']), false);
        } else {
            $this->sConfig = new \stdClass();
        }
    }

    private function sanitizeArcheID(string $id): string {
        return preg_replace('/\D/', '', $id);
    }

    /**
     * Search input autocomplete
     * @param string $str
     * @return Response
     */
    public function smartSearchAutoComplete(string $str): \Symfony\Component\HttpFoundation\JsonResponse {

        $response = [];
        $q = $str ?? '';
        if (!empty($q)) {

            $limit = $this->sConfig->autocomplete?->count ?? 10;
            $maxLength = $this->sConfig->autocomplete?->maxLength ?? 50;

            $weights = array_filter($this->sConfig->facets, fn($x) => $x->type === 'matchProperty');
            $weights = reset($weights) ?: new \stdClass();
            $weights->weights ??= ['_' => 0.0];
            $weights->defaultWeight ??= 1.0;

            $query = new \zozlak\queryPart\QueryPart("WITH weights (property, weight) AS (VALUES ");
            foreach ($weights->weights as $k => $v) {
                $query->query .= "(?::text, ?::float),";
                $query->param[] = $k;
                $query->param[] = $v;
            }
            $query->query = substr($query->query, 0, -1) . ")";
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

            $limit = 0;
            
            echo "<pre>";
            var_dump($this->baseConfig->asArray()['dbConnStr']);
            echo "</pre>";

            die();
            
            if (isset($this->baseConfig->asArray()['dbConnStr']) && !empty($this->baseConfig->asArray()['dbConnStr'])) {
                $this->pdo = new \PDO($this->baseConfig->asArray()['dbConnStr']);

                $pdoStmnt = $this->pdo->prepare($query->query);
                $pdoStmnt->execute($query->param);
                $response = $pdoStmnt->fetchAll(\PDO::FETCH_COLUMN);

                $limit -= count($response);
            } 
            
            if ($limit > 0) {
                $query->param[count($query->param) - 4] = '%' . $q . '%';
                $pdoStmnt->execute($query->param);
                $response = array_merge($response, $pdoStmnt->fetchAll(\PDO::FETCH_COLUMN));
            }
        }
        return new Response(json_encode($response));
    }
}
