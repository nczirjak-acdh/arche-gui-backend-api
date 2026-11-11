<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ArcheConfiguration
{
    private const CONFIG_PATH = '/src/arche-config/config-gui.yaml';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function load(?string $configFile = null): array
    {
        $configFile ??= $this->projectDir . self::CONFIG_PATH;
        try {
            $config = Yaml::parseFile($configFile);
        } catch (ParseException) {
            // A malformed configuration line may contain database credentials.
            throw new RuntimeException('Could not read the ARCHE YAML configuration. Check the file and its syntax.');
        }

        if (!is_array($config)) {
            throw new RuntimeException('The ARCHE YAML configuration must contain a mapping.');
        }

        return $config;
    }

    /** Return the configured REST URL with no trailing slash. */
    public function baseUrl(array $config): string
    {
        $urlBase = $config['rest']['urlBase'] ?? null;
        $pathBase = $config['rest']['pathBase'] ?? '';
        if (!is_string($urlBase) || !is_string($pathBase)) {
            throw new RuntimeException('The ARCHE configuration must specify a valid REST base URL.');
        }

        $url = rtrim(rtrim($urlBase, '/') . '/' . trim($pathBase, '/'), '/');
        $parts = parse_url($url);
        if (!in_array($parts['scheme'] ?? null, ['http', 'https'], true) ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('The ARCHE configuration must specify a valid REST base URL.');
        }

        return $url;
    }
}
