<?php

namespace App\Controller;

use App\Service\ArcheContext;
use App\Service\SmartSearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

class SmartSearchController extends \App\Controller\ArcheBaseController {

    protected $helper;
    
    
    public function __construct(ArcheContext $arche, RequestStack $rs, private SmartSearchService $smartSearchService)
    {
        parent::__construct($arche, $rs);       
    }
    
    /**
     * Smartsearch autocomplete
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function smartSearchAutoComplete(string $str): JsonResponse
    
    {
        $data = $this->smartSearchService->smartSearchAutoComplete($str);
        return $data;
    }
 
}
