<?php

namespace App\Controller;

use App\Service\ArcheContext;
use App\Service\OntologyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

class OntologyController extends \App\Controller\ArcheBaseController {

    protected $helper;
    
    public function __construct(ArcheContext $arche, RequestStack $rs, private OntologyService $ontologyService)
    {
        parent::__construct($arche, $rs);             
    }
    
    public function getOntologyTable(string $lang = "en") {
        $data = $this->ontologyService->getOntologyJs($lang ?: $this->siteLang);
        return $data;
    }
    

    public function getAcceptedFormatsTable(string $lang): Response {
        
        return $this->ontologyService->getAcceptedFormatsTable($lang ?: $this->siteLang);
        
    }
    
    
    public function getRootTable(string $lang = "en"): Response {
        $html = $this->ontologyService->getRootTable($lang ?: $this->siteLang);
        
        if (empty($html)) {
            return new \Symfony\Component\HttpFoundation\Response("There is no resource", 404, ['Content-Type' => 'application/json']);
        }
        $response = new \Symfony\Component\HttpFoundation\Response();
        $response->setContent($html);
        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }
    
    
    
}
