<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\RdfConstants as RC;
use App\Service\ArcheContext;
use App\Service\VersionsService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

class VersionsController extends \App\Controller\ArcheBaseController {

    private $prev = [];
    private $newer = [];
    private $versions = [];
    private $resId;
    private $reverseArr = [];
    protected $helper;

    public function __construct(ArcheContext $arche, RequestStack $rs, private VersionsService $versionsService)
    {
        parent::__construct($arche, $rs);       
    }


    /**
     * Get the resource versions List
     * @param string $id
     * @param string $lang
     * @return JsonResponse
     */
    public function getVersionsList(string $id, string $lang = "en") {
        
        $data = $this->versionsService->getVersionsList($id, $lang ?: $this->siteLang);
        return $data;
        
    }
}
