<?php
$composerAutoloader = __DIR__ . '/../../../Packages/Libraries/autoload.php';
if (!file_exists($composerAutoloader)) {
    exit(PHP_EOL . 'Unit test bootstrap: Could not find autoloader at "' . $composerAutoloader . '".');
}
require_once($composerAutoloader);
