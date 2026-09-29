<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Kernel;

#[AsController]
class SoftwareVersionsController {

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }
  
    /**
     * Return arche lib names and versions
     * @return JsonResponse
     * @throws \RuntimeException
     */
    public function softwareVersions(): JsonResponse {
        $contents = file_get_contents($this->projectDir . '/composer.json');
        if ($contents === false) {
            throw new \RuntimeException('Unable to read composer.json.');
        }

        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $libraries = [];
        foreach ($composer['require'] ?? [] as $name => $version) {
            if (str_starts_with($name, 'acdh-oeaw/')) {
                $libraries[] = ['name' => $name, 'version' => $version];
            }
        }

        return new JsonResponse([
            'libraries' => $libraries,
            'symfony' => ['name' => 'symfony', 'version' => Kernel::VERSION],
        ]);
    }

}
