<?php
$path = getenv("MAGE_PATH");

if (!file_exists($path)) {
    echo "Mage.php not found!\n";
    echo "Usage:\t";
    echo 'MAGE_PATH="/path/to/magento/app/Mage.php" vendor/bin/phpunit' . "\n";
    die;
}

require_once($path);
/**
 * @todo Why isnt this autoloading
 */
require_once 'lib/Convenient/Core/Model/Config.php';
