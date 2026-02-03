<?php

namespace App\Controller;

use App\Service\ArcheContext;
use App\Service\InverseDataService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;


class InverseDataController extends \App\Controller\ArcheBaseController {

    protected $helper;
    private $acceptedFormatsHelper;

    public function __construct(ArcheContext $arche, RequestStack $rs, private InverseDataService $inverseDataService) {
        parent::__construct($arche, $rs);
    }

    private function setProps(Request $request): array {
        $draw = $request->request->getInt('draw', 0);
        $offset = $request->request->getInt('start', 0);
        $limit = $request->request->getInt('length', 10);
        $search = $request->request->all('search')['value'] ?? '';

        $order = $request->request->all('order')[0] ?? null;
        $columns = $request->request->all('columns');
        //datatable start columns from 0 but in db we have to start it from 1
        $orderby = $order['column'] ?? 1;
        $order = $order['dir'] ?? 'asc';
        $orderField = $columns[$orderby]['data'] ?? 'id';

        return [
            'offset' => $offset, 'limit' => $limit, 'draw' => $draw, 'search' => $search,
            'orderby' => $orderby, 'order' => $order
        ];
    }

    /**
     * Get Related Collections and Resources
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getRPRDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getRPRDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    /**
     * Get Publications DT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getPublicationsDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getPublicationsDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    
    /**
     * Get CollectionConceptDT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getCollectionConceptDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getCollectionConceptDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    
    /**
     * Get Project Associated DT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getProjectAssociatedDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getProjectAssociatedDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    /**
     * Get Spatial DT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getSpatialDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getSpatialDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    /**
     * Get Contributed DT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getContributedDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getContributedDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    /**
     * Get ispartof DT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getIsPartOfDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getIsPartOfDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    
    /**
     * Get involved DT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getInvolvedDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getInvolvedDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    /**
     * Get has members DT
     * @param int $count
     * @param string $lang
     * @return JsonResponse
     */
    public function getHasMembersDT(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        $data = $this->inverseDataService->getHasMembersDT($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
    
    
    
    
}
