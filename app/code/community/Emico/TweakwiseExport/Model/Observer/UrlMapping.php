<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Observer_UrlMapping
 */
class Emico_TweakwiseExport_Model_Observer_UrlMapping
{
    const SLUG_MAPPING_TABLE = 'emico_tweakwise_slug_attribute_mapping';

    /**
     * @var array
     */
    protected $attributesExported = [];

    /**
     * Register all uniquely exported attribute codes and values, so we can persist them at the end of the export
     *
     * @param Varien_Event_Observer $observer
     */
    public function addProductAttributes(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('emico_tweakwise/uriStrategy')->hasActiveStrategy('path')) {
            return;
        }

        /** @var array $exportAttributes */
        $exportAttributes = $observer->getData('exportAttributes');

        foreach ($exportAttributes as $code => $values) {
            if (!isset($this->attributesExported[$code])) {
                $this->attributesExported[$code] = [];
            }
            $this->attributesExported[$code] = array_unique(
                array_merge(
                    $this->attributesExported[$code],
                    $values
                )
            );
        }
    }

    /**
     * Flush slugs for all exported attributes
     *
     * @param Varien_Event_Observer $observer
     */
    public function writeAttributeSlugs(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('emico_tweakwise/uriStrategy')->hasActiveStrategy('path')) {
            return;
        }

        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');

        $connection->truncateTable(self::SLUG_MAPPING_TABLE);

        $rowsToInsert = [];
        foreach ($this->attributesExported as $code => $values) {
            /** @var array $values */
            foreach ($values as $value) {

                $rowsToInsert[] = [
                    'attribute_code' => $code,
                    'attribute_value' => $value,
                    'slug' => $this->getSlugifier()->slugify($value)
                ];
            }
        }

        $connection->insertMultiple(self::SLUG_MAPPING_TABLE, $rowsToInsert);

        // Clear the collection cache
        Mage::getModel('emico_tweakwise/slugAttributeMapping')->clearCache();
    }

    /**
     * @return Emico_Tweakwise_Helper_Slugifier
     */
    protected function getSlugifier()
    {
        return Mage::helper('emico_tweakwise/slugifier');
    }
}
