<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Discovery\PostgresVocabularySource;
use App\Service\Discovery\Vocabulary;
use App\Service\Discovery\VocabularyBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:discovery:build-vocabulary',
    description: 'Build the English/German discovery vocabulary from RDF or ARCHE PostgreSQL.',
)]
final class BuildDiscoveryVocabularyCommand extends Command
{
    public function __construct(
        private readonly VocabularyBuilder $builder,
        private readonly Vocabulary $vocabulary,
        private readonly PostgresVocabularySource $postgres,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Path to the N-Triples export.')
            ->addOption(
                'from-postgres',
                null,
                InputOption::VALUE_NONE,
                'Read the public vocabulary directly from ARCHE PostgreSQL.',
            )
            ->addOption(
                'config',
                null,
                InputOption::VALUE_REQUIRED,
                'Override src/arche-config/config-gui.yaml for PostgreSQL.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getArgument('source');
        $fromPostgres = $input->getOption('from-postgres');
        $configFile = $input->getOption('config');

        if (($source !== null) === $fromPostgres || ($configFile !== null && !$fromPostgres)) {
            $io->error('Pass an N-Triples file or --from-postgres. Use --config only with --from-postgres.');
            return self::INVALID;
        }

        $io->text($fromPostgres
            ? 'Building the vocabulary snapshot from ARCHE PostgreSQL.'
            : 'Building the vocabulary snapshot from RDF.');

        try {
            $stats = $fromPostgres
                ? $this->builder->buildFromPostgres($this->postgres, $this->vocabulary->path, $configFile)
                : $this->builder->build($source, $this->vocabulary->path);
        } catch (\Exception $error) {
            $io->error($error->getMessage());
            return self::FAILURE;
        }

        $io->success(sprintf('Published %d vocabulary labels to %s.', $stats['terms'], $this->vocabulary->path));
        return self::SUCCESS;
    }
}
