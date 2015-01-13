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

`Mage_Core_Model_Config` also has a protected variable `$_useCache`, when this flag is set Magento will attempt to use load sections of the config from cache storage then persist them within the singleton itself.

##Symptoms of a Corrupted Config Cache##

The following symptoms would usually manifest when the website is experiencing high load, and very often after a cache flush was triggered. The symptoms persist until you flush the `CONFIG` cache.

###Enterprise Edition###
Your website produces nothing but `Front controller reached 100 router match iterations` reports. (Tested on Magento 1.12 and 1.13)

###Community Edition###
Your homepage fails to load the CSS, and a message is displayed saying `There was no 404 CMS page configured or found`. 

I have not spent much time debugging the effects on the community edition, there are likely other symptoms. (Tested on Magento 1.9.1.0)

## Debugging the Issue ##

[Many](http://tutorialmagento.com/fixing-front-controller-reached-100-router-match-iterations) [sources](http://www.magestore.com/magento/magento-front-controller-reached-100-router-match-iterations-error.html) [correctly](http://stackoverflow.com/questions/6262129/magento-front-controller-reached-100-router-match-iterations-error) point out that the problem is caused by some of the routers disappearing from the configuration object, meaning there is no router available to match the request.

This error only occurs when the routers configuration was loaded from cache. To stop the bug from bringing down the website and to aid my debugging I rewrote `Mage_Core_Model_Cache::save()` such that it would do some quick data validation, and prevent corrupted data being saved.

```php
/**
 * Save data
 *
 * @param string $data
 * @param string $id
 * @param array $tags
 * @param int $lifeTime
 * @return bool
 */
public function save($data, $id, $tags = array(), $lifeTime = null)
{
    //Start patch for 100 routers problem
    if (strpos($id,'config_global_stores_') !== false) {
        $xml = new SimpleXMLElement($data);
        $xmlPath = $xml->xpath('web/routers/standard');
        if (count($xmlPath) != 1) {
            //Returning true to prevent it from saving an incomplete cache entry
            return true;
        }
    }
    //End patch

    if ($this->_disallowSave) {
        return true;
    }

    /**
     * Add global magento cache tag to all cached data exclude config cache
     */
    if (!in_array(Mage_Core_Model_Config::CACHE_TAG, $tags)) {
        $tags[] = Mage_Core_Model_App::CACHE_TAG;
    }
    return $this->getFrontend()->save((string)$data, $this->_id($id), $this->_tags($tags), $lifeTime);
}
```

This code change did not 'solve' the issue, but it did stop the website crashing so much. It was also useful as a point of debugging, as I now had a place from which I could monitor and log the issue.

## The Problem ##

By using apache bench to stress my Magento instance along with a lot of `file_put_contents` debugging I was able to discover that the invalid configuration was generated in the `loadDb` method of `Mage_Core_Model_Config`, but only under the following conditions

1. `Mage_Core_Model_Config::init()` has been called on the singleton twice.
2. The first call to must successfully load from cache and set `$_useCache = true`
3. The second call must fail to retrieve the config from cache, and proceed to  incorrectly rebuild the cache because `$_useCache = true` is still set.

### Explanation ###
To understand how we get this flow we'll have to revisit `Mage_Core_Model_Config::init()` and a few other functions.

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

We're interested in `$cacheLoad = $this->loadMoudulesCache()`, the first call successfully retrieved something that resolved to `true` while the second call received something that resolved to `false`. 

Digging deeper into the code.

```php
/**
 * Load cached modules configuration
 *
 * @return bool
 */
public function loadModulesCache()
{
    if (Mage::isInstalled(array('etc_dir' => $this->getOptions()->getEtcDir()))) {
        if ($this->_canUseCacheForInit()) {
            Varien_Profiler::start('mage::app::init::config::load_cache');
            $loaded = $this->loadCache();
            Varien_Profiler::stop('mage::app::init::config::load_cache');
            if ($loaded) {
                $this->_useCache = true;
                return true;
            }
        }
    }
    return false;
}
```
`loadModulesCache` attempts to load the configuration from cache, if it is loaded it sets `$_useCache = true` and returns `true` so that we do not continue to regenerate the cache in the `init` method. The main points for this call failing would be that `loadCache` or `_canUseCacheForInit` returns `false`.

Again, digging deeper into the code.

```php
/**
 * Check if cache can be used for config initialization
 *
 * @return bool
 */
protected function _canUseCacheForInit()
{
    return Mage::app()->useCache('config') && $this->_allowCacheForInit
        && !$this->_loadCache($this->_getCacheLockId());
}
```

`_canUseCacheForInit` ensures the cache is enabled and that it is not locked. For some reason Magento actually uses the cache to lock itself  `$this->_loadCache($this->_getCacheLockId())`.

The problem in our case was that on the second run of `Mage_Core_Model_Config::init()` we were failing the `_canUseCacheForInit` call because the cache was locked. This meant that we would proceed to regenerate and save the `CONFIG` cache while the the singletons `$_useCache` was erroneously still set to `true`.

### Cache Lock Generation ###

As far as I am aware the cache is locked only within the `saveCache` call of the `Mage_Core_Model_Config` singleton. The cache is locked after the configuration has been generated and before the calls to `_saveCache` which will save the config for `config_global`, `config_websites` and `config_stores_{stores}`. The cache lock is removed when all the configuration has been saved in cache.

```php
/**
 * Save configuration cache
 *
 * @param   array $tags cache tags
 * @return  Mage_Core_Model_Config
 */
public function saveCache($tags=array())
{
    if (!Mage::app()->useCache('config')) {
        return $this;
    }
    if (!in_array(self::CACHE_TAG, $tags)) {
        $tags[] = self::CACHE_TAG;
    }
    $cacheLockId = $this->_getCacheLockId();
    if ($this->_loadCache($cacheLockId)) {
        return $this;
    }

    if (!empty($this->_cacheSections)) {
        $xml = clone $this->_xml;
        foreach ($this->_cacheSections as $sectionName => $level) {
            $this->_saveSectionCache($this->getCacheId(), $sectionName, $xml, $level, $tags);
            unset($xml->$sectionName);
        }
        $this->_cachePartsForSave[$this->getCacheId()] = $xml->asNiceXml('', false);
    } else {
        return parent::saveCache($tags);
    }

    $this->_saveCache(time(), $cacheLockId, array(), 60);
    $this->removeCache();
    foreach ($this->_cachePartsForSave as $cacheId => $cacheData) {
        $this->_saveCache($cacheData, $cacheId, $tags, $this->getCacheLifetime());
    }
    unset($this->_cachePartsForSave);
    $this->_removeCache($cacheLockId);
    return $this;
}
```

### Step-By-Step ###

What was happening was likely due to the shared cache storage between multiple servers, but could also have been caused by multiple processes running on one server.

Here's a step-by-step of what was happening in our instance, we had a cronjob which was calling `Mage::app()->init()` multiple times which accounted for the repeated initialisation of `Mage_Core_Model_Config`.

|Time   |Process 1   |Process 2   | Shared Cache Lock   |
|---|---|---|---|
|1   | `init()` - `loadModulesCache` succeeds and sets `$_useCache = true`. |  -  |   |
|2   | Some code is executed | `init()` - `loadModulesCache` fails because someone has hit Flush Cache in the admin panel  |   |
|3   |  Some code is executed | `init()` - `loadModules`, `loadDb` work as expected.  |   |
|4   | Some code calls `Mage::app()->init()`  | `init()` - `saveCache` is initiated  |   |
|5   | `Mage::app()->init()` calls `Mage_Core_Model_Config::init()`  | `saveCache()` - sets the cache lock | LOCKED  |
|6   | `init()` - `loadModulesCache` fails as cache lock is present | `saveCache()` - saves config to cache  | LOCKED  |
|7   | `init()` - calls `loadModules` and `loadDb` with `$_useCache = true`, generated invalid config | `saveCache()` - removes the cache lock, `init` completes. |   |
|7   |  `init()` - calls `saveCache` with incorrectly generated data, causing the errors. | Some code is executed |   |


# Replication #

If you have a look at `100-router-script.php` you can see a simple script which should allow you to reproduce the bug on a Magento instance. Simply download it to the root of your Magento instance and run it.

```
    php replicate.php
```

I was unable to easily reproduce the time sensitive cache hit on `global_config.lock`, however I was able to emulate it by making `loadModulesCache` fail to load `config_global` on the second call.

# The Fix #

2 weeks of work and all this for a 1 line fix.

By forcing `$_useCache = false` when regenerating the config we were able to completely stop this bug from occurring in our instances.

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
    $this->_useCache = false;
    $this->loadModules();
    $this->loadDb();
    $this->saveCache();
    return $this;
}
```

## Performance ##

I do not believe that this fix will affect performance in any negative way as the usual flow for `loadModules`, `loadDb` and `saveCache` is for `$_useCache` to be `false`.
