<?php 

// src/Service/MetadataService.php
namespace App\Service;
use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Contracts\Translation\TranslatorInterface;

class OntologyService
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

}
