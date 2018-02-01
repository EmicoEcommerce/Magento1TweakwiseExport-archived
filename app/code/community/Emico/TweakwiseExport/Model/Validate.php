<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Validate
 */
class Emico_TweakwiseExport_Model_Validate
{
    /**
     * @var int[][]
     */
    protected $_expectedProductCategoryCount;

    /**
     * @var int[][]
     */
    protected $_expectedCategories = [];

    /**
     * @var int[][]
     */
    protected $_nonAnchorCategories = [];

    /**
     * @param $file
     * @throws Emico_TweakwiseExport_Model_Exception_InvalidFeedException
     */
    public function validate($file)
    {
        $xml = simplexml_load_file($file);

        $this->validateCategoryProductCounts($xml);
        $this->validateCategoryLinks($xml);
    }

    /**
     * Validate category, product en category product link count
     *
     * @param SimpleXMLElement $xml
     * @throws Emico_TweakwiseExport_Model_Exception_InvalidFeedException
     */
    protected function validateCategoryProductCounts(SimpleXMLElement $xml)
    {
        $exportHelper = Mage::helper('emico_tweakwiseexport');
        $expectedCategoryCount = 1;
        $expectedProductCount = 0;
        $expectedProductCategoryCount = 0;

        /** @var Mage_Core_Model_Store $store */
        foreach (Mage::app()->getStores() as $store) {
            if (!$store->getIsActive() || !$exportHelper->isEnabled($store)) {
                continue;
            }

            $expectedCategories = $this->getExpectedCategories($store);
            $expectedCategoryCount += count($expectedCategories);
            $expectedProducts = $this->getExpectedProducts($store);
            if (count($expectedProducts)) {
                $expectedProductCount += count($expectedProducts);
            } else {
                $expectedProductCount++;
            }

            $expectedProductCategoryCount += $this->getExpectedProductCategoryCount($store, $expectedProducts, $expectedCategories);
        }

        $categoryCount = count($xml->xpath("/tweakwise/categories/category"));
        if ($categoryCount != $expectedCategoryCount) {
            throw new Emico_TweakwiseExport_Model_Exception_InvalidFeedException(Mage::helper('emico_tweakwiseexport')->__(
                'Invalid amount of categories exported. Found categories: %s out of %s expected', $categoryCount, $expectedCategoryCount
            ));
        }

        $productCount = count($xml->xpath("/tweakwise/item"));
        if ($productCount != $expectedProductCount) {
            throw new Emico_TweakwiseExport_Model_Exception_InvalidFeedException(Mage::helper('emico_tweakwiseexport')->__(
                'Invalid amount of products exported. Found products: %s out of %s expected', $productCount, $expectedProductCount
            ));
        }

        $productCategoryCount = count($xml->xpath('/tweakwise/item/categories/categoryid'));
        if ($productCategoryCount != $expectedProductCategoryCount) {
            throw new Emico_TweakwiseExport_Model_Exception_InvalidFeedException(Mage::helper('emico_tweakwiseexport')->__(
                'Invalid amount of product category links exported. Found product category links: %s out of %s expected', $productCategoryCount, $expectedProductCategoryCount
            ));
        }
    }

    /**
     * @param Mage_Core_Model_Store $store
     *
     * @return int[]
     */
    protected function getExpectedCategories(Mage_Core_Model_Store $store)
    {
        $storeId = $store->getId();
        if (!isset($this->_expectedCategories[$storeId])) {
            $categoryResource = Mage::getResourceModel('catalog/category_collection');
            $categoryPath = Mage_Catalog_Model_Category::TREE_ROOT_ID . '/' . $store->getRootCategoryId();
            $categorySelect = $categoryResource
                ->setStore($store)
                ->addFieldToFilter('is_active', true)
                ->addAttributeToFilter(
                    [
                        ['attribute' => 'path', 'like' => $categoryPath],
                        ['attribute' => 'path', 'like' => $categoryPath . '/%'],
                    ]
                )
                ->addOrder('path', 'DESC')
                ->getSelect()
                ->reset(Zend_Db_Select::COLUMNS)
                ->columns(['entity_id', 'path']);
            $expectedCategories = $categoryResource->getConnection()->fetchPairs($categorySelect);

            foreach ($expectedCategories as $categoryId => $path) {
                foreach (explode('/', $path) as $id) {
                    if ($id == 1) {
                        continue;
                    }

                    // Filter inactive parents
                    if (!isset($expectedCategories[$id])) {
                        unset($expectedCategories[$categoryId]);
                        break;
                    }
                }
            }
            $this->_expectedCategories[$storeId] = array_keys($expectedCategories);
        }

        return $this->_expectedCategories[$storeId];
    }

    /**
     * @param Mage_Core_Model_Store $store
     *
     * @return int[]
     */
    protected function getExpectedProducts(Mage_Core_Model_Store $store)
    {
        $exportHelper = Mage::helper('emico_tweakwiseexport');

        /** @var Mage_Catalog_Model_Resource_Product_Collection $productCollection */
        $productCollection = Mage::getResourceModel('catalog/product_collection')
            ->addPriceData(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID, $store->getWebsiteId())
            ->addStoreFilter($store)
            ->addFieldToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED, true)
            ->addFieldToFilter('visibility', $exportHelper->getVisibilityFilter(), true);

        if (!$exportHelper->exportOutOfStockChildren($store)) {
            Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($productCollection);
        }
        $select = $productCollection->getSelect()
            ->reset(Zend_Db_Select::COLUMNS)
            ->columns('e.entity_id');

        return $productCollection->getConnection()->fetchCol($select);
    }

    /**
     * @param Mage_Core_Model_Store $store
     * @param int[] $expectedProducts
     * @param int[] $expectedCategories
     *
     * @return int
     */
    protected function getExpectedProductCategoryCount(Mage_Core_Model_Store $store, array $expectedProducts, array $expectedCategories)
    {
        if ($this->_expectedProductCategoryCount == null) {
            $resource = Mage::getResourceModel('catalog/category');
            $query = $resource->getReadConnection()->select()
                ->from(['cp' => $resource->getTable('catalog/category_product')], ['product_id'])
                ->joinInner(['ce' => $resource->getTable('catalog/category')], 'ce.entity_id = cp.category_id', 'path')
                ->query();

            $productCategoryIds = [];
            while ($row = $query->fetch()) {
                if (!isset($productCategoryIds[$row['product_id']])) {
                    $productCategoryIds[$row['product_id']] = [];
                }

                $pathCategories = explode('/', $row['path']);
                foreach ($pathCategories as $index => $categoryId) {
                    if ($index == 0) {
                        continue;
                    }

                    if (isset($productCategoryIds[$row['product_id']][$categoryId])) {
                        $isTopLevel = $productCategoryIds[$row['product_id']][$categoryId];
                    } else {
                        $isTopLevel = false;
                    }

                    $isTopLevel = $isTopLevel || ($index == count($pathCategories) - 1);
                    $productCategoryIds[$row['product_id']][$categoryId] = $isTopLevel;
                }
            }
            $this->_expectedProductCategoryCount = $productCategoryIds;
        }

        $anchorCategories = $this->filterNonAnchorCategories($store, $expectedCategories);

        $categoryLinkCount = 0;
        foreach ($expectedProducts as $productId) {
            if (isset($this->_expectedProductCategoryCount[$productId])) {
                foreach ($this->_expectedProductCategoryCount[$productId] as $categoryId => $isTopLevel) {
                    if ($categoryId == $store->getRootCategoryId()) {
                        $categoryLinkCount++;
                    } elseif ($isTopLevel && in_array($categoryId, $expectedCategories)) {
                        $categoryLinkCount++;
                    } elseif (in_array($categoryId, $anchorCategories)) {
                        $categoryLinkCount++;
                    }
                }
            } else { // Product belongs to no category so only top level is used
                $categoryLinkCount++;
            }
        }

        return $categoryLinkCount;
    }

    /**
     * @param Mage_Core_Model_Store $store
     * @param int[] $categories
     *
     * @return int[]
     */
    protected function filterNonAnchorCategories(Mage_Core_Model_Store $store, array $categories)
    {
        $storeId = $store->getId();
        if (!isset($this->_nonAnchorCategories[$storeId])) {
            $resource = Mage::getResourceModel('catalog/category_collection');
            $this->_nonAnchorCategories[$storeId] = $resource->getConnection()->fetchCol($resource
                ->setStore($store)
                ->addFieldToFilter('is_anchor', true)
                ->getSelect()
                ->reset(Zend_Db_Select::COLUMNS)
                ->columns('entity_id'));
        }

        return array_intersect($categories, $this->_nonAnchorCategories[$storeId]);
    }

    /**
     * Validate category parent en product category references.
     *
     * @param SimpleXMLElement $xml
     * @throws Emico_TweakwiseExport_Model_Exception_InvalidFeedException
     */
    protected function validateCategoryLinks(SimpleXMLElement $xml)
    {
        $categoryIdElements = $xml->xpath('/tweakwise/categories/category/categoryid');
        $categoryIds = [];
        foreach ($categoryIdElements as $id) {
            $categoryIds[] = (string)$id;
        }
        $categoryIds = array_flip($categoryIds);

        foreach ($xml->xpath('/tweakwise/categories/category/parents/categoryid') as $categoryIdElement) {
            $categoryId = (string)$categoryIdElement;
            if (!isset($categoryIds[$categoryId])) {
                throw new Emico_TweakwiseExport_Model_Exception_InvalidFeedException(Mage::helper('emico_tweakwiseexport')->__(
                    'Category parent reference %s not found', $categoryId
                ));
            }
        }

        foreach ($xml->xpath('/tweakwise/items/item/categories/categoryid') as $categoryIdElement) {
            $categoryId = (string)$categoryIdElement;
            if (!isset($categoryIds[$categoryId])) {
                throw new Emico_TweakwiseExport_Model_Exception_InvalidFeedException(Mage::helper('emico_tweakwiseexport')->__(
                    'Product category reference %s not found', $categoryId
                ));
            }
        }
    }
}
