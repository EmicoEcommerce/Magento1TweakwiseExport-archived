<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_System_Config_Source_Attributecombination
 */
class Emico_TweakwiseExport_Model_System_Config_Source_Attributecombination
{
    const OPTION_COMBINED = 0;
    const OPTION_PARENT_ONLY = 1;
    const OPTION_CHILDREN_ONLY = 2;

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::OPTION_COMBINED, 'label' => Mage::helper('emico_tweakwiseexport')->__('Combined')],
            [
                'value' => self::OPTION_PARENT_ONLY,
                'label' => Mage::helper('emico_tweakwiseexport')->__('Parent only'),
            ],
            [
                'value' => self::OPTION_CHILDREN_ONLY,
                'label' => Mage::helper('emico_tweakwiseexport')->__('Children only'),
            ],
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [
            self::OPTION_COMBINED => Mage::helper('emico_tweakwiseexport')->__('Combined'),
            self::OPTION_PARENT_ONLY => Mage::helper('emico_tweakwiseexport')->__('Parent only'),
            self::OPTION_CHILDREN_ONLY => Mage::helper('emico_tweakwiseexport')->__('Children only'),
        ];
    }
}
