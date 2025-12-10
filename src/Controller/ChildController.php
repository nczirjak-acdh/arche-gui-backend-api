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

    public function __construct(ArcheContext $arche, RequestStack $rs, private \App\Service\ChildService $childService) {
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
     * Child tree view API
     * @param string $id
     * @param array $searchProps
     * @param string $lang
     * @return Response
     */
    public function getChildTreeData(string $id, string $lang, Request $request): \Symfony\Component\HttpFoundation\JsonResponse {
        $searchProps = [];
        if ($request) {
            $searchProps = $this->setProps($request);
        }
        
       
        $data = $this->childService->getChildTreeData($id, $lang ?: $this->siteLang, $searchProps);
        return $data;
    }
}
