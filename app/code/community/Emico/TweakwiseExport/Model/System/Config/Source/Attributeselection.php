<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_System_Config_Source_Attributeselection
 */
class Emico_TweakwiseExport_Model_System_Config_Source_Attributeselection
{
    const OPTION_FILTERABLE = 0;
    const OPTION_SEARCHABLE = 1;
    const OPTION_BOTH = 2;
    const OPTION_ALL = 3;


    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::OPTION_FILTERABLE,
                'label' => Mage::helper('emico_tweakwiseexport')->__('Filterable'),
            ],
            [
                'value' => self::OPTION_SEARCHABLE,
                'label' => Mage::helper('emico_tweakwiseexport')->__('Searchable'),
            ],
            [
                'value' => self::OPTION_BOTH,
                'label' => Mage::helper('emico_tweakwiseexport')->__('Filterable & Searchable'),
            ],
            ['value' => self::OPTION_ALL, 'label' => Mage::helper('emico_tweakwiseexport')->__('All')],
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
            self::OPTION_FILTERABLE => Mage::helper('emico_tweakwiseexport')->__('Filterable'),
            self::OPTION_SEARCHABLE => Mage::helper('emico_tweakwiseexport')->__('Searchable'),
            self::OPTION_BOTH => Mage::helper('emico_tweakwiseexport')->__('Filterable & Searchable'),
            self::OPTION_ALL => Mage::helper('emico_tweakwiseexport')->__('All'),
        ];
    }
}
