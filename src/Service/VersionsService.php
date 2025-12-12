<?php

// src/Service/MetadataService.php

namespace App\Service;

use zozlak\RdfConstants as RC;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Contracts\Translation\TranslatorInterface;

class VersionsService {

    protected $helper;
    private $repoDb;

    public function __construct(private ArcheContext $arche, private TranslatorInterface $translator) {
        $this->helper = $this->helper = new \App\Helper\ArcheCoreHelper();
        $this->repoDb = $this->arche->getRepoDb();
    }

    private function sanitizeArcheID(string $id): string {
        return preg_replace('/\D/', '', $id);
    }

    public function getVersionsList(string $id, string $lang = "en") {

        $this->resId = $this->sanitizeArcheID($id);
        $result = [];

        $schema = $this->repoDb->getSchema();

        $context = [
            (string) $schema->label => 'title',
            (string) $schema->id => 'id',
            (string) $schema->isNewVersionOf => 'prevVersion',
            (string) $schema->ontology->version => 'version',
            (string) $schema->creationDate => 'avDate',
            (string) $schema->accessRestriction => 'accessRestriction'
        ];

        $result = $this->getVersions($id, $schema->isNewVersionOf, $context);

        //if we have newer version then we have to fetch the first parent and regenerate the tree again
        if (isset($result->newerVersion)) {
            $this->fetchChildElements($result->newerVersion);
            $first = end($this->reverseArr);
            if (isset($first->repoid)) {
                $result = $this->getVersions($first->repoid, $schema->isNewVersionOf, $context);
            }
        }

        $prevArr = [];
        $marked = false;
        if (isset($result->prevVersion)) {
            foreach ($result->prevVersion as $obj) {
                $this->traverseObject($obj, $prevArr, 'prevVersion');
            }
        }
        $avDate = $this->formatAvDate($result->avDate[0]->value);
     
        if (is_array($result->id)) {
            foreach ($result->id as $item) {
                if (strpos($item->value, '/api/') !== false) {
                    if ((string) $item->value === (string) $this->resId) {
                        $marked = true;
                    }
                }
            }
            // 2. If nothing found then check the first array element
            if ((string) $result->id[0]->value === (string) $this->resId) {
                $marked = true;
            }
        } else {
            if ((string) $result->id === (string) $this->resId) {
                $marked = true;
            }
        }

        
        if (!isset($result->version[0]->value) || $result->version[0]->value === null) {
            return new JsonResponse([], 200, ['Content-Type' => 'application/json']);
        }

        $this->versions[0] = array(
            'id' => $result->repoid,
            'uri' => $result->repoid,
            'uri_dl' => $this->repoDb->getBaseUrl() . $result->repoid,
            'filename' => $avDate . ' - ' . $result->version[0]->value,
            'resShortId' => $result->repoid,
            'title' => $result->title[0]->value . ' - ' . $result->version[0]->value,
            'text' => $result->title[0]->value . ' - ' . $result->version[0]->value,
            'userAllowedToDL' => false,
            'dir' => false,
            'icon' => "jstree-file",
            'marked' => $marked,
            'encodedUri' => $this->repoDb->getBaseUrl() . $result->repoid,
            'repoid' => $result->repoid,
            'version' => $result->version[0]->value,
            'avDate' => $avDate,
            "children" => $prevArr);

        if (count((array) $this->versions) == 0) {
            $message = $this->translator->trans('arche_error.no_resource', [], 'messages', $lang);
            return new JsonResponse(array($message), 404, ['Content-Type' => 'application/json']);
        }

        return new JsonResponse($this->versions, 200, ['Content-Type' => 'application/json']);
    }

    private function formatAvDate(string $dateString): string {
        $date = new \DateTime($dateString);
        return $date->format('Y-m-d');
    }

    private function traverseObject($io, &$outputArray, $versionDirection) {

// Extract title and repoid from the current object
        $title = $io->title[0]->value;
        $version = $io->version[0]->value;
        $marked = false;
        $avDate = "";
        if (!($io->repoid)) {
            return;
        }
        if ($io->avDate[0]->value) {
            $avDate = $this->formatAvDate($io->avDate[0]->value);
        }
        if ((string) $io->repoid === (string) $this->resId) {
            $marked = true;
        }
// Create a new array with title and repoid
        $newItem = array(
            'id' => $io->repoid,
            'uri' => $io->repoid,
            'uri_dl' => $this->repoDb->getBaseUrl() . $io->repoid,
            'filename' => $avDate . ' - ' . $version,
            'resShortId' => $io->repoid,
            'title' => $title,
            'text' => $title . ' - ' . $version,
            'userAllowedToDL' => false,
            'dir' => false,
            'marked' => $marked,
            'encodedUri' => $this->repoDb->getBaseUrl() . $io->repoid,
            'repoid' => $io->repoid,
            'version' => $version,
            'avDate' => $avDate
        );

// If the current object has a 'prevVersion' property and it's not empty
        if (isset($io->{$versionDirection}) && !empty($io->{$versionDirection})) {
            if (count($io->prevVersion) > 0) {
                foreach ($io->{$versionDirection} as $prev) {
                    $this->traverseObject($prev, $newItem['children'], $versionDirection);
                }
            }
        }

// Append the new item to the output array
        $outputArray[] = $newItem;
    }

    private function getVersions(int $resId, string $prevVerProp, array $context): object {

        $res = new \acdhOeaw\arche\lib\RepoResourceDb($resId, $this->repoDb);
        $pdoStmt = $res->getMetadataStatement(
                '99_99_0_0',
                $prevVerProp,
                array_keys($context),
                array_keys($context)
        );
        $tree = [];
        $previd = 0;
        while ($triple = $pdoStmt->fetchObject()) {
            $id = (string) $triple->id;
            $tree[$id] ??= new \stdClass();

            $property = $context[$triple->property];
            if ($property === 'prevVersion') {
//$previd = $triple->value;

                $tree[$triple->value] ??= new \stdClass();
                $tree[$id]->{$property}[] = $tree[$triple->value];
                $tree[$triple->value]->newerVersion[] = $tree[$id];
                $tree[$id]->repoid = $triple->id;
                $tree[$id]->previd = $previd;
            } else {
                $tree[$id]->$property[] = \acdhOeaw\arche\lib\TripleValue::fromDbRow($triple);
                $tree[$id]->repoid = $triple->id;
            }
        }
        return $tree[(string) $resId];
    }

    private function fetchChildElements($array) {
        foreach ($array as $element) {
            if (isset($element->id)) {
                $this->reverseArr[] = $element;
                // Recursively rebuild children first
                if (isset($element->newerVersion)) {
                    $this->fetchChildElements($element->newerVersion);
                }
            }
        }
    }
}
