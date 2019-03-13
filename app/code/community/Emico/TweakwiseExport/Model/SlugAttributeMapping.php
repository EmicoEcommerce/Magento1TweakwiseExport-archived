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
     * @param string $value
     * @return string
     * @throws Exception
     */
    public function getSlugForAttributeValue($value)
    {
        $mapping = $this->getMapping();
        if (!isset($mapping[$value])) {
            return $this->persistNewSlug($value);
        }
        return $mapping[$value];
    }

    /**
     * @param string $attributeValue
     * @throws Exception
     * @return string
     */
    public function persistNewSlug($attributeValue)
    {
        $slug = $this->getSlugifier()->slugify($attributeValue);
        $slugAttributeMappingRecord = Mage::getModel('emico_tweakwiseexport/slugAttributeMapping');
        $slugAttributeMappingRecord->setData('attribute_value', $attributeValue);
        $slugAttributeMappingRecord->setData('slug', $slug);
        $slugAttributeMappingRecord->save();
        $this->clearCache();
        return $slug;
    }

    /**
     * @param $requestedSlug
     * @return int|null|string
     * @throws Emico_TweakwiseExport_Model_Exception_SlugMappingException
     */
    public function getAttributeValueBySlug($requestedSlug)
    {
        $mapping = $this->getMapping();
        $key = array_search($requestedSlug, $mapping, true);
        if ($key) {
            return $key;
        }

        throw new Emico_TweakwiseExport_Model_Exception_SlugMappingException(sprintf('No attribute slug found for slug "%s"', $requestedSlug));
    }

    /**
     * @return bool
     */
    public function clearCache()
    {
        return $this->getCacheInstance()->clean('all', ['tweakwise_slugs']);
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
            $this->collection->initCache(
                $this->getCacheInstance(),
                null,
                ['collections', 'tweakwise_slugs']
            );
        }

        return $this->collection;
    }

    /**
     * @return Emico_Tweakwise_Helper_Slugifier
     */
    protected function getSlugifier()
    {
        return Mage::helper('emico_tweakwise/slugifier');
    }
}