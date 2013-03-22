<?php

namespace ChamiloLMS\Command\Database;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputOption,
    Symfony\Component\Console;
use Symfony\Component\Console\Input\ArrayInput;
use Doctrine\DBAL\Migrations\Tools\Console\Command\AbstractCommand;


/**
 * Class MigrationCommand
 */
class MigrationCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('migrations:migrate_chamilo')
            ->setDescription('Execute a chamilo migration to a specified version or the latest available version.')
            ->addArgument('version', InputArgument::REQUIRED, 'The version to migrate to.', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Execute the migration as a dry run.')
            ->addOption('configuration', null, InputOption::VALUE_OPTIONAL, 'The path to a migrations configuration file.');
    }

    public function getMinVersionSupportedByInstall()
    {
        return key($this->availableVersions());
    }

    public function getVersionNumberList()
    {
        $versionList = $this->availableVersions();
        $versionNumberList = array();
        foreach ($versionList as $version => $info) {
            $versionNumberList[] = $version;
        }
        return $versionNumberList;
    }

    public function availableVersions()
    {
        $versionList = array(
            '1.8.8' => array(
                'require_update' => true,
                'pre' => 'migrate-db-1.8.7-1.8.8-pre.sql',
                'post' => 'null',
                'hook_to_version' => '8' //see ChamiloLMS\Migrations\Version8.php file
            ),
            '1.8.8.2' => false,
            '1.8.8.4' => false,
            '1.8.8.6' => false,
            '1.9.0' => array(
                'require_update' => true,
                'pre' => 'migrate-db-1.8.8-1.9.0-pre.sql',
                'post' => 'null',
                'hook_to_version' => '9'
            ),
            '1.9.2' => false,
            '1.9.4' => false,
            '1.9.6' => false,
            '1.10'  => array(
                'require_update' => true,
                'pre' => 'migrate-db-1.9.0-1.10.0-pre.sql',
                'post' => 'migrate-db-1.9.0-1.10.0-post.sql',
                'hook_to_version' => '10'
            )
        );

        return $versionList;
    }

    public function getAvailableVersionInfo($version)
    {
        $versionList = $this->availableVersions();
        foreach ($versionList as $versionName => $versionInfo) {
            if ($version == $versionName) {
                return $versionInfo;
            }
        }
        return false;
    }

    protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output)
    {
        $version = $input->getArgument('version');
        $dryRun = $input->getOption('dry-run');
        $minVersion = $this->getMinVersionSupportedByInstall();
        $versionList = $this->availableVersions();

        $input->setOption('configuration', $this->getMigrationConfigurationFile());

        $configuration = $this->getMigrationConfiguration($input, $output);

        $doctrineVersion = $configuration->getCurrentVersion();

        //$migration = new Migration($configuration);

        $versionNameList = $this->getVersionNumberList();

        //Checking version
        if (!in_array($version, $versionNameList)) {
            $output->writeln("<comment>Version '$version' is not available</comment>");
            $output->writeln("<comment>Available versions: </comment><info>".implode(', ', $versionNameList)."</info>");
            exit;
        }

        global $_configuration;

        $currentVersion = null;
        //Checking root_sys and correct Chamilo version to install

        if (!isset($_configuration['root_sys'])) {
            $output->writeln("<comment>Can't migrate Chamilo. This is not a Chamilo folder installation.</comment>");
        }

        if (isset($_configuration['system_version']) &&
            !empty($_configuration['system_version']) &&
            $_configuration['system_version'] > $minVersion &&
            $version > $_configuration['system_version']
        ) {
            $currentVersion = $_configuration['system_version'];
        } else {
            $output->writeln("<comment>Please provide a version greater than your current installation > </comment><info>".$_configuration['system_version']."</info>");
            exit;
        }

        $versionInfo = $this->getAvailableVersionInfo($version);


        if (isset($versionInfo['hook_to_version']) && isset($doctrineVersion)) {
            if ($doctrineVersion == $versionInfo['hook_to_version']) {
                $output->writeln("<comment>Nothing to update!</comment>");
                exit;
            }
        }



        //Too much questions?

        $dialog = $this->getHelperSet()->get('dialog');
        if (!$dialog->askConfirmation(
            $output,
            '<question>Are you sure you want to update Chamilo located here?</question> '.$_configuration['root_sys'].' (y/N)',
            false
        )
        ) {
            return;
        }

        $dialog = $this->getHelperSet()->get('dialog');
        if (!$dialog->askConfirmation(
            $output,
            '<question>Are you sure you want to update from version</question> <info>'.$_configuration['system_version'].'</info> <comment>to version </comment><info>'.$version.'</info> (y/N)',
            false
        )
        ) {
            return;
        }

        $output->writeln('<comment>Migrating from Chamilo version: </comment><info>'.$_configuration['system_version'].'</info> <comment>to version <info>'.$version);

        //Starting
        $output->writeln('<comment>Starting migration for Chamilo portal located here: </comment><info>'.$_configuration['root_sys'].'</info>');

        $oldVersion = $currentVersion;
        foreach ($versionList as $versionItem => $versionInfo) {
            if ($versionItem > $currentVersion && $versionItem <= $version) {
                if (isset($versionInfo['require_update']) && $versionInfo['require_update'] == true) {
                    //Greater than my current version
                    $this->startMigration($oldVersion, $versionItem, $dryRun, $output);
                    $oldVersion = $versionItem;
                } else {
                    $output->writeln("<comment>Version <info>'$versionItem'</info> does not need a DB migration</comment>");
                }
            }
        }
    }

    /**
     *
     * @return string
     */
    public function getMigrationConfigurationFile()
    {
        return api_get_path(SYS_PATH).'src/ChamiloLMS/Migrations/migrations.yml';
    }

    /**
     * @param $version
     * @param $output
     */
    public function startMigration($fromVersion, $toVersion, $dryRun, $output)
    {
        $output->writeln("<comment>Starting migration from version: </comment><info>$fromVersion</info><comment> to </comment><info>$toVersion ");
        $installPath = api_get_path(SYS_CODE_PATH).'install/';

        $versionInfo = $this->getAvailableVersionInfo($toVersion);

        if (isset($versionInfo['pre']) && !empty($versionInfo['pre'])) {
            $sqlToInstall = $installPath.$versionInfo['pre'];
            if (file_exists($sqlToInstall)) {
                //$result = $this->processSQL($sqlToInstall, $dryRun, $output);
                $result = true;
                $output->writeln("<comment>Executing file: <info>'$sqlToInstall'</info>");

                if ($result) {
                    $command = $this->getApplication()->find('migrations:migrate');
                    $arguments = array(
                        'command' => 'migrations:migrate',
                        'version' => $versionInfo['hook_to_version'],
                        '--configuration' => $this->getMigrationConfigurationFile()
                    );
                    $input     = new ArrayInput($arguments);
                    $command->run($input, $output);
                    $output->writeln("<comment>Migration ended succesfully</comment>");
                }
            }
        }
    }

    /**
     *
     * @param $sqlFilePath
     * @param $dryRun
     * @param $output
     *
     * @return bool
     * @throws \Exception
     */
    private function processSQL($sqlFilePath, $dryRun, $output)
    {
        try {
            $lines = 0;
            $conn = $this->getHelper('main_database')->getConnection();
            $output->writeln(sprintf("Processing file '<info>%s</info>'... ", $sqlFilePath));

            $sqlList = $this->getSQLContents($sqlFilePath, 'main');

            $conn->beginTransaction();

            foreach ($sqlList as $query) {
                if ($dryRun) {
                    $output->writeln($query);
                } else {
                    //$output->writeln('     <comment>-></comment> ' . $query);
                    $conn->executeQuery($query);
                }
                $lines++;
            }
            $conn->commit();

            if (!$dryRun) {
                $output->writeln(sprintf('%d statements executed!', $lines) . PHP_EOL);

                return true;
            }
        } catch (\Exception $e) {
            $conn->rollback();
            $output->write(sprintf('<error>Migration failed. Error %s</error>', $e->getMessage()));
            throw $e;
        }

        return false;
    }

    /**
     * Function originally wrote in install.lib.php
     * @param $file
     * @param $section
     * @param bool $printErrors
     *
     * @return array|bool
     */
    public function getSQLContents($file, $section, $printErrors = true)
    {
        //check given parameters
        if (empty($file)) {
            $error = "Missing name of file to parse in get_sql_file_contents()";
            if ($printErrors) {
                echo $error;
            }
            return false;
        }
        if (!in_array($section, array('main', 'user', 'stats', 'scorm', 'course'))) {
            $error = "Section '$section' is not authorized in getSQLContents()";
            if ($printErrors) {
                echo $error;
            }

            return false;
        }
        $filepath = $file;
        if (!is_file($filepath) or !is_readable($filepath)) {
            $error = "File $filepath not found or not readable in getSQLContents()";
            if ($printErrors) {
                echo $error;
            }

            return false;
        }
        //read the file in an array
        // Empty lines should not be executed as SQL statements, because errors occur, see Task #2167.
        $file_contents = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($file_contents) or count($file_contents) < 1) {
            $error = "File $filepath looks empty in getSQLContents()";
            if ($printErrors) {
                echo $error;
            }

            return false;
        }

        //prepare the resulting array
        $section_contents = array();
        $record = false;
        foreach ($file_contents as $index => $line) {
            if (substr($line, 0, 2) == '--') {
                //This is a comment. Check if section name, otherwise ignore
                $result = array();
                if (preg_match('/^-- xx([A-Z]*)xx/', $line, $result)) { //we got a section name here
                    if ($result[1] == strtoupper($section)) {
                        //we have the section we are looking for, start recording
                        $record = true;
                    } else {
                        //we have another section's header. If we were recording, stop now and exit loop
                        if ($record) {
                            break;
                        }
                        $record = false;
                    }
                }
            } else {
                if ($record) {
                    if (!empty($line)) {
                        $section_contents[] = $line;
                    }
                }
            }
        }

        //now we have our section's SQL statements group ready, return
        return $section_contents;
    }
}
