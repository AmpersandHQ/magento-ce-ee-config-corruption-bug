<?php
require_once(dirname(__FILE__) . '/app/Mage.php');

if (!Mage::app()->useCache('config')) {
    die("Config cache needs to be enabled.");
}

/**
 * Clearing every bit of cache I can get a hold of, to simulate an empty cache to begin with
 */
Mage::app()->getCacheInstance()->clean();
Mage::app()->getCacheInstance()->flush();
Mage::app()->getCache()->clean();
Mage::reset();

/**
 * Warming up the cache.
 */
Mage::app();
Mage::reset();

/**
 * Initialise mage app,
 * sets useCache = true
 *
 * Removes config_global to simulate a time sensitive cache hit on the config_global.lock cache entry
 */
Mage::app()->getCacheInstance()->remove('config_global');

/**
 * Recall init, which calls Mage_Core_Model_Config:;init,
 * useCache is still true, but we have the hit the fake cache lock on config_global.
 */
Mage::app()->init(Mage_Core_Model_App::ADMIN_STORE_ID, 'store');

/**
 * Reload a non FPC page, you should see a 100 router match iteration page / no 404 page
 */
