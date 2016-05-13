<?php
namespace Axyr\Silverstripe\Installer\Console;

use DB;
use Controller;
use DatabaseAdmin;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class NewCommand extends Command
{
    private $config = [
        'database' => [
            'class'    => 'MySQLPDODatabase',
            'server'   => '127.0.0.1',
            'database' => '',
            'username' => 'root',
            'password' => ''
        ],
        'host' => [
            'hostname' => 'http://localhost'
        ],
        'admin' => [
            'username' => 'admin',
            'password' => ''
        ]
    ];

    private $directory = '';

    private $input;

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

        $this->configureDatabase();
        $this->configureHostName();
        $this->configureAdmin();
        if (!$this->confirmConfiguration()) {
            return;
        }

        $this->info('Installing project...');

        $composer = $this->findComposer();

        // Check requirements
        // https://github.com/silverstripe/silverstripe-framework/blob/master/dev/install/install.php5

        $this->runCommands($composer . ' create-project silverstripe/installer ' . $this->directory);

        $this->writeEnvironmentFile();
        $this->writeConfigFile();

        //$this->buildDatabaseSchema();
        $this->runCommands([
            'cd ' . $this->directory,
            'php framework/cli-script.php dev/build'
        ], true); //suppress database build messages.

        $this->comment('Project ready!');
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

        if($this->getEnvironmentFile()) {
            $config = ['database' => $this->getDatabaseNameFromEnv()];
        }

        $this->configureSection('database', $config);
    }

    protected function configureHostName()
    {
        $config = $this->config['host'];

        if($this->getEnvironmentFile()) {
            $config = ['hostname' => $this->getHostNameFromEnv()];
        }

        $this->configureSection('host', $config);
    }

    protected function configureAdmin()
    {
        $config = $this->config['admin'];

        if($this->getEnvironmentFile()) {
            if(defined('SS_DEFAULT_ADMIN_USERNAME')) {
                $config['username'] = SS_DEFAULT_ADMIN_USERNAME;
            }
            if(defined('SS_DEFAULT_ADMIN_PASSWORD')) {
                $config['password'] = SS_DEFAULT_ADMIN_PASSWORD;
            }
        }

        $this->configureSection('admin', $config);
    }

    protected function configureSection($section, $config)
    {
        $helper = $this->getHelper('question');

        foreach ($config as $name => $value) {
            $question    = new Question($this->formatQuestion(ucfirst($section) . ' ' . $name, $value), $value);
            $this->config[$section][$name] = $helper->ask($this->input, $this->output, $question);
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

    /**
     * Write config to _ss_environment.php file in webroot.
     */
    public function writeEnvironmentFile()
    {
        $file = $this->directory . '/_ss_environment.php';

        if(!is_dir($this->directory)) {
            $this->line('create ' . $this->directory);
            mkdir($this->directory);
        }

        $this->line('create ' . $file);
        touch($file);

        $host  = $this->config['host'];
        $db    = $this->config['database'];
        $admin = $this->config['admin'];

        $content  = "<?php\n\n";
        $content .= "global \$_FILE_TO_URL_MAPPING;\n";
        $content .= "\$_FILE_TO_URL_MAPPING[__DIR__] = '".$host['hostname']."';\n\n";
        $content .= "define('SS_ENVIRONMENT_TYPE', 'dev');\n\n";
        $content .= "define('SS_DATABASE_CLASS', '".$db['class']."');\n";
        $content .= "define('SS_DATABASE_SERVER', '".$db['server']."');\n";
        $content .= "define('SS_DATABASE_USERNAME', '".$db['username']."');\n";
        $content .= "define('SS_DATABASE_PASSWORD', '".$db['password']."');\n\n";
        $content .= "define('SS_DEFAULT_ADMIN_USERNAME', '".$admin['username']."');\n";
        $content .= "define('SS_DEFAULT_ADMIN_PASSWORD', '".$admin['password']."');\n\n";

        $this->line('write config to _ss_environment.php');
        file_put_contents($file, $content);
    }

    public function writeConfigFile()
    {
        $mysite = $this->directory . '/mysite';
        $file   = $mysite . '/_config.php';

        if(!is_dir($mysite)) {
            $this->line('create ' . $mysite);
            mkdir($mysite);
        }

        $this->line('create ' . $file);
        touch($file);

        $this->info('writing mysite/_config.php');

        $content  = "<?php\n\n";
        $content .= "global \$project;\n";
        $content .= "\$project = 'mysite';\n\n";
        $content .= "global \$database;\n";
        $content .= "\$database = '{$this->config['database']['database']}';\n\n";
        $content .= "require_once('conf/ConfigureFromEnv.php');\n\n";
        $content .= "// Set the site locale\n";
        $content .= "i18n::set_locale('en_US');\n";
        // todo fix me
        $content .= "date_default_timezone_set('Europe/Amsterdam');\n";

        file_put_contents($file, $content);
    }

    protected function buildDatabaseSchema()
    {
        require_once $this->directory . '/framework/core/Core.php';

        $con = new Controller();
        $con->pushCurrent();

        $this->info("Building database schema...");
        global $databaseConfig;
        //var_dump($databaseConfig);
        DB::connect($databaseConfig);
        $dbAdmin = new DatabaseAdmin();
        $dbAdmin->init();
        $dbAdmin->doBuild(true, true);
    }

    /**
     * @param $question
     * @param string $default
     * @return string
     */
    protected function formatQuestion($question, $default = ' ')
    {
        return "<info>$question </info><comment>[$default]</comment><info>:</info> ";
    }

    /**
     * Find existing values from the _ss_environment.php file if it exists.
     * @return array
     */
    protected function getDatabaseNameFromEnv()
    {
        if(defined('SS_DATABASE_CHOOSE_NAME')) {
            return 'SS_' . pathinfo($this->directory, PATHINFO_FILENAME);
        } elseif(defined('SS_DATABASE_NAME')) {
            return SS_DATABASE_NAME;
        }

        return '';
    }

    /**
     * @return string
     */
    protected function getHostNameFromEnv()
    {
        global $_FILE_TO_URL_MAPPING;

        if(is_array($_FILE_TO_URL_MAPPING)) {
            if(isset($_FILE_TO_URL_MAPPING[$this->directory])) {
                return $_FILE_TO_URL_MAPPING[$this->directory];
            }elseif(isset($_FILE_TO_URL_MAPPING[realpath(getcwd())])) {
                return $_FILE_TO_URL_MAPPING[realpath(getcwd())];
            }
        }
        return $this->config['host']['hostname'];
    }

    /**
     * @return null|string
     */
    protected function getEnvironmentFile()
    {
        $file = realpath(getcwd()) . '/_ss_environment.php';

        if(file_exists($file)) {
            set_include_path(dirname(getcwd()));
            require_once "$file";
            return $file;
        }

        return null;
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

    protected function info($message)
    {
        $this->line($message, 'info');
    }

    protected function comment($message)
    {
        $this->line($message, 'comment');
    }

    protected function error($message)
    {
        $this->line($message, 'error');
    }

    protected function question($message)
    {
        $this->line($message, 'question');
    }

    protected function line($message, $type = null)
    {
        $this->output->writeln($type ? "<$type>$message</$type>" : $message);
    }
}
