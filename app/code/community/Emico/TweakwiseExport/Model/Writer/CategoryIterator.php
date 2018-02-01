<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Writer_CategoryIterator
 */
class Emico_TweakwiseExport_Model_Writer_CategoryIterator implements IteratorAggregate
{
    /**
     * {@inheritDoc}
     */
    public function getIterator()
    {
        $iterator = new AppendIterator();
        $iterator->append(new ArrayIterator([
            [
                'store_id' => 0,
                'tweakwise_id' => '1',
                'name' => 'Root',
                'position' => 0,
            ],
        ]));

        $helper = Mage::helper('emico_tweakwiseexport');
        /** @var Zend_Db_Select[] $selects */
        $selects = [];

        $appEmulation = Mage::getSingleton('core/app_emulation');
        /** @var $store Mage_Core_Model_Store */
        foreach (Mage::app()->getStores() as $store) {
            if (!$store->getIsActive() || !$helper->isEnabled($store)) {
                continue;
            }

            //Start environment emulation of the specified store
            $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation($store->getId());

            $selects[] = $this->getCategoryQuery($store);
            //Stop environment emulation and restore original store
            $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
        }

        /** @var Varien_Db_Statement_Pdo_Mysql $stmt */
        $stmt = Mage::getResourceModel('catalog/category_flat_collection')
            ->getConnection()
            ->select()
            ->union($selects)
            ->order('store_id')
            ->order('level')
            ->query();

        $iterator->append(new IteratorIterator($stmt));

        return new Emico_TweakwiseExport_Model_Writer_CategoryFilterIterator($iterator);
    }

    /**
     * @param Mage_Core_Model_Store $store
     * @return Zend_Db_Select
     */
    public function getCategoryQuery(Mage_Core_Model_Store $store)
    {
        $storePrefix = $this->getHelper()->toStoreId($store, '');

        /** @var Mage_Catalog_Model_Resource_Category_Flat_Collection $collection */
        $collection = Mage::getResourceModel('catalog/category_flat_collection');
        $connection = $collection->getConnection();

        /** @var Varien_Db_Statement_Pdo_Mysql $stmt */

        return $collection->getSelect()
            ->reset(Zend_Db_Select::COLUMNS)
            ->where('is_active = 1')
            ->where('entity_id != 1')
            ->columns([
                // Fields required by category filter
                'level',
                'entity_id',
                'store_id',
                'path',
                // Fields required by TW
                'tweakwise_id' => $connection->getCheckSql('entity_id = 1', 'entity_id', 'CONCAT("' . $storePrefix . '", entity_id)'),
                'parent_id' => $connection->getCheckSql('parent_id = 1 OR parent_id = 0', 'parent_id', 'CONCAT("' . $storePrefix . '", parent_id)'),
                'name',
                'position',
            ])
            ->group('tweakwise_id');
    }

    /**
     * @return Emico_TweakwiseExport_Helper_Data
     */
    public function getHelper()
    {
        return Mage::helper('emico_tweakwiseexport');
    }
}
