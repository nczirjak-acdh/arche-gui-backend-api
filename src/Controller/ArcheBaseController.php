<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use acdhOeaw\arche\lib\Config;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\schema\Ontology;
//use Drupal\Core\File\FileSystemInterface;

/**
 * Description of ArcheBaseController
 *
 * @author nczirjak
 */
class ArcheBaseController extends AbstractController {

    protected Config $config;
    protected RepoDb $repoDb;
    protected Ontology $ontology;
    protected $siteLang;
    protected $helper;
    protected $model;
    protected \PDO $pdo;
    protected \acdhOeaw\arche\lib\Schema $schema;

    public function __construct() {
        (isset($_SESSION['language'])) ? $this->siteLang = strtolower($_SESSION['language']) : $this->siteLang = "en";
        $this->config = Config::fromYaml('../arche-config/config-gui.yaml');
        $this->checkTmpDirs();
        try {
            $this->pdo = new \PDO($this->config->dbConnStr);
            $baseUrl = $this->config->rest->urlBase . $this->config->rest->pathBase;
            $this->schema = new \acdhOeaw\arche\lib\Schema($this->config->schema);
            $headers = new \acdhOeaw\arche\lib\Schema($this->config->rest->headers);
            $nonRelProp = $this->config->metadataManagment->nonRelationProperties ?? [];
            $this->repoDb = new RepoDb($baseUrl, $this->schema, $headers, $this->pdo, $nonRelProp);
            $this->ontology = \acdhOeaw\arche\lib\schema\Ontology::factoryDb($this->pdo, $this->schema, $this->config->ontologyCacheFile ?? '', $this->config->ontologyCacheTtl ?? 600);
        } catch (\Exception $ex) {
            \Drupal::messenger()->addWarning($this->t('Error during the BaseController initialization!') . ' ' . $ex->getMessage());
            return array();
        }
    }

    private function checkTmpDirs() {

        $tempDirectory = 'public://tmp_files';
        if (!\Drupal::service('file_system')->prepareDirectory($tempDirectory, FileSystemInterface::CREATE_DIRECTORY)) {
            error_log('Could not create the temporary directory: ' . $tempDirectory);
        }
        
        $translationsDirectory = 'public://translations';
        if (!\Drupal::service('file_system')->prepareDirectory($translationsDirectory, FileSystemInterface::CREATE_DIRECTORY)) {
            error_log('Could not create the temporary directory: ' . $translationsDirectory);
        }
        
        $configDir = 'public://config_tlpXNA-ReYSeqYjmFBBCPxdygkZ95C_n73LVRKAXtzVywwEXIa2HSiI8OMNjzjxZcXYpMKd3ug';
        if (!\Drupal::service('file_system')->prepareDirectory($configDir, FileSystemInterface::CREATE_DIRECTORY)) {
            error_log('Could not create the temporary directory: ' . $configDir);
        }
        
        $configSyncDir = 'public://config_tlpXNA-ReYSeqYjmFBBCPxdygkZ95C_n73LVRKAXtzVywwEXIa2HSiI8OMNjzjxZcXYpMKd3ug/sync';
        if (!\Drupal::service('file_system')->prepareDirectory($configSyncDir, FileSystemInterface::CREATE_DIRECTORY)) {
            error_log('Could not create the temporary directory: ' . $configSyncDir);
        }


    }

    /**
     * If the API needs a different response language then we have to change the
     * session lang params to get the desired lang string translation
     * @param string $lang
     * @return void
     */
    protected function changeAPILanguage(string $lang): void {
        if ($this->getCurrentLanguage() !== $lang) {
            $_SESSION['language'] = $lang;
            $_SESSION['_sf2_attributes']['language'] = $lang;
        }
    }

    /**
     * Get the site actual language
     * @return type
     */
    private function getCurrentLanguage() {
        $current_language = \Drupal::languageManager()->getCurrentLanguage();
        // Get the language code, for example 'en' for English.
        $language_code = $current_language->getId();
        return $language_code;
    }
    
    /**
     * we have to redirect the old metadata url to the new one
     * old: oeaw_detail new: metadata
     */
    public function redirectOldDetailView(string $identifier) {
        $destination = '/browser/metadata/' . $identifier; // Redirect to a path using the parameter
        return new \Symfony\Component\HttpFoundation\RedirectResponse($destination, 301);
    }
}
