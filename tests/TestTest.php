<?php
class TestTest extends PHPUnit_Framework_TestCase
{
    protected $cacheTypesEnabled;

    /**
     *
     * @author Luke Rodgers <lr@amp.co>
     */
    public function setUp()
    {
        /**
         * Disable all caches except config
         */
        $caches = array();

        foreach (Mage::app()->useCache() as $type => $status) {
            $status = 0;
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
     *
     * @author Luke Rodgers <lr@amp.co>
     */
    public function testReinit()
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
        $before = $before->descend('default/web')->asXML();

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
        Mage::app()->init(Mage_Core_Model_App::ADMIN_STORE_ID, 'STORE');

        /**
         * Get a copy of the stores/default/web configuration from the corrupted cache
         */
        Mage::reset();
        $after = Mage::app()->getCacheInstance()->load('config_global_stores');
        $after = new Varien_Simplexml_Element($after);
        $after = $after->descend('default/web')->asXML();

        $this->assertEquals($before, $after);
    }
}
