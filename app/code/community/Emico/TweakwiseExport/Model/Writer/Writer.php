<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Writer_Writer
 */
class Emico_TweakwiseExport_Model_Writer_Writer
{
    /**
     * Attribute explode separator
     */
    const ATTRIBUTE_SEPARATOR = '-|-|-';

    /**
     * @var array
     */
    protected $_exportedCategoryIds = [];

    /**
     * {@inheritDoc}
     */
    public function write($output)
    {
        $writer = new Emico_TweakwiseExport_Model_Writer_Xml($output);
        $writer->documentStart();
        $this->writeCategories($writer);
        $this->writeProducts($writer);
        $writer->documentFinish();

        return true;
    }

    /**
     * @param Emico_TweakwiseExport_Model_Writer_Xml $writer
     * @return Emico_TweakwiseExport_Model_Writer_Writer
     */
    protected function writeCategories(Emico_TweakwiseExport_Model_Writer_Xml $writer)
    {
        $categoryIterator = Mage::getModel('emico_tweakwiseexport/writer_categoryIterator');

        $writer->startElement('categories');

        /** @var $category Mage_Catalog_Model_Category */
        foreach ($categoryIterator as $category) {
            if ($this->triggerIgnoreEvent($writer, 'category', $category)) {
                continue;
            }

            $this->_exportedCategoryIds[$category['tweakwise_id']] = true;

            $writer->startElement('category');
            $writer->writeElement('categoryid', $category['tweakwise_id']);
            $writer->writeElement('rank', $category['position']);
            $writer->writeElement('name', $category['name']);

            if (isset($category['parent_id']) && $category['parent_id']) {
                $writer->startElement('parents');
                $writer->writeElement('categoryid', $category['parent_id']);
                $writer->endElement(); // </parents>
            }

            $writer->endElement(); // </category>
        }

        $writer->endElement();

        return $this;
    }

    /**
     * @param Emico_TweakwiseExport_Model_Writer_Xml $writer
     * @param string $type
     * @param array|Varien_Object $object
     * @return bool
     */
    protected function triggerIgnoreEvent(Emico_TweakwiseExport_Model_Writer_Xml $writer, $type, &$object)
    {
        $ignore = new Varien_Object(['ignore' => false]);
        $objectConvert = false;
        if (!is_object($object)) {
            $objectConvert = true;
            $object = new Varien_Object($object);
        }
        Mage::dispatchEvent(
            'emico_tweakwiseexport_' . $type . '_before',
            [
                $type => $object,
                'writer' => $writer,
                'ignore' => $ignore,
            ]
        );

        if ($objectConvert) {
            $object = $object->toArray();
        }

        return (bool)$ignore->getData('ignore');
    }

    /**
     * @param Emico_TweakwiseExport_Model_Writer_Xml $writer
     * @return Emico_TweakwiseExport_Model_Writer_Writer
     */
    protected function writeProducts(Emico_TweakwiseExport_Model_Writer_Xml $writer)
    {
        $iterator = Mage::getModel('emico_tweakwiseexport/writer_productiterator');

        foreach ($iterator as $product) {
            if ($this->triggerIgnoreEvent($writer, 'product', $product)) {
                continue;
            }
            $writer->startElement('item');
            $writer->writeElement('id', $product['tweakwise_id']);
            unset($product['tweakwise_id']);
            $writer->writeElement('price', $this->convertPrice($product['store_id'], $product['price']));
            $stock = round($product['qty']);
            unset($product['qty']);
            $writer->writeElement(
                'stock',
                $stock > 2147483647 ? 2147483647 : $stock
            ); // Limit the stock to 32 bit integer max value

            $name = $product['name'];
            if (is_array($name)) {
                $name = array_shift($product['name']);
            } else {
                unset($product['name']);
            }
            $writer->writeElement('name', $name);

            $writer->startElement('attributes');
            $categories = $product['categories'];
            unset($product['categories']);
            $exportAttributes = $this->getHelper()->getAttributes();
            $distinctAttributes = $this->getHelper()->getParentAttributes();
            foreach ($distinctAttributes as $alias => $attribute) {
                $exportAttributes[$alias] = $exportAttributes[$attribute];
            }

            // Write default attributes
            $writer->writeAttribute('product_type_id', $product['product_type_id']);
            $writer->writeAttribute('old_price', $this->convertPrice($product['store_id'], $product['old_price']));

            if (isset($product['request_path'])) {
                $writer->writeAttribute('request_path', $product['request_path']);
            }

            if (array_key_exists('stock_percentage', $product)) {
                $writer->writeAttribute('stock_percentage', $product['stock_percentage']);
                unset($product['stock_percentage']);
            }

            $writtenAttributeValues = [];
            foreach ($product as $attributeCode => $values) {
                if ($values === null) {
                    continue;
                }

                if (!isset($exportAttributes[$attributeCode])) {
                    continue;
                }

                /** @var Mage_Catalog_Model_Resource_Eav_Attribute $attribute */
                $attribute = $exportAttributes[$attributeCode];
                $attribute->setStoreId($product['store_id']);

                foreach ($this->getAttributeValues($attribute, $values) as $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }

                    if ($attributeCode == 'price') {
                        $value = $this->convertPrice($product['store_id'], $product['price']);
                    }

                    if (isset($writtenAttributeValues[$attributeCode . $value])) {
                        continue;
                    }

                    $writtenAttributeValues[$attributeCode . $value] = true;
                    $writer->writeAttribute($attributeCode, $value);
                }
            }

            unset($product['product_type_id']);

            Mage::dispatchEvent(
                'emico_tweakwiseexport_product_export_attributes_after',
                [
                    'product' => $product,
                    'writer' => $writer,
                ]
            );

            $writer->endElement(); // </attributes>

            // Write categories
            $writer->startElement('categories');
            if (is_string($categories)) {
                $categories = explode(self::ATTRIBUTE_SEPARATOR, $categories);
            }
            if (count($categories)) {
                $categories = array_unique($categories);
                foreach ($categories as $categoryId) {
                    if (isset($this->_exportedCategoryIds[$categoryId])) {
                        $writer->writeElement('categoryid', $categoryId);
                    }
                }
            } else {
                $writer->writeElement('categoryid', 1);
            }
            $writer->endElement(); // </categories>

            $writer->endElement(); // </item>

            $writer->flush();
        }

        return $this;
    }

    /**
     * @param null|string|bool|int|Mage_Core_Model_Store $store
     * @param float $price
     * @return float
     */
    protected function convertPrice($store, $price)
    {
        $store = Mage::app()->getStore($store);
        return $store->convertPrice($price, false, false);
    }

    /**
     * @return Emico_TweakwiseExport_Helper_Data
     */
    public function getHelper()
    {
        return Mage::helper('emico_tweakwiseexport');
    }

    /**
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute
     * @param mixed $originalValue
     * @return array
     */
    protected function getAttributeValues(Mage_Catalog_Model_Resource_Eav_Attribute $attribute, $originalValue)
    {
        return $this->getHelper()->getAttributeValues($attribute, $originalValue);
    }
}
