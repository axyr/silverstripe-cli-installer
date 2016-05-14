<?php


namespace Axyr\Silverstripe\Installer\Console\Helper;


use Axyr\Silverstripe\Installer\Console\NewCommand;

class FileWriter
{
    private $directory;
    private $command;

    public function __construct(NewCommand $command, $directory)
    {
        $this->command   = $command;
        $this->directory = $directory;
    }

    /**
     * Write config to _ss_environment.php file in webroot.
     */
    public function writeEnvironmentFile($config)
    {
        $file = $this->directory . '/_ss_environment.php';

        if(!is_dir($this->directory)) {
            $this->command->info('create ' . $this->directory);
            mkdir($this->directory);
        }

        $this->command->info('create ' . $file);
        touch($file);

        $host  = $config['hostname'];
        $db    = $config['database'];
        $admin = $config['admin'];

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

        $this->command->info('write config to _ss_environment.php');
        file_put_contents($file, $content);
    }

    /**
     * Write config to _config.php file in webroot/mysite.
     */
    public function writeConfigFile($config)
    {
        $mysite = $this->directory . '/mysite';
        $file   = $mysite . '/_config.php';

        if(!is_dir($mysite)) {
            $this->command->line('create ' . $mysite);
            mkdir($mysite);
        }

        $this->command->info('create ' . $file);
        touch($file);

        $this->command->info('writing mysite/_config.php');

        $content  = "<?php\n\n";
        $content .= "global \$project;\n";
        $content .= "\$project = 'mysite';\n\n";
        $content .= "global \$database;\n";
        $content .= "\$database = '{$config['database']['database']}';\n\n";
        $content .= "require_once('conf/ConfigureFromEnv.php');\n\n";
        $content .= "// Set the site locale\n";
        $content .= "i18n::set_locale('{$config['locale']['locale']}');\n";

        //if(!ini_get('date.timezone') && $config['timezone']['timezone']) {
            $content .= "date_default_timezone_set('{$config['timezone']['timezone']}');\n";
        //}

        file_put_contents($file, $content);
    }

    public function writeTestFiles()
    {
        // todo, peer review...
        $stubDir = str_replace('src'.DIRECTORY_SEPARATOR.'Helper','stubs',__DIR__);

        $tests  = $this->directory . '/mysite/tests';
        $file   = $tests . '/SampleTest.php';

        if(!is_dir($tests)) {
            $this->command->line('create ' . $tests);
            mkdir($tests);
        }

        $this->command->info('create SampleTest.php');
        copy($stubDir.'/SampleTest.php', $file);

        $this->command->info('create phpunit.xml');
        copy($stubDir.'/phpunit.xml', $this->directory . '/mysite/phpunit.xml');

        $this->command->info('create phpunit.xml.dist');
        copy($stubDir.'/phpunit.xml.dist', $this->directory . '/phpunit.xml.dist');
    }
}
