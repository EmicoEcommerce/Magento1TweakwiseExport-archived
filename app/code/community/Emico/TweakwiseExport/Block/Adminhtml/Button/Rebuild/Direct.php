<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Block_Adminhtml_Button_Rebuild_Direct
 */
class Emico_TweakwiseExport_Block_Adminhtml_Button_Rebuild_Direct extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * @param Varien_Data_Form_Element_Abstract $element
     *
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $this->setElement($element);

        $params = [
            'suffix' => Mage::helper('emico_tweakwiseexport')->feedSuffix(),
        ];

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setType('button')
            ->setLabel('Run Tweakwise export directly')
            ->setOnClick('setLocation(\'' . Mage::helper('adminhtml')->getUrl('adminhtml/feed/rebuild', $params) . '\')');

        return $button->toHtml();
    }
}
