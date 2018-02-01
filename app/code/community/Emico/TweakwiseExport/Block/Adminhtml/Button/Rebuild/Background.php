<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Block_Adminhtml_Button_Rebuild_Background
 */
class Emico_TweakwiseExport_Block_Adminhtml_Button_Rebuild_Background extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * @param Varien_Data_Form_Element_Abstract $element
     *
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $this->setElement($element);

        $class = '';
        if ($state = Mage::getModel('core/variable')->setStoreId(1)->loadByCode('tweakwiseexport_scheduled_for_execution')->getValue()) {
            $class = 'disabled';
        }

        $params = [
            'suffix' => Mage::helper('emico_tweakwiseexport')->feedSuffix(),
        ];

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setType('button')
            ->setClass($class)
            ->setLabel('Run Tweakwise export in background');

        if (!$state) {
            $button->setOnClick('setLocation(\'' . Mage::helper('adminhtml')->getUrl('adminhtml/feed/scheduleBuildFeed', $params) . '\')');
        }

        return $button->toHtml();
    }
}
