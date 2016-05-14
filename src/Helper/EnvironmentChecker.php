<?php

namespace Axyr\Silverstripe\Installer\Console\Helper;

class EnvironmentChecker
{
    private $directory = null;
    private $environmentFile = null;

    public function __construct($directory)
    {
        $this->directory = $directory;
        $this->getEnvironmentFile();
    }

    /**
     * @return null|string
     */
    public function getEnvironmentFile()
    {
        if(!$this->environmentFile) {
            $file = realpath(getcwd()) . '/_ss_environment.php';

            if (file_exists($file)) {
                set_include_path(dirname(getcwd()));
                require_once "$file";
                $this->environmentFile = $file;
            }
        }

        return $this->environmentFile;
    }

    /**
     * @return string
     */
    public function getDatabaseName()
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
    public function getHostName()
    {
        global $_FILE_TO_URL_MAPPING;

        if(is_array($_FILE_TO_URL_MAPPING)) {
            if(isset($_FILE_TO_URL_MAPPING[$this->directory])) {
                return $_FILE_TO_URL_MAPPING[$this->directory];
            }elseif(isset($_FILE_TO_URL_MAPPING[realpath(getcwd())])) {
                return $_FILE_TO_URL_MAPPING[realpath(getcwd())];
            }
        }
        return '';
    }

    /**
     * https://bojanz.wordpress.com/2014/03/11/detecting-the-system-timezone-php/
     */
    public function getTimeZone()
    {
        /*
         * Mac OS X (and older Linuxes)
         * /etc/localtime is a symlink to the
         * timezone in /usr/share/zoneinfo.
         */
        if (is_link('/etc/localtime')) {
            $filename = readlink('/etc/localtime');
            if (strpos($filename, '/usr/share/zoneinfo/') === 0) {
                return substr($filename, 20);
            }
        /*
         * Ubuntu / Debian.
         */
        } elseif (file_exists('/etc/timezone')) {
            $data = file_get_contents('/etc/timezone');
            if ($data) {
                return $data;
            }
        /*
         * RHEL / CentOS
         */
        } elseif (file_exists('/etc/sysconfig/clock')) {
            $data = parse_ini_file('/etc/sysconfig/clock');
            if (!empty($data['ZONE'])) {
                return $data['ZONE'];
            }
        }
    }

    /**
     * todo guess from system
     * @return string
     */
    public function getLocale()
    {
        return 'en_US';
    }

}
