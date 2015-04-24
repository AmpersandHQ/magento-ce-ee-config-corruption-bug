<?php
class Convenient_Core_Model_Config extends Mage_Core_Model_Config
{
    protected $counter = 0;

    /**
     * @param string $id
     * @return string
     *
     * @author Luke Rodgers <lr@amp.co>
     */
    protected function _loadCache($id)
    {
        if ($id === $this->_getCacheLockId() && (++$this->counter % 2 == 0)) {
            return false;
        }
        return $this->parentLoadCache($id);
    }

    /**
     * Wrapper so we can mock it for testing
     *
     * @param $id
     * @return string
     *
     * @author Luke Rodgers <lr@amp.co>
     */
    protected function parentLoadCache($id)
    {
        return parent::_loadCache($id);
    }
}
