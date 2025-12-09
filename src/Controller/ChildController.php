<?php

namespace App\Controller;

use App\Service\ArcheContext;
use App\Service\ChildService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use Symfony\Component\Routing\Attribute\Route;

class ChildController extends \App\Controller\ArcheBaseController {

    protected $helper;
    

    
    public function __construct(ArcheContext $arche, RequestStack $rs, private \App\Service\ChildService $childService)
    {
        parent::__construct($arche, $rs);       
    }
    

    

    /**
     * Child tree view API
     * @param string $id
     * @param array $searchProps
     * @param string $lang
     * @return Response
     */
    public function getChildTreeData(string $id, string $lang, Request $request): Response {
        
        $data = $this->childService->getChildTreeData($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }

}
