<?php
namespace Axyr\Silverstripe\Installer\Console;

use DB;
use Controller;
use DatabaseAdmin;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Input\InputOption;
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
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    private $input;

    /**
     * @var \Symfony\Component\Console\Input\OutputInterface
     */
    private $output;

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
        $this->input  = $input;
        $this->output = $output;

        $this->verifyApplicationDoesntExist(
            $this->directory = ($input->getArgument('name')) ? getcwd() . '/' . $input->getArgument('name') : getcwd()
        );

        $this->checker = new EnvironmentChecker($this->directory);
        $this->writer  = new FileWriter($this, $this->directory, $this->config);

        $this->configureDatabase()
             ->configureAdmin()
             ->configureHostName()
             ->configureLocale()
             ->configureTimeZone();

        if (!$this->confirmConfiguration()) {
            return;
        }

        $this->info('Installing project...');
        $composer = $this->findComposer();
        $this->runCommands($composer . ' create-project silverstripe/installer ' . $this->directory);

        $this->writer->writeEnvironmentFile($this->config);
        $this->writer->writeConfigFile($this->config);

        $this->runCommands([
            'cd ' . $this->directory,
            'php framework/cli-script.php dev/build'
        ], true); // suppress database build messages

        $this->removeInstallationFiles();
        $this->writer->writeTestFiles();

        $this->comment('Project ready!');
        $this->comment('Test your website by entering : cd '.$input->getArgument('name').' && vendor/bin/phpunit mysite');
        $this->comment('and visit your website on ' . $this->config['hostname']['hostname']);
    }

    protected function runCommands($commands, $quiet = false)
    {
        $commands = (array)$commands;
        $process = new Process(implode(' && ', $commands), $this->directory, null, null, null);

        if ($quiet === false && '\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
           $process->setTty(true);
        }

        $output = $this->output;
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

        $this->configureSection('database', $config);
    }

    protected function configureHostName()
    {
        $config = $this->config['hostname'];

        if($this->checker->getEnvironmentFile()) {
            $config = $this->checker->getHostName();
        }

        $this->configureSection('hostname', $config);
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

        $this->configureSection('admin', $config);
    }

    protected function configureLocale()
    {
        $this->configureSection('locale', $this->checker->getLocale());
    }

    protected function configureTimeZone()
    {
        if(!ini_get('date.timezone')) {
            $this->configureSection('timezone', trim($this->checker->getTimeZone()));
        }
    }

    protected function configureSection($section, $config)
    {
        $helper = $this->getHelper('question');

        if(!is_array($config)) {
            $config = [$section => $config];
        }

        foreach ($config as $key => $value) {
            $name = $key;
            if($section != $name) {
                $name = $section . ' ' . $name;
            }
            $question    = new Question($this->formatConfigurationQuestion($name, $value), $value);
            $this->config[$section][$key] = $helper->ask($this->input, $this->output, $question);
        }
    }

    protected function confirmConfiguration()
    {
        $table = new Table($this->output);
        $table->setHeaders(['Name', 'Value']);
        foreach ($this->config as $section => $values) {
            $table->addRow([ '', '']);
            foreach ($values as $name => $value) {
                $table->addRow([$name, $value]);
            }
        }
        $table->render();

        return $this
            ->getHelper('question')
            ->ask($this->input, $this->output, new ConfirmationQuestion('Are these settings correct?'));
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

            if(file_exists($this->directory . '/' . $installfile)) {
                $this->warning('Could not delete file : ' . $installfile);
            }else{
                $this->info('Deleted installation file : ' . $installfile);
            }
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

    /**
     * @param $question
     * @param string $default
     * @return string
     */
    protected function formatConfigurationQuestion($question, $default = ' ')
    {
        $question = ucfirst($question);
        return "<info>$question </info><comment>[$default]</comment><info>:</info> ";
    }

    public function info($message)
    {
        $this->line($message, 'info');
    }

    public function comment($message)
    {
        $this->line($message, 'comment');
    }

    public function error($message)
    {
        $this->line($message, 'error');
    }

    public function question($message)
    {
        $this->line($message, 'question');
    }

    public function line($message, $type = null)
    {
        $this->output->writeln($type ? "<$type>$message</$type>" : $message);
    }
}
