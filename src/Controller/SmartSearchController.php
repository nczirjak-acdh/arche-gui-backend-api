<?php

namespace App\Controller;

use App\Service\ArcheContext;
use App\Service\SmartSearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;

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
    
    
    public function search(Request $request): Response
    
    {
        $postParams = [];
        if ($request->getContent() !== '') {
            $postParams = $request->toArray();
        }
        if (empty($postParams)) {
            $postParams = $request->request->all();
        }
        if (empty($postParams)) {
            $postParams = $request->query->all();
        }

        return $this->smartSearchService->search($postParams);
    }
    
    
 
}
