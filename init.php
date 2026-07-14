<?php
declare(strict_types=1);

define('ROOT_DIR', __DIR__);
define('SRC_DIR', ROOT_DIR . '/src');
define('CLASSES_DIR', SRC_DIR . '/classes');
define('CONFIG_DIR', ROOT_DIR . '/config');

error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(function (string $class): void {
    $path = CLASSES_DIR . '/' . $class . '.php';

    if (is_file($path)) {
        require $path;
    }
});

require SRC_DIR . '/functions.php';

$configFile = CONFIG_DIR . '/config.php';
if (is_file($configFile)) {
    require $configFile;
}
