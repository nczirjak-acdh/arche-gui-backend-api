<?php

namespace App\Controller;

use App\Service\ArcheContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;

class ArcheBaseController extends AbstractController
{
    protected ArcheContext $arche;
    protected string $siteLang;

    public function __construct(ArcheContext $arche, RequestStack $requestStack)
    {
        $this->arche = $arche;

        $request = $requestStack->getCurrentRequest();
        if ($request && $request->getSession()->has('language')) {
            $this->siteLang = strtolower($request->getSession()->get('language'));
        } else {
            $this->siteLang = 'en';
        }
    }

    protected function getRepoDb()
    {
        return $this->arche->getRepoDb();
    }

    protected function getConfig()
    {
        return $this->arche->getConfig();
    }

    
}
