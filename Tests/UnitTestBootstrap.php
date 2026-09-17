<?php
$composerAutoloader = __DIR__ . '/../../../Packages/Libraries/autoload.php';
if (!file_exists($composerAutoloader)) {
    exit(PHP_EOL . 'Unit test bootstrap: Could not find autoloader at "' . $composerAutoloader . '".');
}
require_once($composerAutoloader);

/*
 * Flow's root autoloader only merges the packages' `autoload` sections, not `autoload-dev`,
 * so shared test doubles under Tests/ (e.g. Tests/Unit/Fixtures) need this mapping here.
 */
spl_autoload_register(static function (string $className): void {
    $prefix = 'NEOSidekick\\AiAssistant\\Tests\\';
    if (strncmp($className, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($className, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
