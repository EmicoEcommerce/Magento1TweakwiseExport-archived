<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Writer_Productiterator
 */
class Emico_TweakwiseExport_Model_Writer_Productiterator implements IteratorAggregate
{
    /**
     * @var Zend_Db_Select[]
     */
    protected $_productIdSelects = [];

    /**
     * {@inheritDoc}
     */
    public function getIterator()
    {
        $iterator = new AppendIterator();
        $appEmulation = Mage::getSingleton('core/app_emulation');

        $helper = Mage::helper('emico_tweakwiseexport');

        //Needed for big groupable products with large sets of children
        $resource = Mage::getResourceSingleton('catalog/product_collection');
        $resource->getConnection()->query('SET group_concat_max_len = 24576');

        /** @var $store Mage_Core_Model_Store */
        foreach (Mage::app()->getStores() as $store) {
            if (!$store->getIsActive() || !$helper->isEnabled($store)) {
                continue;
            }

            //Start environment emulation of the specified store
            $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation($store->getId());

            $iterator->append($this->getSimpleIterator($store));
            $iterator->append($this->getProductTypeIterator($store, 'bundle'));
            $iterator->append($this->getProductTypeIterator($store, 'configurable'));
            $iterator->append($this->getProductTypeIterator($store, 'grouped'));

            Mage::dispatchEvent('emico_tweakwiseexport_prepare_product_collection', [
                'collection' => $iterator,
                'store' => $store,
                'writer' => $this,
            ]);

            //Stop environment emulation and restore original store
            $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
        }

        return $iterator;
    }

    /**
     * @param Mage_Core_Model_Store $store
     * @return Iterator
     * @throws Emico_TweakwiseExport_Model_Exception_ExportException
     * @throws Mage_Core_Exception
     */
    public function getSimpleIterator(Mage_Core_Model_Store $store)
    {
        $collection = $this->createProductCollection($store);
        $select = $collection->getSelect();

        // Add stock
        $select->joinLeft(['s' => $collection->getTable('cataloginventory/stock_item')], 's.product_id = e.entity_id', []);
        $select->columns(['qty' => new Zend_Db_Expr('IF(s.qty IS NOT NULL, s.qty, 2147483647)')]);
        $select->columns(['stock_percentage' => new Zend_Db_Expr('IF(s.is_in_stock = 1, 100, 0)')]);

        /** @var Mage_Catalog_Model_Resource_Product_Flat $entity */
        $entity = $collection->getEntity();

        /** @var $attribute Mage_Eav_Model_Attribute */
        foreach ($this->getHelper()->getAttributes() as $attribute) {
            if ($entity->getAttributeForSelect($attribute->getAttributeCode())) {
                $select->columns([$attribute->getAttributeCode() => 'e.' . $attribute->getAttributeCode()]);
            }
        }
        $select->where('e.type_id NOT IN(\'bundle\', \'configurable\', \'grouped\')');

        /** @var Varien_Db_Statement_Pdo_Mysql $stmt */
        $stmt = $collection->getSelect()->query();

        return new IteratorIterator($stmt);
    }

    /**
     * @param Mage_Core_Model_Store $store
     * @param string $productType
     * @return IteratorIterator
     * @throws Emico_TweakwiseExport_Model_Exception_ExportException
     * @throws Mage_Core_Exception
     */
    public function getProductTypeIterator(Mage_Core_Model_Store $store, $productType)
    {
        $collection = $this->createProductCollection($store);
        $collection->getSelect()->where('e.type_id = ?', $productType);

        $this->joinTypeChildren($collection, $store, $productType);
        if ($this->getHelper()->getIsAddStockPercentage($store)) {
            $this->joinTypeStockPercentage($collection, $store, $productType);
        }

        $this->addLinkedAttributes($store, $collection);
        $this->addParentAttributeValues($collection);

        Mage::dispatchEvent(sprintf('emico_tweakwiseexport_prepare_%s_product_collection', $productType), [
            'collection' => $collection,
            'store' => $store,
            'writer' => $this,
        ]);

        /** @var Varien_Db_Statement_Pdo_Mysql $stmt */
        $stmt = $collection->getSelect()->query();

        return new IteratorIterator($stmt);
    }

    /**
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     * @param Mage_Core_Model_Store $store
     * @param $productType
     * @throws Mage_Core_Exception
     */
    protected function joinTypeChildren(
        Mage_Catalog_Model_Resource_Product_Collection $collection,
        Mage_Core_Model_Store $store,
        $productType
    ) {
        /** @var Mage_Catalog_Model_Resource_Product_Flat $entity */
        $entity = $collection->getEntity();
        $flatTable = $entity->getFlatTableName($store->getId());
        $linkTable = $this->getRelationTable($productType);
        $parentColumn = $this->getRelationParentColumn($productType);
        $childColumn = $this->getRelationChildColumn($productType);

        $enabled = Mage_Catalog_Model_Product_Status::STATUS_ENABLED;

        $childSelect = $collection->getConnection()->select();
        $childSelect->from(['link' => $linkTable]);
        if ($productType === 'grouped') {
            $childSelect->where('link.link_type_id = ?', Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED);
        }
        $childSelect->reset('columns');
        $childSelect->columns(['parent_id' => "link.$parentColumn"]);
        $childSelect->join(
            ['flat' => $flatTable],
            "link.$childColumn = flat.entity_id AND flat.status = $enabled"
        );
        if (!$this->getHelper()->exportOutOfStockChildren($store)) {
            $stockTable = $collection->getTable('cataloginventory/stock_status');
            $websiteId = $store->getWebsiteId();
            $childSelect->join(
                ['stock' => $stockTable],
                "flat.entity_id = stock.product_id AND stock.website_id = $websiteId and stock.stock_status = 1",
                []
            );
        }

        $collection->getSelect()->joinLeft(
            ['l' => $childSelect],
            'e.entity_id = l.parent_id',
            []
        );
    }

    protected function joinTypeStockPercentage(
        Mage_Catalog_Model_Resource_Product_Collection $collection,
        Mage_Core_Model_Store $store,
        $productType
    ) {
        /** @var Mage_Catalog_Model_Resource_Product_Flat $entity */
        $entity = $collection->getEntity();
        $productTable = $entity->getFlatTableName($store->getId());
        $linkTable = $this->getRelationTable($productType);
        $statusTable = $collection->getTable('cataloginventory/stock_status');
        $parentColumn = $this->getRelationParentColumn($productType);
        $childColumn = $this->getRelationChildColumn($productType);

        $websiteId = $store->getWebsiteId();
        $enabled = Mage_Catalog_Model_Product_Status::STATUS_ENABLED;

        $select = $collection->getConnection()->select();
        $select->from(['link' => $linkTable]);
        $select->reset('columns');

        if ($productType === 'grouped') {
            $select->where('link.link_type_id = ?', Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED);
        }

        $select->group("link.$parentColumn");
        $select->join(
            ['flat' => $productTable],
            "link.$childColumn = flat.entity_id AND flat.status = $enabled",
            []
        );

        $select->join(
            ['stock' => $statusTable],
            "flat.entity_id = stock.product_id AND stock.website_id = $websiteId",
            []
        );

        $select->columns(
            [
                'stock_percentage' => new Zend_Db_Expr("ROUND((SUM(stock.stock_status) / COUNT(stock.stock_status)) * 100)"),
                'parent_id' => "link.$parentColumn"
            ]
        );

        $collection->getSelect()->join(
            ['percentage' => $select],
            'e.entity_id = percentage.parent_id',
            ['stock_percentage' => 'percentage.stock_percentage']
        );
    }

    /**
     * @param $productType
     * @return string
     */
    protected function getRelationTable($productType)
    {
        $coreResource = Mage::getResourceSingleton('core/resource');
        switch ($productType) {
            case 'configurable':
                return $coreResource->getTable('catalog/product_super_link');
            case 'bundle':
                return $coreResource->getTable('bundle/selection');
            case 'grouped':
                return $coreResource->getTable('catalog/product_link');
            default:
                throw new InvalidArgumentException('Unsupported product type ' . $productType);
        }
    }

    /**
     * @param $productType
     * @return string
     */
    protected function getRelationParentColumn($productType)
    {
        switch ($productType) {
            case 'configurable':
                return 'parent_id';
            case 'bundle':
                return 'parent_product_id';
            case 'grouped':
                return 'product_id';
            default:
                throw new InvalidArgumentException('Unsupported product type ' . $productType);
        }
    }

    /**
     * @param $productType
     * @return string
     */
    protected function getRelationChildColumn($productType)
    {
        switch ($productType) {
            case 'configurable':
            case 'bundle':
                return 'product_id';
            case 'grouped':
                return 'linked_product_id';
            default:
                throw new InvalidArgumentException('Unsupported product type ' . $productType);
        }
    }

    /**
     * @param Mage_Core_Model_Store $store
     *
     * @return Mage_Catalog_Model_Resource_Product_Collection
     * @throws Emico_TweakwiseExport_Model_Exception_ExportException
     * @throws Mage_Core_Exception
     */
    public function createProductCollection(Mage_Core_Model_Store $store)
    {
        // Set current store id to resource singleton so the collection uses the correct flat table
        Mage::getResourceSingleton('catalog/product_flat')->setStoreId($store->getId());

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection->setStore($store);
        $collection->setStoreId($store);

        if (!$collection->isEnabledFlat()) {
            throw new Emico_TweakwiseExport_Model_Exception_ExportException('Flat tables must be enabled on all export stores. Please enable on ' . $store->getFrontendName());
        }

        $collection->addStoreFilter($store);
        $collection->addFieldToFilter('visibility', ['in' => $this->getHelper()->getVisibilityFilter()]);
        $collection->addPriceData(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);

        if (!Mage::helper('cataloginventory')->isShowOutOfStock()) {
            $this->addStockFilter($collection, 'e');
        }

        $select = $collection->getSelect();
        $select->reset('columns');
        $select->group('tweakwise_id');

        $this->addDefaultColumns($collection, $store);

        $storePrefix = $this->getHelper()->toStoreId($store, '');
        $select->columns(['tweakwise_id' => new Zend_Db_Expr('CONCAT("' . $storePrefix . '", e.entity_id)')]);
        $select->columns(['store_id' => new Zend_Db_Expr($store->getId())]);

        // Add categories
        $prefixColumn = 'IF(c.category_id = 1, c.category_id, CONCAT("' . $storePrefix . '", c.category_id))';
        $prefixExpr = 'GROUP_CONCAT(' . $prefixColumn . ' SEPARATOR "' . Emico_TweakwiseExport_Model_Writer_Writer::ATTRIBUTE_SEPARATOR . '" )';
        $categorySelect = $collection->getConnection()->select()
            ->from(['c' => $collection->getTable('catalog/category_product_index')], [$prefixExpr])
            ->where('e.entity_id = c.product_id AND c.store_id = ' . $store->getId());
        $select->columns(['categories' => $categorySelect]);

        // This happens when bundled products have no active children
        $select->where('price_index.final_price IS NOT NULL OR price_index.min_price IS NOT NULL');

        return $collection;
    }

    /**
     * @return Emico_TweakwiseExport_Helper_Data
     */
    public function getHelper()
    {
        return Mage::helper('emico_tweakwiseexport');
    }

    /**
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     * @param string $tableAlias
     * @throws Mage_Core_Model_Store_Exception
     */
    protected function addStockFilter(Mage_Catalog_Model_Resource_Product_Collection $collection, $tableAlias)
    {
        // This is copied from Mage_CatalogInventory_Model_Resource_Stock_Status::addIsInStockFilterToCollection() because of the table alias
        $websiteId = Mage::app()->getStore($collection->getStoreId())->getWebsiteId();
        $connection = $collection->getConnection();
        $stockTableAlias = 'stock_status_index_' . $tableAlias;

        $joinCondition = [
            $tableAlias . '.entity_id = ' . $stockTableAlias . '.product_id',
            $connection->quoteInto($stockTableAlias . '.website_id = ?', $websiteId),
            $connection->quoteInto($stockTableAlias . '.stock_id = ?', Mage_CatalogInventory_Model_Stock::DEFAULT_STOCK_ID),
            $connection->quoteInto($stockTableAlias . '.stock_status = ?', Mage_CatalogInventory_Model_Stock_Status::STATUS_IN_STOCK)
        ];

        $collection->getSelect()
            ->join(
                [$stockTableAlias => $collection->getTable('cataloginventory/stock_status')],
                implode(' AND ', $joinCondition),
                []
            );
    }

    /**
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     * @param Mage_Core_Model_Store $store
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function addDefaultColumns(
        Mage_Catalog_Model_Resource_Product_Collection $collection,
        Mage_Core_Model_Store $store
    ) {
        $columns = [
            'entity_id' => 'e.entity_id',
            'name' => 'e.name',
            'product_type_id' => 'e.type_id',
            // Required for some bundled products
            'price' => new Zend_Db_Expr('IF(price_index.final_price IS NOT NULL AND price_index.final_price != 0, price_index.final_price, price_index.min_price)'),
            'old_price' => 'price_index.price',
            'min_price' => 'price_index.min_price',
            'max_price' => 'price_index.max_price',
        ];

        if (Mage::getStoreConfig('emico_tweakwise/directdata/enabled')) {
            $collection->joinTable(
                ['uw' => 'core/url_rewrite'],
                'product_id = entity_id',
                ['request_path' => 'request_path'],
                'uw.store_id = ' . $store->getId() . ' AND category_id IS NULL AND is_system = 1',
                'left'
            );
            $columns['request_path'] = 'uw.request_path';
        }

        $select = $collection->getSelect();
        // Add default columns
        $select->columns($columns);
    }

    /**
     * @param Mage_Core_Model_Store $store
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     * @return Emico_TweakwiseExport_Model_Writer_Productiterator
     * @throws Mage_Core_Exception
     */
    public function addLinkedAttributes(Mage_Core_Model_Store $store, Mage_Catalog_Model_Resource_Product_Collection $collection)
    {
        /** @var Mage_Catalog_Model_Resource_Product_Flat $entity */
        $entity = $collection->getEntity();

        /** @var Mage_Catalog_Model_Resource_Product_Flat $entity */
        $select = $collection->getSelect();

        /** @var $attribute Mage_Eav_Model_Attribute */
        foreach ($this->getHelper()->getAttributes() as $attribute) {
            if (!$entity->getAttributeForSelect($attribute->getAttributeCode())) {
                continue;
            }
            $column = $attribute->getAttributeCode();

            if ($this->getHelper()->isSpecialAttribute($column)) {
                continue;
            }

            $separator = Emico_TweakwiseExport_Model_Writer_Writer::ATTRIBUTE_SEPARATOR;
            if ($this->getHelper()->isMergedAttribute($column)) {
                // Note here that the 'l' table alias matches with the alias generated in joinTypeChildren.
                $linkConcatExpression = 'IF(l.' . $column . ' IS NULL, "", GROUP_CONCAT(l.' . $column . ' SEPARATOR "' . $separator . '"))';
                $entityConcatExpression = 'CONCAT(IF(e.' . $column . ' IS NULL, "", e.' . $column . '), "' . $separator . '", ' . $linkConcatExpression . ')';
                $columnExpression = new Zend_Db_Expr($entityConcatExpression);
            } else {
                $columnExpression = $column;
            }

            if ($column === 'name') {
                $alias = 'alt_name';
            } else {
                $alias = $column;
            }

            $select->columns([$alias => $columnExpression]);
        }

        // Add qty again note that the 'l' table alias matches with the alias generated in joinTypeChildren.
        $select->joinLeft(['s' => $collection->getTable('cataloginventory/stock_item')], 's.product_id = l.entity_id', []);
        switch ($this->getStockCombineType($store)) {
            case Emico_TweakwiseExport_Model_System_Config_Source_Stockcombination::OPTION_MAX:
                $select->columns(['qty' => new Zend_Db_Expr('MAX(IF(s.qty IS NOT NULL, s.qty, 0))')]);
                break;
            case Emico_TweakwiseExport_Model_System_Config_Source_Stockcombination::OPTION_MIN:
                $select->columns(['qty' => new Zend_Db_Expr('MIN(IF(s.qty IS NOT NULL, s.qty, 2147483647))')]);
                break;
            case Emico_TweakwiseExport_Model_System_Config_Source_Stockcombination::OPTION_SUM:
                $select->columns(['qty' => new Zend_Db_Expr('SUM(IF(s.qty IS NOT NULL, s.qty, 0))')]);
                break;
        }

        return $this;
    }

    /**
     * @param Mage_Core_Model_Store $store
     * @return int
     */
    protected function getStockCombineType(Mage_Core_Model_Store $store)
    {
        return Mage::helper('emico_tweakwiseexport')->stockCombineType($store);
    }

    /**
     * @param Mage_Catalog_Model_Resource_Product_Collection $collection
     */
    protected function addParentAttributeValues(Mage_Catalog_Model_Resource_Product_Collection $collection)
    {
        $distinctAttributes = $this->getHelper()->getParentAttributes();
        $select = $collection->getSelect();
        foreach ($distinctAttributes as $alias => $attributeCode) {
            // No concat needed we need the specific value
            $select->columns([$alias => $attributeCode]);
        }
    }
}
