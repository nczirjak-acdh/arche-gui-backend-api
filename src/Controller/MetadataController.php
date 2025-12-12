<?php

namespace App\Controller;

use App\Service\ArcheContext;
use App\Service\MetadataService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

class MetadataController extends \App\Controller\ArcheBaseController {

    protected $helper;
    private $acceptedFormatsHelper;

    
    public function __construct(ArcheContext $arche, RequestStack $rs, private MetadataService $metadataService)
    {
        parent::__construct($arche, $rs);       
    }
    
 
    /**
     * Home Page slider data
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getTopCollections(int $count, string $lang = 'en'): JsonResponse
    {
        $data = $this->metadataService->getTopCollections($count, $lang ?: $this->siteLang);
        return $data;
    }
    
    /**
     * Metadata view expert content
     * @param string $id
     * @param string $lang
     * @return JsonResponse
     */
    public function getExpertData(string $id, string $lang = "en"): JsonResponse {        
        $data = $this->metadataService->getExpertData($id, $lang ?: $this->siteLang);
        return $data;
    }
  
    
    /**
     * Get the actual resource breadcrumb
     * @param string $id
     * @param string $lang
     * @return JsonResponse
     */
    public function getBreadcrumb(string $id, string $lang = "en"): JsonResponse {
        $data = $this->metadataService->getBreadcrumb($id, $lang ?: $this->siteLang);
        return $data;
        
    }
    
    
    
    
    
    
    
    /*** OLD CODES ***/
    
    
       /**
     * MAP search coorindates
     * @param string $lang
     * @return JsonResponse
     */
    public function getSearchCoordinates(string $lang = "en"): JsonResponse {
        $result = [];

        $schema = $this->repoDb->getSchema();
        $scfg = new \acdhOeaw\arche\lib\SearchConfig();
        //$scfg->orderBy = ['^' . $schema->modificationDate];
        //$scfg->limit = $count;
        $scfg->metadataMode = 'resource';

        $scfg->resourceProperties = [
            (string) $schema->label,
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasLatitude',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasLongitude',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasWKT',
            (string) $schema->id
        ];

        $properties = [
            (string) $schema->label => 'title',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasLatitude' => 'lat',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasLongitude' => 'lon',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasWKT' => 'wkt'
        ];
        $scfg->relativesProperties = [];
        $pdoStmt = $this->repoDb->getPdoStatementBySearchTerms([new \acdhOeaw\arche\lib\SearchTerm(RC::RDF_TYPE, 'https://vocabs.acdh.oeaw.ac.at/schema#Place')], $scfg);

        $result = $this->helper->extractRootView($pdoStmt, $scfg->resourceProperties, $properties, $lang);
        if (count((array) $result) == 0) {
            return new JsonResponse(array(t("There is no resource")), 404, ['Content-Type' => 'application/json']);
        }

        return new JsonResponse($result, 200, ['Content-Type' => 'application/json']);
    }
 
    
    
    

    

    /**
     * DOwnload the 3d Object file and return the url.
     * @param string $identifier
     * @return JsonResponse
     */
    public function get3dUrl(string $identifier): JsonResponse {
        $tmpDir = \Drupal::service('file_system')->realpath(\Drupal::config('system.file')->get('default_scheme') . "://");

        //download the file
        $identifier = $this->repoDb->getBaseUrl() . $identifier;
        //$identifier = 'https://arche-curation.acdh-dev.oeaw.ac.at/api/3110280';
        $obj = new \Drupal\arche_core_gui\Object\ThreeDObject();
        $fileObj = $obj->downloadFile($identifier, $tmpDir);
        $fileUrl = ($fileObj['result']) ? $fileObj['result'] : "";

        if (empty($fileUrl)) {
            return new JsonResponse("No binary", 404, ['Content-Type' => 'application/json']);
        }
        return new JsonResponse($fileUrl, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Get just the coordinates for the detail view map box
     * @param string $identifier
     * @return JsonResponse
     */
    public function getCoordinates(string $identifier): JsonResponse {

        $id = \Drupal\Component\Utility\Xss::filter(preg_replace('/[^0-9]/', '', $identifier));
        $lang = "en";
        if (empty($id)) {
            return new JsonResponse(array(t("Please provide an id")), 404, ['Content-Type' => 'application/json']);
        }

        $result = [];

        try {
            $res = new \acdhOeaw\arche\lib\RepoResourceDb($id, $this->repoDb);
        } catch (\Exception $ex) {
            return [];
        }

        $schema = $this->repoDb->getSchema();

        $contextResource = [
            (string) $schema->label => 'title',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasLatitude' => 'lat',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasLongitude' => 'lon',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasWKT' => 'wkt'
        ];
        $contextRelatives = [
            (string) $schema->label => 'title'
        ];

        $pdoStmt = $res->getMetadataStatement(
                'resource',
                $schema->parent,
                array_keys($contextResource),
                array_keys($contextRelatives)
        );

        $result = [];
        $result = $this->helper->extractExpertView($pdoStmt, $id, $contextRelatives, $lang);

        if (count((array) $result) == 0) {
            return new JsonResponse(array(t("There is no resource")), 404, ['Content-Type' => 'application/json']);
        }

        return new JsonResponse(array($result), 200, ['Content-Type' => 'application/json']);
    }

    public function getAcceptedFormats(string $lang): Response {
        $formats = \acdhOeaw\ArcheFileFormats::getAll();
        $table = [];
        foreach ($formats as $i) {
            if (empty($i->ARCHE_conformance ?? '') || empty($i->ARCHE_category ?? '')) {
                continue;
            }
            $group = explode('/', $i->ARCHE_category);
            $group = end($group);
            $table[$group][] = [implode(', ', $i->extensions), $i->name, $i->ARCHE_conformance];
        }
        foreach ($table as &$v) {
            usort($v, fn($x, $y) => $x[0] <=> $y[0]);
        }
        unset($v);
      
        if (count($table) === 0) {
            return new \Symfony\Component\HttpFoundation\Response("There is no data", 404, ['Content-Type' => 'application/json']);
        }

        $html = "";
        $html = $this->acceptedFormatsHelper->createHtml($table, $lang);

        if (empty($html)) {
            return new \Symfony\Component\HttpFoundation\Response("There is no data", 404, ['Content-Type' => 'application/json']);
        }
        $response = new \Symfony\Component\HttpFoundation\Response();
        $response->setContent($html);
        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }
}
