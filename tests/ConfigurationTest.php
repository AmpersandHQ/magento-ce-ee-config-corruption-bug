<?php
class ConfigurationTest extends PHPUnit_Framework_TestCase
{
    protected $cacheTypesEnabled;

    /**
     *
     * @author Luke Rodgers <lr@amp.co>
     */
    public function setUp()
    {
        /**
         * Enable config cache
         */
        $caches = array();

        foreach (Mage::app()->useCache() as $type => $status) {
            if ($type == 'config') {
                $status = 1;
            }
            $caches[$type] = $status;
        }

        Mage::app()->saveUseCache($caches);

        /**
         * Clear all cache entries
         */
        Mage::app()->getCacheInstance()->clean();
        Mage::app()->getCacheInstance()->flush();
        Mage::app()->getCache()->clean();
        Mage::reset();
    }

    /**
     * @test
     * @author Luke Rodgers <lr@amp.co>
     */
    public function reinitWithAlternativeConfigModel()
    {
        /**
         * Initialise Mage and warm the cache
         */
        Mage::app();
        Mage::reset();

        /**
         * Get a copy of the stores/default/web configuration from the warmed up cache
         */
        $before = Mage::app()->getCacheInstance()->load('config_global_stores');
        $before = new Varien_Simplexml_Element($before);
        $before = $before->descend('admin')->asXML();

        /**
         * Initialise Mage with our custom config module which alternates between hitting a fake cache lock
         */
        Mage::reset();
        Mage::app(
            Mage_Core_Model_App::ADMIN_STORE_ID,
            'store',
            array('config_model' => 'Convenient_Core_Model_Config')
        );

        /**
         * Recall init, which calls Mage_Core_Model_Config:;init,
         * useCache is still true, but we have the hit the fake cache lock on config_global.
         */
        Mage::app()->init(Mage_Core_Model_App::ADMIN_STORE_ID, 'store');

        /**
         * Get a copy of the stores/default/web configuration from the corrupted cache
         */
        Mage::reset();
        $after = Mage::app()->getCacheInstance()->load('config_global_stores');
        $after = new Varien_Simplexml_Element($after);
        $after = $after->descend('admin')->asXML();

        $this->assertEquals($before, $after);
    }

    /**
     * @test
     * @author Luke Rodgers <lr@amp.co>
     */
    public function reinitMissingCacheEntry()
    {
        /**
         * Initialise Mage and warm the cache
         */
        Mage::app();
        Mage::reset();

        /**
         * Get a copy of the stores/default/web configuration from the warmed up cache
         */
        $before = Mage::app()->getCacheInstance()->load('config_global_stores');
        $before = new Varien_Simplexml_Element($before);
        $before = $before->descend('admin')->asXML();

        /**
         * Initialise Mage and remove config_global from the cache, to simulate hitting a fake cache lock
         */
        Mage::reset();
        Mage::app()->getCacheInstance()->remove('config_global');


        /**
         * Recall init, which calls Mage_Core_Model_Config:;init,
         * useCache is still true, but we have the hit the fake cache lock on config_global.
         */
        Mage::app()->init(Mage_Core_Model_App::ADMIN_STORE_ID, 'store');

        /**
         * Get a copy of the stores/default/web configuration from the corrupted cache
         */
        Mage::reset();
        $after = Mage::app()->getCacheInstance()->load('config_global_stores');
        $after = new Varien_Simplexml_Element($after);
        $after = $after->descend('admin')->asXML();

        $this->assertEquals($before, $after);
    }
}
