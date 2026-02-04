<?php 

// src/Service/MetadataService.php
namespace App\Service;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use acdhOeaw\arche\lib\SearchConfig;
use App\Helper\ArcheCoreHelper;
use acdhOeaw\arche\lib\SearchTerm;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChildService
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
     * Child tree view API
     * @param string $id
     * @param array $searchProps
     * @param string $lang
     * @return Response
     */
    public function getChildTreeData(string $id, string $lang, array $searchProps): \Symfony\Component\HttpFoundation\JsonResponse {
         $id = $this->sanitizeArcheID($id);

        if (empty($id)) {       
            $message = $this->translator->trans('arche_error.provide_id', [], 'messages', $lang);
            return new \Symfony\Component\HttpFoundation\JsonResponse(array($message), 404, ['Content-Type' => 'application/json']);
        }

        $result = [];
        $schema = $this->repoDb->getSchema();
        $property = [(string) $schema->parent, 'http://www.w3.org/2004/02/skos/core#prefLabel'];
        
        $resContext = [
            (string) $schema->label => 'title',
            //   (string) \zozlak\RdfConstants::RDF_TYPE => 'rdftype',
            //(string) $schema->creationDate => 'avDate',
            (string) $schema->id => 'identifier',
            (string) $schema->accessRestriction => 'accessRestriction',
            (string) $schema->binarySize => 'binarysize',
            (string) $schema->fileName => 'filename',
            (string) $schema->ingest->location => 'locationpath'
        ];
        
        $searchCfg = new \acdhOeaw\arche\lib\SearchConfig();
        //$searchCfg->offset = $searchProps['offset'];
        //$searchCfg->limit = $searchProps['limit'];
        $orderby = "asc";
        if ($searchProps['order'] === 'desc') {
            $orderby = '^';
        }
        //$searchCfg->orderBy = [$orderby . (string)\zozlak\RdfConstants::RDF_TYPE => 'rdftype'];
        $searchCfg->orderBy = [(string) \zozlak\RdfConstants::RDF_TYPE => 'rdftype'];
        $searchCfg->orderByLang = $lang;
        //$searchPhrase = '170308';
        $searchPhrase = '';
        $result = $this->getChildren($id, $resContext, $orderby, $lang);

        
        //if we have metadata error #23804 , we have to prevent jstree destroy
        if(isset($result['error'])) {
            $result = [];
            $result['id'] = 0;
            $result['filename'][] = "incosistent metadata!";
            $result['identifier'][] = "none";
            $result['title'] = "incosistent metadata!";
            $result['text'] = "<span style='color: red;'>incosistent metadata!</span>";
            $result['uri'] = "none";
            $result['uri_dl'] = "none";
            $result['children'] = false;
            $result['accessRestriction'] = 'public';
            $result['userAllowedToDL'] = false;
            $result['rdftype'] = "";
            $result['a_attr']['href'] = "none";
            $result['dir'] = false;
            $response = new Response();
            $response->setContent(json_encode((array) $result, \JSON_PARTIAL_OUTPUT_ON_ERROR, 1024));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }

        $helper = new \App\Helper\ArcheCoreHelper();
        $result = $helper->extractChildTreeView((array) $result, $this->repoDb->getBaseUrl(), $lang);

        if (count((array) $result) == 0) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(json_encode([]), 200, ['Content-Type' => 'application/json']);
        }

        $response = new \Symfony\Component\HttpFoundation\JsonResponse();
        $response->setContent(json_encode((array) $result, \JSON_PARTIAL_OUTPUT_ON_ERROR, 1024));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * Fetch the children data
     * @param int $resId
     * @param array $context
     * @param string $orderBy
     * @param string $orderByLang
     * @return array
     */
    function getChildren(int $resId, array $context, string $orderBy, string $orderByLang): array {
        $schema = $this->repoDb->getSchema();
        // add context required to resolve It should cover all of the next item and put collections first 
        $resContext = [
            (string) $schema->nextItem => 'nextItem',
        ];
        $context[\zozlak\RdfConstants::RDF_TYPE] = 'class';
        $context[(string) $schema->nextItem] = 'nextItem';
        $context[(string) $schema->searchMatch] = 'match';
        $searchCfg = new \acdhOeaw\arche\lib\SearchConfig();
        $searchCfg->metadataMode = '0_0_1_0';
        $searchCfg->resourceProperties = array_keys($context);
        $searchCfg->relativesProperties = [
            (string) $schema->label,
            (string) $schema->nextItem,
        ];
        
        $searchTerm = new \acdhOeaw\arche\lib\SearchTerm($schema->parent, $this->repoDb->getBaseUrl() . $resId, type: SearchTerm::TYPE_RELATION);
        $pdoStmt = $this->repoDb->getPdoStatementBySearchTerms([$searchTerm], $searchCfg);
        $resources = [];
        
        while ($triple = $pdoStmt->fetchObject()) {
            //echo "<pre>";
            //var_dump($triple);
            //echo "</pre>";

            //die();
            $triple->value ??= '';
            $id = (string) $triple->id;
            $shortProperty = $context[$triple->property] ?? false;
            if (!$shortProperty) {
                continue;
            }

            $resources[$id] ??= (object) ['id' => $id];
            if ($triple->type === 'REL') {
                $tid = (string) $triple->value;
                $resources[$tid] ??= (object) ['id' => $tid];
                $resources[$id]->{$shortProperty}[] = $resources[$tid];
            } elseif ($shortProperty === 'class') {
                $resources[$id]->class = $triple->value;
            } else {
                $resources[$id]->{$shortProperty}[$triple->lang] = $triple->value;
            }
        }
        // if the resource has the acdh:hasNextItem, return children based on it
        if (count($resources[(string) $resId]->nextItem ?? []) > 0) {
            $children = [];
            $queue = new \SplQueue();
            array_map(fn($x) => isset($x->match) ? $queue->push($x) : null, $resources[(string) $resId]->nextItem);
            while (count($queue) > 0) {
                $next = $queue->shift();
                $children[] = $next;
                array_map(fn($x) => isset($x->match) ? $queue->push($x) : null, $next->nextItem ?? []);
                unset($next->nextItem); // optional, assures printing $children is safe
            }
            //if we have incostistent metadata, then we have to return an error
            if (count($children) === 0) {
                return ['error' => 'incostintent metadata'];
            }
        } else {
            $children = array_filter($resources, fn($x) => isset($x->match));
            $sortFn = function ($a, $b) use ($orderByLang): int {
                if ($a->class !== $b->class) {
                    return $a->class <=> $b->class;
                }
                return ($a->title[$orderByLang] ?? $a->title[min(array_keys($a->title))]) <=> ($b->title[$orderByLang] ?? $b->title[min(array_keys($b->title))]);
            };
            usort($children, $sortFn);
        }
        return $children;
    }
    
    
    /**
     * Create previos/next item
     * @param string $rootId
     * @param string $resourceId
     * @param string $lang
     * @return Response
     */
    public function getNextPrevItem(string $rootId, string $resourceId, string $lang, array $searchProps): \Symfony\Component\HttpFoundation\JsonResponse {

        $rootId = filter_var($rootId, \FILTER_VALIDATE_INT);
        $resourceId = filter_var($resourceId, \FILTER_VALIDATE_INT);

        if (empty($rootId) || empty($resourceId)) {
            return new JsonResponse(array($this->t("Please provide an id")), 404, ['Content-Type' => 'application/json']);
        }

        $result = [];
        $schema = $this->repoDb->getSchema();
        $property = [(string) $schema->parent, 'http://www.w3.org/2004/02/skos/core#prefLabel'];

        $resContext = [
            (string) $schema->label => 'title',
            (string) $schema->id => 'identifier'
        ];

        $searchCfg = new \acdhOeaw\arche\lib\SearchConfig();
        $orderby = "asc";
        $searchCfg->orderBy = [$schema->label];
        $searchCfg->orderByLang = $lang;
        $searchPhrase = '';
        $result = $this->getChildren($rootId, $resContext, $orderby, $lang);

        $helper = new \App\Helper\ArcheCoreHelper();
        $result = $helper->extractPrevNextItem((array) $result, $resourceId, $lang);

        if (count((array) $result) == 0) {
            return new Response(json_encode([]), 200, ['Content-Type' => 'application/json']);
        }

        $response = new JsonResponse();
        $response->setContent(json_encode((array) $result, \JSON_PARTIAL_OUTPUT_ON_ERROR, 1024));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
    
    
    
}
