<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_System_Config_Source_Stockcombination
 */
class Emico_TweakwiseExport_Model_System_Config_Source_Stockcombination
{
    const OPTION_SUM = 0;
    const OPTION_MAX = 1;
    const OPTION_MIN = 2;

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::OPTION_SUM, 'label' => Mage::helper('emico_tweakwiseexport')->__('Sum')],
            ['value' => self::OPTION_MAX, 'label' => Mage::helper('emico_tweakwiseexport')->__('Maximum')],
            ['value' => self::OPTION_MIN, 'label' => Mage::helper('emico_tweakwiseexport')->__('Minimum')],
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
            self::OPTION_SUM => Mage::helper('emico_tweakwiseexport')->__('Sum'),
            self::OPTION_MAX => Mage::helper('emico_tweakwiseexport')->__('Maximum'),
            self::OPTION_MIN => Mage::helper('emico_tweakwiseexport')->__('Minimum'),
        ];
    }
}
