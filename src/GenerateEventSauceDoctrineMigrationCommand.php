<?php

declare(strict_types=1);

namespace Andreo\EventSauce\Doctrine\Migration;

use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\DependencyFactory;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'andreo:eventsauce:doctrine-migrations:generate',
)]
final class GenerateEventSauceDoctrineMigrationCommand extends Command
{
    public function __construct(
        private DependencyFactory $dependencyFactory,
        private TableNameSuffix $tableNameSuffix = new TableNameSuffix(),
        private EventSchemaBuilder $eventMessageSchemaBuilder = new DefaultEventSchemaBuilder(),
        private OutboxMessageSchemaBuilder $outboxMessageSchemaBuilder = new DefaultOutboxMessageSchemaBuilder(),
        private SnapshotSchemaBuilder $snapshotSchemaBuilder = new DefaultSnapshotSchemaBuilder()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'prefix',
            mode: InputArgument::OPTIONAL,
            description: 'Prefix table name.',
        );
        $this->addOption(
            'schema',
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Available values: event, outbox, snapshot, all',
            default: ['all']
        );

        $this->addOption(
            'uuid-type',
            mode: InputOption::VALUE_OPTIONAL,
            description: 'binary | string',
            default: Types::BINARY
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configuration = $this->dependencyFactory->getConfiguration();
        $configuration->setCustomTemplate(__DIR__ . '/template.tpl');

        $connection = $this->dependencyFactory->getConnection();
        $migrationGenerator = $this->dependencyFactory->getMigrationGenerator();
        $classNameGenerator = $this->dependencyFactory->getClassNameGenerator();
        $sqlGenerator = $this->dependencyFactory->getMigrationSqlGenerator();

        $dirs = $configuration->getMigrationDirectories();
        if (null === $namespace = key($dirs)) {
            throw new RuntimeException('Please configure migrations directories.');
        }

        /** @var string $prefix */
        $prefix = $input->getArgument('prefix');
        /** @var string[] $schemas */
        $schemas = $input->getOption('schema');
        $uuidType = $input->getOption('uuid-type');
        if (!in_array($uuidType, [Types::BINARY, Types::STRING], true)) {
            $output->writeln('Invalid uuid type. Available values are binary or string.');

            return self::INVALID;
        }

        $fqcn = $classNameGenerator->generateClassName($namespace);

        $upSqlList = [];
        $downSqlList = [];
        foreach ($schemas as $schema) {
            if (!in_array($schema, ['event', 'outbox', 'snapshot', 'all'], true)) {
                $output->writeln('Invalid schema. Available values are event, outbox, snapshot.');

                return self::INVALID;
            }

            if (in_array($schema, ['event', 'all'], true)) {
                $eventTableSuffix = $this->tableNameSuffix->event;
                $eventTableName = Utils::makeTableName($prefix, $eventTableSuffix);
                $eventSchema = $this->eventMessageSchemaBuilder->build($eventTableName, $uuidType);
                $upSqlList[] = $eventSchema->toSql($connection->getDatabasePlatform());
                $downSqlList[] = $this->downSql($eventTableName);
            }
            if (in_array($schema, ['outbox', 'all'], true)) {
                $outboxTableSuffix = $this->tableNameSuffix->outbox;
                $outboxTableName = Utils::makeTableName($prefix, $outboxTableSuffix);
                $outboxSchema = $this->outboxMessageSchemaBuilder->build($outboxTableName);
                $upSqlList[] = $outboxSchema->toSql($connection->getDatabasePlatform());
                $downSqlList[] = $this->downSql($outboxTableName);
            }
            if (in_array($schema, ['snapshot', 'all'], true)) {
                $snapshotTableSuffix = $this->tableNameSuffix->snapshot;
                $snapshotTableName = Utils::makeTableName($prefix, $snapshotTableSuffix);
                $snapshotSchema = $this->snapshotSchemaBuilder->build($snapshotTableName, $uuidType);
                $upSqlList[] = $snapshotSchema->toSql($connection->getDatabasePlatform());
                $downSqlList[] = $this->downSql($snapshotTableName);
            }
        }

        $upSqlList = array_merge(...$upSqlList);

        $upSql = $sqlGenerator->generate($upSqlList, formatted: true, checkDbPlatform: false);
        $downSql = $sqlGenerator->generate($downSqlList, formatted: true, checkDbPlatform: false);

        $path = $migrationGenerator->generateMigration($fqcn, $upSql, $downSql);

        $io->text([
            sprintf('Generated new aggregate migration class to "<info>%s</info>"', $path),
        ]);

        return self::SUCCESS;
    }

    private function downSql(string $tableName): string
    {
        return "DROP TABLE IF EXISTS `$tableName`;";
    }
}
