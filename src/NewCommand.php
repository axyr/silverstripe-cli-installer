<?php
namespace Axyr\Silverstripe\Installer\Console;

use DB;
use Controller;
use DatabaseAdmin;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Axyr\Silverstripe\Installer\Console\Helper\FileWriter;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Axyr\Silverstripe\Installer\Console\Helper\EnvironmentChecker;

class NewCommand extends Command
{

    /**
     * Default config values.
     * If an _ss_enviroment.php file exists, this values will be merged into these defaults.
     * @var array
     */
    private $config = [
        'database' => [
            'class'    => 'MySQLPDODatabase',
            'server'   => '127.0.0.1',
            'database' => '',
            'username' => 'root',
            'password' => ''
        ],
        'admin' => [
            'username' => 'admin',
            'password' => 'admin'
        ],
        'hostname' => [
            'hostname'  => 'http://localhost'
        ],
        'locale' => [
            'locale'  => 'en_US'
        ],
        'timezone' => [
            'timezone'  => ''
        ],
    ];

    /**
     * Installation directory.
     * @var string
     */
    private $directory = '';

    /**
     * @var \Axyr\Silverstripe\Installer\Console\Helper\EnvironmentChecker
     */
    private $checker;

    /**
     * @var \Axyr\Silverstripe\Installer\Console\Helper\FileWriter
     */
    private $writer;

    /**
     * @var \Symfony\Component\Console\Style\SymfonyStyle
     */
    private $io;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Silverstripe project.')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release');
    }

    /**
     * Execute the command.
     *
     * @param  InputInterface $input
     * @param  OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io     = new SymfonyStyle($input, $output);

        $this->verifyApplicationDoesntExist(
            $this->directory = ($input->getArgument('name')) ? getcwd() . '/' . $input->getArgument('name') : getcwd()
        );

        $this->checker = new EnvironmentChecker($this->directory);
        $this->writer  = new FileWriter($this->io, $this->directory);

        $this->configureDatabase()
             ->configureAdmin()
             ->configureHostName()
             ->configureLocale()
             ->configureTimeZone();

        if (!$this->confirmConfiguration()) {
            return;
        }

        $this->io->title('Installing project...');
        $composer = $this->findComposer();
        $this->runCommands($composer . ' create-project silverstripe/installer ' . $this->directory, $output);

        $this->io->newLine();
        $this->io->title('Writing configuration');

        $this->writer->writeEnvironmentFile($this->config);
        $this->writer->writeConfigFile($this->config);

        $this->runCommands([
            'cd ' . $this->directory,
            'php framework/cli-script.php dev/build'
        ],
            $output,
            true); // suppress database build messages

        $this->io->title('Writing configuration');
        $this->removeInstallationFiles();
        $this->writer->writeTestFiles();

        $this->io->success([
            'Project ready!',
            'Test your website by entering : cd '.$input->getArgument('name').' && vendor/bin/phpunit mysite',
            'and visit your website on ' . $this->config['hostname']['hostname']
        ]);
    }

    protected function runCommands($commands, $output, $quiet = false)
    {
        $commands = (array)$commands;
        $process = new Process(implode(' && ', $commands), $this->directory, null, null, null);

        if ($quiet === false && '\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
           $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output, $quiet) {
            if($quiet === false) {
               $output->write($line);
            }
        });
    }

    /**
     * If we have a _ss_environment.php file, we only need the database name
     */
    protected function configureDatabase()
    {
        $config = $this->config['database'];

        if($this->checker->getEnvironmentFile()) {
            $config = ['database' => $this->checker->getDatabaseName()];
        }

        return $this->configureSection('database', $config);
    }

    protected function configureHostName()
    {
        $config = $this->config['hostname'];

        if($this->checker->getEnvironmentFile()) {
            $config = $this->checker->getHostName();
        }

        return $this->configureSection('hostname', $config);
    }

    protected function configureAdmin()
    {
        $config = $this->config['admin'];

        if($this->checker->getEnvironmentFile()) {
            if(defined('SS_DEFAULT_ADMIN_USERNAME')) {
                $config['username'] = SS_DEFAULT_ADMIN_USERNAME;
            }
            if(defined('SS_DEFAULT_ADMIN_PASSWORD')) {
                $config['password'] = SS_DEFAULT_ADMIN_PASSWORD;
            }
        }

        return $this->configureSection('admin', $config);
    }

    protected function configureLocale()
    {
        return $this->configureSection('locale', $this->checker->getLocale());
    }

    protected function configureTimeZone()
    {
        if(!ini_get('date.timezone')) {
            return $this->configureSection('timezone', trim($this->checker->getTimeZone()));
        }
        return $this;
    }

    protected function configureSection($section, $config)
    {
        if(!is_array($config)) {
            $config = [$section => $config];
        }

        foreach ($config as $key => $value) {
            $name = ($section != $key) ? $section . ' ' . $key : $key;
            $this->config[$section][$key] = $this->io->askQuestion(new Question(ucfirst($name), $value));
        }

        return $this;
    }

    protected function confirmConfiguration()
    {
        $rows = [];
        foreach ($this->config as $section => $values) {
            foreach ($values as $name => $value) {
                $rows[] = [$name, $value];
            }
        }
        $this->io->table(['Name', 'Value'], $rows);
        return $this->io->askQuestion(new ConfirmationQuestion('Are these settings correct?'));
    }

    protected function removeInstallationFiles()
    {
        $installfiles = array(
            'install.php',
            'install-frameworkmissing.html'
        );

        foreach($installfiles as $installfile) {
            if(file_exists($this->directory . '/' . $installfile)) {
                @unlink($this->directory . '/' . $installfile);
            }

            (file_exists($this->directory . '/' . $installfile))
                ? $this->io->warning('Could not delete file : ' . $installfile)
                : $this->io->text('Deleted installation file : ' . $installfile);
        }
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd() . '/composer.phar')) {
            return '"' . PHP_BINARY . '" composer.phar';
        }

        return 'composer';
    }
}
