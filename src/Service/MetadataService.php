<?php 

// src/Service/MetadataService.php
namespace App\Service;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Contracts\Translation\TranslatorInterface;

class MetadataService
{
    protected $helper;
    private $repoDb; 
    
    public function __construct(private ArcheContext $arche, private TranslatorInterface $translator) {
        $this->helper = $this->helper = new \App\Helper\ArcheCoreHelper();
        $this->repoDb = $this->arche->getRepoDb();      
        
    }
    
    private function sanitizeArcheID(string $id): string {
        return preg_replace('/\D/', '', $id);
    }

    /**
     * Home page topcollection slider 
     * @param int $count
     * @param string $lang
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getTopCollections(int $count, string $lang): \Symfony\Component\HttpFoundation\JsonResponse
    {        
        $result = [];
        $schema = $this->repoDb->getSchema();
        $scfg = new \acdhOeaw\arche\lib\SearchConfig();
        $scfg->orderBy = ['^' . $schema->modificationDate];
        $scfg->limit = $count;
        $scfg->metadataMode = 'resource';

        $scfg->resourceProperties = [
            (string) $schema->label,
            (string) $schema->modificationDate,
            (string) $schema->creationDate,
            (string) $schema->ontology->description,
            (string) $schema->id
        ];

        $properties = [
            (string) $schema->label => 'title',
            (string) $schema->modificationDate => 'modifyDate',
            (string) $schema->creationDate => 'avDate',
            (string) $schema->ontology->description => 'description',
            (string) $schema->id => 'identifier'
        ];
        $scfg->relativesProperties = [];
        $pdoStmt = $this->repoDb->getPdoStatementBySearchTerms([new \acdhOeaw\arche\lib\SearchTerm(RC::RDF_TYPE, $schema->classes->topCollection)], $scfg);

        $result = $this->helper->extractRootView($pdoStmt, $scfg->resourceProperties, $properties, $lang);
        if (count((array) $result) == 0) {
            $message = $this->translator->trans('arche_error.no_resource', [], 'messages', $lang);
            return new JsonResponse(array($message), 404, ['Content-Type' => 'application/json']);
        }

        return new JsonResponse($result, 200, ['Content-Type' => 'application/json']);
    }
    
    /**
     * Metadata view all data
     * @param string $id
     * @param string $lang
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getExpertData(string $id, string $lang): \Symfony\Component\HttpFoundation\JsonResponse {
        $id = $this->sanitizeArcheID($id);

        if (empty($id)) {       
            $message = $this->translator->trans('arche_error.provide_id', [], 'messages', $lang);
            return new JsonResponse(array($message), 404, ['Content-Type' => 'application/json']);
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
            (string) $schema->parent => 'parent',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasAuthor' => 'author',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasCurator' => 'curator',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasLicense' => 'license',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#binarySize' => 'binarySize',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier' => 'identifiers',
            (string) \zozlak\RdfConstants::RDF_TYPE => 'class',
        ];
        $contextRelatives = [
            (string) $schema->label => 'title',
            (string) \zozlak\RdfConstants::RDF_TYPE => 'class',
            (string) $schema->parent => 'parent',
            (string) 'https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier' => 'identifiers',
        ];

        $pdoStmt = $res->getMetadataStatement(
                '0_99_1_0',
                $schema->parent,
                [],
                array_keys($contextRelatives)
        );
        $result = [];
        $result = $this->helper->extractExpertView($pdoStmt, $id, $contextRelatives, $lang);

        if (count((array) $result) == 0) {            
            $message = $this->translator->trans('arche_error.no_resource', [], 'messages', $lang);
            return new JsonResponse(array($message), 404, ['Content-Type' => 'application/json']);
        }
        
        return new JsonResponse(array("data" => $result), 200, ['Content-Type' => 'application/json']);
    }
    
    /**
     * Metadata breadcrumb based on id
     * @param string $id
     * @param string $lang
     * @return JsonResponse
     */
    public function getBreadcrumb(string $id, string $lang = "en"): JsonResponse {
        $id = $this->sanitizeArcheID($id);

        if (empty($id)) {            
            $message = $this->translator->trans('arche_error.provide_id', [], 'messages', $lang);
            return new JsonResponse(array($message), 404, ['Content-Type' => 'application/json']);
        }

        $result = [];
        try {
            $res = new \acdhOeaw\arche\lib\RepoResourceDb($id, $this->repoDb);
        } catch (\Exception $ex) {
            return [];
        }

        $schema = $this->repoDb->getSchema();
        $context = [
            (string) $schema->label => 'title',
            (string) $schema->parent => 'parent',
        ];

        $pdoStmt = $res->getMetadataStatement(
                '0_99_1_0',
                $schema->parent,
                array_keys($context),
                array_keys($context)
        );

        $breadcrumbHelper = new \App\Helper\ArcheBreadcrumbHelper();
        $result = $breadcrumbHelper->extractBreadcrumbView($pdoStmt, $id, $context, $lang);

        if (count((array) $result) == 0) {
            $message = $this->translator->trans('arche_error.no_resource', [], 'messages', $lang);
            return new JsonResponse(array($message), 404, ['Content-Type' => 'application/json']);
        }

        return new JsonResponse($result, 200, ['Content-Type' => 'application/json']);
    }
}
