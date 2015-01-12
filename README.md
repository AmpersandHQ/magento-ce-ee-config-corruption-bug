# Magento Bug - Corrupted Config Cache 

http://ampersandcommerce.com 
##Preface##
The majority of my experimentation took place on EE 1.13.0.1 and while this bug does also affect CE 1.9.1.0 I will be referring to EE 1.13.0.1 code throughout the explanation.

##`Mage_Core_Model_Config` and Caching##

`Mage_Core_Model_Config` is the class responsible for merging all the system configuration files (config.xml, local.xml) and module configuration files (config.xml) into one object. There are 3 basic parts to the `Mage_Core_Model_Config::init()` call

1. Loading the base config (config.xml and local.xml files).
2. Attempting to load the rest of the config from cache.
3. (On cache load failure) Load the remainder of the config from xml and the database, then save it into cache.

```php
    /**
     * Initialization of core configuration
     *
     * @return Mage_Core_Model_Config
     */
    public function init($options=array())
    {
        $this->setCacheChecksum(null);
        $this->_cacheLoadedSections = array();
        $this->setOptions($options);
        $this->loadBase();

        $cacheLoad = $this->loadModulesCache();
        if ($cacheLoad) {
            return $this;
        }
        $this->loadModules();
        $this->loadDb();
        $this->saveCache();
        return $this;
    }
```

If at any point something were to silently go wrong within `loadModules` or `loadDb`, then corrupted configuration would be saved into cache, meaning that the following request would be served invalid configuration.

##Symptoms##

The following symptoms would usually manifest when the website is experiencing high load, and very often after a cache flush was triggered. The symptoms persist until you flush the `CONFIG` cache.

###Enterprise Edition###
Your website produces nothing but `Front controller reached 100 router match iterations` reports. (Tested on Magento 1.12 and 1.13)

###Community Edition###
Your homepage fails to load the CSS, and a message is displayed saying `There was no 404 CMS page configured or found`. 

I have not spent much time debugging the effects on the community edition, there are likely other symptoms. (Tested on Magento 1.9.1.0)
