<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_System_Config_Source_Productselectionfilter
 */
class Emico_TweakwiseExport_Model_System_Config_Source_Productselectionfilter
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];
        foreach ($this->toArray() as $index => $value) {
            $options[] = [
                'value' => $index,
                'label' => $value,
            ];
        }

        return $options;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        $options = Mage_Catalog_Model_Product_Visibility::getOptionArray();
        unset($options[Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE]);

        return $options;
    }
}
