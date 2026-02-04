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
    private $acceptedFormatsHelper;

    
    public function __construct(ArcheContext $arche, RequestStack $rs, private OntologyService $ontologyService)
    {
        parent::__construct($arche, $rs);       
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
