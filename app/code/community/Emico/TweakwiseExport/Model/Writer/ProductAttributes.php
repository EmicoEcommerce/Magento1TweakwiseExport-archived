<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Writer_ProductAttributes
 */
class Emico_TweakwiseExport_Model_Writer_ProductAttributes
{
    /**
     * @var Mage_Catalog_Model_Resource_Eav_Attribute[][]
     */
    protected $_attributeGroups;

    /**
     * @var Mage_Catalog_Model_Resource_Eav_Attribute[]
     */
    protected $_attributeIdMap = [];

    /**
     * @var Mage_Catalog_Model_Resource_Eav_Attribute[]
     */
    protected $_attributeCodeMap = [];

    /**
     * @var array
     */
    protected $_data = [];

    /**
     * @var Mage_Core_Model_Store
     */
    protected $_store;

    /**
     * @var string[]
     */
    protected $_excludeChildAttributes;

    /**
     * @var Mage_Catalog_Model_Resource_Eav_Attribute[]
     */
    protected $_extraAttributes = null;

    /**
     * @param Emico_TweakwiseExport_Model_Writer_Xml $writer
     * @param int $storeId
     * @param int $productId
     */
    public function writeExtraAttributes(Emico_TweakwiseExport_Model_Writer_Xml $writer, $storeId, $productId)
    {
        if (count($this->getExtraAttributes()) == 0) {
            return;
        }

        if (!$this->getStore()) {
            $this->setStore($storeId);
            $this->clearMemoryData();
        } elseif ($this->getStore()->getId() != $storeId) {
            $this->setStore($storeId);
            $this->clearMemoryData();
        }

        // Write product data recursive for children included
        $this->writeProductData($writer, $productId, $storeId);
    }

    /**
     * @return Mage_Catalog_Model_Resource_Eav_Attribute[]
     */
    public function getExtraAttributes()
    {
        if (!$this->_extraAttributes) {
            $helper = Mage::helper('emico_tweakwiseexport');
            $attributes = $helper->getAttributes();
            $flatAttributes = Mage::getResourceModel('catalog/product_flat_indexer')->getFlatColumns();

            /** @var Mage_Catalog_Model_Resource_Eav_Attribute $attribute */
            foreach ($attributes as $attribute) {
                if ($attribute->isStatic()) {
                    continue;
                }
                /**
                 * Skip attributes that for whatever reason are present in flat table,
                 * this could be because attribute is marked as filterable.
                 * @see Mage_Catalog_Helper_Product_Flat::XML_NODE_ADD_FILTERABLE_ATTRIBUTES
                 */
                if (isset($flatAttributes[$attribute->getAttributeCode()])) {
                    continue;
                }

                $this->_extraAttributes[] = $attribute;
            }
        }
        return $this->_extraAttributes;
    }

    /**
     * @return Mage_Core_Model_Store
     */
    protected function getStore()
    {
        return $this->_store;
    }

    /**
     * @param Mage_Core_Model_Store|int|string $store
     * @return $this
     */
    protected function setStore($store)
    {
        $this->_store = Mage::app()->getStore($store);
        return $this;
    }

    /**
     * @return $this
     */
    protected function clearMemoryData()
    {
        $this->_data = [];
        return $this;
    }

    /**
     * @param Emico_TweakwiseExport_Model_Writer_Xml $writer
     * @param array|int $products
     * @param $storeId
     */
    protected function writeProductData(Emico_TweakwiseExport_Model_Writer_Xml $writer, $products, $storeId)
    {
        if (!is_array($products)) {
            $products = [$products];
            $excludeAttributes = [];
        } else {
            $excludeAttributes = $this->getExcludeChildAttributes();
        }

        foreach ($products as $productId) {
            $data = $this->getProductData($productId);
            foreach ($data as $attribute => $values) {
                if ($attribute == '_children') {
                    continue;
                } elseif (empty($values)) {
                    continue;
                } elseif (in_array($attribute, $excludeAttributes)) {
                    continue;
                }

                $values = $this->getValues($attribute, $values, $storeId);
                foreach ($values as $value) {
                    $writer->writeAttribute($attribute, $value);
                }
            }

            if (isset($data['_children'])) {
                $this->writeProductData($writer, $data['_children'], $storeId);
            }
        }
    }

    /**
     * @return string[]
     */
    protected function getExcludeChildAttributes()
    {
        if (!$this->_excludeChildAttributes) {
            $this->_excludeChildAttributes = explode(',', Mage::getStoreConfig('emico_tweakwise/export/exclude_child_attributes'));
        }
        return $this->_excludeChildAttributes;
    }

    /**
     * @param $productId
     * @return array
     */
    protected function getProductData($productId)
    {
        if (!isset($this->_data[$productId])) {
            $this->clearMemoryData();
            $this->loadData($productId);
        }

        return $this->_data[$productId];
    }

    /**
     * Load data for provided product id an consecutive id's until + $limit
     *
     * @param int $productId
     * @param int $limit
     * @return array
     */
    protected function loadData($productId, $limit = 200)
    {
        list($childIds, $linkGroups) = $this->getChildProductIdsAndLinkGroups($productId, $limit);
        $selects = [];
        foreach ($this->getAttributeGroups() as $table => $attributes) {
            $selects[] = $this->getAttributeGroupSelect($table, $attributes, $productId, $limit, $childIds);
        }
        if (!count($selects)) {
            return $this->_data[$productId] = [];
        }

        $query = $this->getConnection()->select()
            ->union($selects)
            ->order('entity_id')
            ->query();

        $product = [];
        $currentProduct = null;
        while ($row = $query->fetch()) {
            if ($row['entity_id'] != $currentProduct) {
                if ($currentProduct) {
                    if (isset($linkGroups[$currentProduct])) {
                        $product['_children'] = $linkGroups[$currentProduct];
                    }
                    $this->_data[$currentProduct] = $product;
                }

                $product = [];
                $currentProduct = $row['entity_id'];
            }

            $attributeCode = $this->_attributeIdMap[$row['attribute_id']]->getAttributeCode();
            $product[$attributeCode] = $row['value'];
        }

        if ($currentProduct) {
            if (isset($linkGroups[$currentProduct])) {
                $product['_children'] = $linkGroups[$currentProduct];
            }
            $this->_data[$currentProduct] = $product;
        }

        return $this->_data;
    }

    /**
     * @param $productId
     * @param $limit
     *
     * @return array
     */
    protected function getChildProductIdsAndLinkGroups($productId, $limit)
    {
        $connection = $this->getConnection();
        $childIdSelects = [];

        // Fetch product links
        $childIdSelects[] = $connection->select()
            ->from($this->getTable('catalog/product_super_link'), ['parent_id', 'product_id'])
            ->where('parent_id >= ?', $productId)
            ->where('parent_id < ?', $productId + $limit);

        $childIdSelects[] = $connection->select()
            ->from($this->getTable('bundle/selection'), ['parent_product_id', 'product_id'])
            ->where('parent_product_id >= ?', $productId)
            ->where('parent_product_id < ?', $productId + $limit);

        $childIdSelects[] = $connection->select()
            ->from($this->getTable('catalog/product_link'), ['product_id', 'linked_product_id'])
            ->where('product_id >= ?', $productId)
            ->where('product_id < ?', $productId + $limit)
            ->where('link_type_id = ?', Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED);

        $childIdQuery = $connection->select()
            ->union($childIdSelects)
            ->query();

        $childIds = [];
        $links = [];
        while ($row = $childIdQuery->fetch()) {
            $childIds[] = $row['product_id'];
            if (!isset($links[$row['parent_id']])) {
                $links[$row['parent_id']] = [];
            }
            $links[$row['parent_id']][] = $row['product_id'];
        }

        return [$childIds, $links];
    }

    /**
     * @return Varien_Db_Adapter_Pdo_Mysql
     */
    protected function getConnection()
    {
        return Mage::getResourceSingleton('catalog/product')->getReadConnection();
    }

    /**
     * @param string $alias
     * @return string
     */
    protected function getTable($alias)
    {
        return Mage::getResourceSingleton('catalog/product')->getTable($alias);
    }

    /**
     * @return Mage_Catalog_Model_Resource_Eav_Attribute[][]
     */
    protected function getAttributeGroups()
    {
        if (!$this->_attributeGroups) {
            $groupedAttributes = [];

            foreach ($this->getExtraAttributes() as $attribute) {
                $this->_attributeIdMap[$attribute->getId()] = $attribute;
                $this->_attributeCodeMap[$attribute->getAttributeCode()] = $attribute;

                $table = $attribute->getBackendTable();
                if (!isset($groupedAttributes[$table])) {
                    $groupedAttributes[$table] = [];
                }
                $groupedAttributes[$table][$attribute->getAttributeCode()] = $attribute;
            }
            $this->_attributeGroups = $groupedAttributes;
        }
        return $this->_attributeGroups;
    }

    /**
     * @param string $table
     * @param Mage_Catalog_Model_Resource_Eav_Attribute[] $attributes
     * @param int $productId
     * @param int $limit
     * @return Varien_Db_Select
     */
    protected function getAttributeGroupSelect($table, $attributes, $productId, $limit, array $extraProductIds)
    {
        $joinWhere = [
            'main.entity_id = store.entity_id',
            'main.attribute_id = store.attribute_id',
            'main.store_id != store.store_id',
            'store.store_id = ' . $this->getStore()->getId(),
        ];

        $attributeIds = [];
        foreach ($attributes as $attribute) {
            $attributeIds[] = $attribute->getId();
        }

        $connection = $this->getConnection();

        $entityIdWhere = '(' . $connection->quoteInto('main.entity_id >= ?', $productId) . ' AND ' . $connection->quoteInto('main.entity_id < ?', $productId + $limit) . ')';
        if ($extraProductIds) {
            $entityIdWhere .= ' OR ' . $connection->quoteInto('main.entity_id IN (?)', $extraProductIds);
        }

        return $connection->select()
            ->from(['main' => $table], ['entity_id', 'attribute_id'])
            ->joinLeft(['store' => $table], join(' AND ', $joinWhere), [])
            ->columns(new Zend_Db_Expr('COALESCE(store.`value`, main.`value`) AS `value`'))
            ->where($entityIdWhere)
            ->where('main.attribute_id IN (?)', $attributeIds)
            ->where('main.store_id = 0');
    }

    /**
     * @param string $attributeCode
     * @param mixed $value
     * @param $storeId
     * @return mixed
     * @throws Mage_Core_Exception
     */
    protected function getValues($attributeCode, $value, $storeId)
    {
        $attribute = $this->_attributeCodeMap[$attributeCode];
        $attribute->setStoreId($storeId);
        return Mage::helper('emico_tweakwiseexport')->getAttributeValues($attribute, $value);
    }
}
