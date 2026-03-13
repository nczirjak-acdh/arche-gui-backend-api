<?php

namespace App\Controller;

use App\Service\OpenApiSpecBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

class OpenApiController extends AbstractController
{
    public function __construct(
        private readonly OpenApiSpecBuilder $specBuilder,
    ) {
    }

    #[Route('/api/docs', name: 'arche_api_docs_swagger', methods: ['GET'])]
    public function swaggerUi(): Response
    {
        $openApiUrl = '/api/docs/openapi.json';

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ARCHE API Swagger</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    window.onload = function () {
      window.ui = SwaggerUIBundle({
        url: '{$openApiUrl}',
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [SwaggerUIBundle.presets.apis],
      });
    };
  </script>
</body>
</html>
HTML;

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    #[Route('/api/docs/openapi.json', name: 'arche_api_docs_openapi_json', methods: ['GET'])]
    public function openApiJson(): JsonResponse
    {
        return $this->json($this->specBuilder->build());
    }

    #[Route('/api/docs/openapi.yaml', name: 'arche_api_docs_openapi_yaml', methods: ['GET'])]
    public function openApiYaml(): Response
    {
        $yaml = Yaml::dump($this->specBuilder->build(), 20, 2);
        return new Response($yaml, Response::HTTP_OK, ['Content-Type' => 'application/yaml; charset=UTF-8']);
    }
}
