<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_System_Config_Source_Productattributes
 */
class Emico_TweakwiseExport_Model_System_Config_Source_Productattributes
{
    /**
     * @var Mage_Eav_Model_Entity_Attribute_Abstract[]|Mage_Eav_Model_Mysql4_Entity_Attribute_Collection
     */
    protected $_attributes;

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];
        foreach ($this->getAttributes() as $attribute) {
            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => $this->getAttributeLabel($attribute),
            ];
        }

        return $options;
    }

    /**
     * @return Mage_Eav_Model_Entity_Attribute_Abstract[]|Mage_Eav_Model_Mysql4_Entity_Attribute_Collection
     */
    protected function getAttributes()
    {
        if ($this->_attributes == null) {
            $entityType = Mage::getSingleton('eav/config')->getEntityType(Mage_Catalog_Model_Product::ENTITY);
            $this->_attributes = $entityType->getAttributeCollection()
                ->addFieldToFilter(
                    ['used_in_product_listing', 'is_filterable', 'is_filterable_in_search', 'is_searchable', 'is_visible_in_advanced_search', 'used_for_sort_by'],
                    [true, true, true, true, true, true]
                )
                ->addFieldToFilter('attribute_code', ['nin' => Mage::helper('emico_tweakwiseexport')->getSpecialAttributes()])
                ->addOrder('frontend_label');
        }

        return $this->_attributes;
    }

    /**
     * @param Mage_Eav_Model_Entity_Attribute_Abstract $attribute
     * @return string
     */
    protected function getAttributeLabel(Mage_Eav_Model_Entity_Attribute_Abstract $attribute)
    {
        return trim($attribute->getData('frontend_label') . ' (' . $attribute->getAttributeCode() . ')');
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        $options = [];
        foreach ($this->getAttributes() as $attribute) {
            $options[$attribute->getAttributeCode()] = $this->getAttributeLabel($attribute);
        }

        return $options;
    }
}
