<?php
class Convenient_Core_Model_Config extends Mage_Core_Model_Config
{
    protected $counter = 1;

    /**
     * @param string $id
     * @return string
     *
     * @author Luke Rodgers <lr@amp.co>
     */
    protected function _loadCache($id)
    {
        if ($id === $this->_getCacheLockId() && ($this->counter++ == 2)) {
            //Return as a cache hit, fake cache lock on the second call to check the cache lock
            return true;
        }
        return parent::_loadCache($id);
    }
}
