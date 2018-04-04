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
     * Register all uniquely exported attribute codes and values, so we can persist them at the end of the export
     *
     * @param Varien_Event_Observer $observer
     */
    public function addProductAttributes(Varien_Event_Observer $observer)
    {
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
