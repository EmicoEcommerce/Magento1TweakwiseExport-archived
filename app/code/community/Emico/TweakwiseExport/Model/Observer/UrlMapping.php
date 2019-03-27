<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Observer_UrlMapping
 */
class Emico_TweakwiseExport_Model_Observer_UrlMapping
{
    /**
     * @var array
     */
    protected $attributesExported = [];

    /**
     * @var array
     */
    protected $attributeFilterable = [];

    /**
     * Register all uniquely exported attribute codes and values, so we can persist them at the end of the export
     *
     * @param Varien_Event_Observer $observer
     */
    public function addProductAttributes(Varien_Event_Observer $observer)
    {
        /** @var array $exportAttributes */
        $exportAttributes = $observer->getData('exportAttributes');

        foreach ($exportAttributes as $code => $values) {

            if (!$this->isAttributeFilterable($code)) {
                continue;
            }
            if (!isset($this->attributesExported[$code])) {
                $this->attributesExported[$code] = [];
            }
            foreach ($values as $value) {
                $this->attributesExported[$code][$value] = $value;
            }
        }
    }

    /**
     * @param string $code
     * @return bool
     */
    protected function isAttributeFilterable($code)
    {
        if (!isset($this->attributeFilterable[$code])) {
            $attribute = Mage::getSingleton('catalog/config')->getAttribute(Mage_Catalog_Model_Product::ENTITY, $code);
            $this->attributeFilterable[$code] = $attribute->getData('is_filterable') == 1;
        }
        return (bool) $this->attributeFilterable[$code];
    }

    /**
     * Flush slugs for all exported attributes
     *
     * @param Varien_Event_Observer $observer
     */
    public function writeAttributeSlugs(Varien_Event_Observer $observer)
    {
        $rowsToInsert = [];
        foreach ($this->attributesExported as $code => $values) {
            /** @var array $values */
            foreach ($values as $value) {

                $rowsToInsert[] = [
                    'attribute_value' => $value,
                    'slug' => $this->getSlugifier()->slugify($value)
                ];
            }
        }

        Mage::getModel('emico_tweakwiseexport/slugAttributeMapping')->insertBatch($rowsToInsert);
    }

    /**
     * @return Emico_Tweakwise_Helper_Slugifier
     */
    protected function getSlugifier()
    {
        return Mage::helper('emico_tweakwise/slugifier');
    }
}
