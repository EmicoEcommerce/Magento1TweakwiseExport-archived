<?php
/**
 * @author Bram Gerritsen <bgerritsen@emico.nl>
 * @copyright (c) Emico B.V. 2017
 */ 
class Emico_TweakwiseExport_Model_SlugAttributeMapping extends Mage_Core_Model_Abstract
{
    /**
     * @var array
     */
    protected $mapping;

    /**
     * @var Emico_TweakwiseExport_Model_Resource_SlugAttributeMapping_Collection
     */
    protected $collection;

    /**
     * Default constructor
     */
    protected function _construct()
    {
        $this->_init('emico_tweakwiseexport/slugAttributeMapping');
    }

    /**
     * @param array $records
     */
    public function insertBatch(array $records)
    {
        /** @var Emico_TweakwiseExport_Model_Resource_SlugAttributeMapping $resource */
        $resource = $this->getResource();

        $affectedRows = $resource->truncateAndinsertBatch($records);

        // Clear the collection cache
        if ($affectedRows > 0) {
            $this->clearCache();
        }
    }

    /**
     * @param $code
     * @param $value
     * @return string
     */
    public function getSlugForAttribute($code, $value)
    {
        $mapping = $this->getMapping();
        if (!isset($mapping[$value])) {
            return $value;
        }
        return $mapping[$value];
    }

    /**
     * @param string $code
     * @param string $requestedSlug
     * @return int|null|string
     * @throws Emico_TweakwiseExport_Model_Exception
     */
    public function getAttributeValueBySlug($code, $requestedSlug)
    {
        $mapping = $this->getMapping();
        $key = array_search($requestedSlug, $mapping, true);
        if ($key) {
            return $mapping[$key];
        }

        throw new Emico_TweakwiseExport_Model_Exception(sprintf('No slug found for attributeCode "%s" and slug "%s"', $code, $requestedSlug));
    }

    /**
     * @return bool
     */
    public function clearCache()
    {
        return $this->getCacheInstance()->remove($this->getCollection()->getCacheKey());
    }

    /**
     * @return Zend_Cache_Core
     */
    protected function getCacheInstance()
    {
        return Mage::app()->getCache();
    }

    /**
     * @return array
     */
    protected function getMapping()
    {
        if ($this->mapping === null) {
            $this->getCollection()->initCache(
                $this->getCacheInstance(),
                null,
                ['collections', 'tweakwise_slugs']
            );

            foreach ($this->getCollection() as $item) {
                $this->mapping[$item->getAttributeValue()] = $item->getSlug();
            }
        }
        return $this->mapping;
    }

    /**
     * @return Emico_TweakwiseExport_Model_Resource_SlugAttributeMapping_Collection|object
     */
    public function getCollection()
    {
        if ($this->collection === null) {
            $this->collection = parent::getCollection();
        }

        return $this->collection;
    }

}