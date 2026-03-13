<?php

namespace App\Command;

use App\Service\OpenApiSpecBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:openapi:dump',
    description: 'Dump an OpenAPI spec generated from current Symfony routes.',
)]
class DumpOpenApiCommand extends Command
{
    public function __construct(
        private readonly OpenApiSpecBuilder $specBuilder,
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (json or yaml).', 'yaml')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path. Defaults to public/openapi.<format>.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['json', 'yaml'], true)) {
            $output->writeln('<error>Invalid format. Allowed values: json, yaml.</error>');
            return Command::INVALID;
        }

        $spec = $this->specBuilder->build();
        $content = $format === 'json'
            ? json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : Yaml::dump($spec, 20, 2);

        if (!is_string($content)) {
            $output->writeln('<error>Failed to encode OpenAPI output.</error>');
            return Command::FAILURE;
        }

        $outputPath = (string) $input->getOption('output');
        if ($outputPath === '') {
            $outputPath = sprintf('public/openapi.%s', $format);
        }

        $this->filesystem->dumpFile($outputPath, $content . PHP_EOL);
        $output->writeln(sprintf('<info>OpenAPI %s written to %s</info>', strtoupper($format), $outputPath));

        return Command::SUCCESS;
    }
}
