<?php


namespace Axyr\Silverstripe\Installer\Console\Helper;

use Symfony\Component\Console\Style\SymfonyStyle;

class FileWriter
{
    private $directory;
    private $io;

    public function __construct(SymfonyStyle $io, $directory)
    {
        $this->io   = $io;
        $this->directory = $directory;
    }

    /**
     * Write config to _ss_environment.php file in webroot.
     */
    public function writeEnvironmentFile($config)
    {
        $file = $this->directory . '/_ss_environment.php';

        if(!is_dir($this->directory)) {
            $this->io->text('create ' . $this->directory);
            mkdir($this->directory);
        }

        $this->io->text('create ' . $file);
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

        $this->io->text('write config to _ss_environment.php');
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
            $this->io->text('create ' . $mysite);
            mkdir($mysite);
        }

        $this->io->text('create ' . $file);
        touch($file);

        $this->io->text('writing mysite/_config.php');

        $content  = "<?php\n\n";
        $content .= "global \$project;\n";
        $content .= "\$project = 'mysite';\n\n";
        $content .= "global \$database;\n";
        $content .= "\$database = '{$config['database']['database']}';\n\n";
        $content .= "require_once('conf/ConfigureFromEnv.php');\n\n";
        $content .= "// Set the site locale\n";
        $content .= "i18n::set_locale('{$config['locale']['locale']}');\n";

        if(isset($config['timezone'])) {
            $content .= "date_default_timezone_set('{$config['timezone']['timezone']}');\n";
        }

        file_put_contents($file, $content);
    }

    public function writeTestFiles()
    {
        // todo, peer review...
        $stubDir = str_replace('src'.DIRECTORY_SEPARATOR.'Helper','stubs',__DIR__);

        $tests  = $this->directory . '/mysite/tests';
        $file   = $tests . '/SampleTest.php';

        if(!is_dir($tests)) {
            $this->io->text('create ' . $tests);
            mkdir($tests);
        }

        $this->io->text('create SampleTest.php');
        copy($stubDir.'/SampleTest.php', $file);

        $this->io->text('create phpunit.xml');
        copy($stubDir.'/phpunit.xml', $this->directory . '/mysite/phpunit.xml');

        $this->io->text('create phpunit.xml.dist');
        copy($stubDir.'/phpunit.xml.dist', $this->directory . '/phpunit.xml.dist');
    }
}
