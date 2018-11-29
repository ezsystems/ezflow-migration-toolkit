<?php

namespace EzSystems\EzFlowMigrationToolkitBundle\Command;

use EzSystems\EzFlowMigrationToolkit\HelperObject\Page;
use EzSystems\EzFlowMigrationToolkit\Legacy\Model;
use EzSystems\EzFlowMigrationToolkit\Legacy\Wrapper;
use EzSystems\EzFlowMigrationToolkit\Mapper\BlockMapper;
use EzSystems\EzFlowMigrationToolkit\Report;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use eZ\Publish\Core\Persistence\Database\DatabaseHandler;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;

class EzFlowMigrateCommand extends ContainerAwareCommand
{
    /** @var DatabaseHandler $handler */
    private $handler;

    protected function configure()
    {
        $this->setName('ezflow:migrate');

        $this->addArgument(
            'legacy_path',
            InputArgument::REQUIRED,
            'Path to eZ Publish legacy installation to migrate.'
        );
        $this->addOption(
            'ini',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Custom block configuration to load (ie. --ini=extension/ezdemo/setting/block.ini.append.php)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filesystem = new Filesystem();
        $formatter = $this->getHelper('formatter');

        try {
            Report::prepare($output, $formatter);
            
            $this->loginAsAdmin();

            $filesystem->mkdir('src/MigrationBundle');
            
            Report::write("Migrtion log: src/MigrationBundle/migration.log");

            $warning = new OutputFormatterStyle('yellow', 'red', array('bold', 'blink'));
            $output->getFormatter()->setStyle('warning', $warning);

            if ($filesystem->exists('src/MigrationBundle/MigrationBundle.php')) {
                $output->writeln($formatter->formatBlock([
                    '',
                    '  MigrationBundle already exists. Aborting...  ',
                    '',
                ], 'warning'));

                return false;
            }

            $dbname = $this->getContainer()->getParameter('database_name');
            $dbhost = $this->getContainer()->getParameter('database_host');

            $output->writeln($formatter->formatBlock([
                '',
                '     The migration script will operate on your current database.  ',
                '  Make sure to back up your database in case of an unexpected error.  ',
                '',
            ], 'warning'));

            $helper = $this->getHelper('question');

            $output->writeln('');
            $output->writeln('Current database: ' . $dbname . '@' . $dbhost);
            $output->writeln('');

            $question = new Question('Are you sure? (yes/no) [default: no]  ', 'no');

            $answer = $helper->ask($input, $output, $question);

            $output->writeln('');

            if ($answer !== 'yes') {
                $output->writeln($formatter->formatBlock(['  Aborting...  '], 'warning'));

                return false;
            }

            $legacyPath = $input->getArgument('legacy_path');
            $legacyCustomConfiguration = $input->getOption('ini');

            Wrapper::initialize($legacyPath, $legacyCustomConfiguration ?: []);
            Wrapper::$handler = $this->getContainer()->get('ezpublish.api.storage_engine.legacy.dbhandler');

            $legacyWrapper = new Wrapper();

            $legacyModel = new Model(Wrapper::$handler);

            Report::write('Loading pages from database: ' . $dbname . '@' . $dbhost);
            $legacyPages = $legacyModel->getPages();

            Report::write('Reading legacy block configuration');
            $blockMapper = new BlockMapper($legacyWrapper->getBlockConfiguration(), $this->getContainer()->get('twig'));

            $configuration = [
                'layouts' => [],
                'blocks' => [],
                'services' => [],
            ];

            foreach ($legacyPages as $legacyPage) {
                Report::write("Migrating page...");
                Report::write("ContentId: {$legacyPage['contentobject_id']}, FieldId: {$legacyPage['id']}, Version: {$legacyPage['version']}");

                $legacyPage['name'] = 'Migrated Landing Page';
                $page = new Page($legacyPage, $this->getContainer(), $blockMapper);

                $landingPage = $page->getLandingPage($configuration);

                if ($landingPage) {
                    Report::write("Save page as Landing Page");
                    $legacyModel->updateEzPage($legacyPage['id'], $landingPage);
                }
                else {
                    Report::write("Not valid page field (empty or used as call-to-action). Ignoring...");
                }
            }

            $legacyModel->replacePageFieldType();

            $path = 'src/MigrationBundle/Resources/config';
            $filesystem->mkdir($path);
            $filesystem->mkdir('src/MigrationBundle/DependencyInjection');

            $dumper = new Dumper();

            $servicesYaml = $dumper->dump([
                'services' => $configuration['services']
            ], 4);

            $layoutYaml = $dumper->dump([
                'layouts' => $configuration['layouts'],
                'blocks' => $configuration['blocks'],
            ], 6);

            $path = 'src/MigrationBundle/Resources/views/layouts';
            $filesystem->mkdir($path);

            foreach ($configuration['layouts'] as $layout) {
                Report::write('Prepare placeholder layout (' . $layout['identifier'] . ') in: ' . "{$path}/{$layout['identifier']}.html.twig");

                $filesystem->dumpFile(
                    "{$path}/{$layout['identifier']}.html.twig",
                    $this->getContainer()->get('twig')->render('@EzFlowMigrationToolkit/twig/layout.html.twig', [
                        'zones' => $layout['zones']
                    ])
                );
            }

            $path = 'src/MigrationBundle/Resources/config';
            Report::write("Save service configuration: {$path}/services.yml");
            $filesystem->dumpFile("{$path}/services.yml", $servicesYaml);

            Report::write("Save layout configuration: {$path}/layouts.yml");
            $filesystem->dumpFile("{$path}/layouts.yml", $layoutYaml);

            Report::write("Prepare PHP classes for MigrationBundle");
            $filesystem->dumpFile(
                "src/MigrationBundle/MigrationBundle.php",
                $this->getContainer()->get('twig')->render('@EzFlowMigrationToolkit/php/MigrationBundle.php.twig')
            );
            $filesystem->dumpFile(
                "src/MigrationBundle/DependencyInjection/MigrationExtension.php",
                $this->getContainer()->get('twig')->render('@EzFlowMigrationToolkit/php/Extension.php.twig')
            );

            Report::write("Done!");

            $output->writeln($formatter->formatBlock(["  Success!  "], 'info'));
        }
        catch (\Exception $e) {
            $message = "ERROR: {$e->getMessage()}";

            Report::write($message);
            Report::write($e->getTraceAsString());

            $output->writeln($formatter->formatBlock(["  {$message}  "], 'warning'));
        }
    }

    private function loginAsAdmin()
    {
        $repository = $this->getContainer()->get('ezpublish.api.repository');

        $userService = $repository->getUserService();
        $repository->setCurrentUser($userService->loadUser(14));
    }
}
