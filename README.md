# silverstripe-cli-installer
Painless command line installer for Silverstripe

## Installation

### Install composer (linux | osx)
```
$ curl -sS https://getcomposer.org/installer | php
$ sudo mv composer.phar /usr/local/bin/composer
```
### Install the installer
```
$ composer global require axyr/silverstripe-cli-installer 0.0.1
```
Make sure that ~/.composer/vendor/bin directory is in your PATH, so you can use the silverstripe command.

## Usage
Move to the directory where you want to add a new Silverstripte project,
and run the following command.
```
$ silverstripe new projectname
```
If you run this in for example /var/www/projects
it will create a project /var/www/projects/projectname

The console will take you thru the required installation steps.
