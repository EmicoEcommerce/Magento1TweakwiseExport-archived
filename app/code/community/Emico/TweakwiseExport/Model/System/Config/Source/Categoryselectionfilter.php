<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_System_Config_Source_Categoryselectionfilter
 */
class Emico_TweakwiseExport_Model_System_Config_Source_Categoryselectionfilter
{
    const OPTION_ALL = 0;
    const OPTION_VISIBLE = 1;


    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::OPTION_ALL, 'label' => Mage::helper('emico_tweakwiseexport')->__('All')],
            [
                'value' => self::OPTION_VISIBLE,
                'label' => Mage::helper('emico_tweakwiseexport')->__('Include in navigation'),
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
            self::OPTION_ALL => Mage::helper('emico_tweakwiseexport')->__('All'),
            self::OPTION_VISIBLE => Mage::helper('emico_tweakwiseexport')->__('Include in navigation'),
        ];
    }
}
