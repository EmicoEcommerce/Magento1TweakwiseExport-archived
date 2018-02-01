<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Default TweakWise export helper
 *
 * Class Emico_TweakwiseExport_Helper_Data
 */
class Emico_TweakwiseExport_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH_EMAIL_ADDRESS = 'trans_email/ident_general/email';
    const XML_PATH_EMAIL_NAME = 'trans_email/ident_general/name';

    const TWEAKWISE_FILE_NAME = 'tweakwise-feed.xml';

    const VARIABLE_SCHEDULED_FOR_EXPORT = 'scheduled_for_execution';
    const VARIABLE_EXPORT_EXCEPTION = 'export_exception';

    const EXPORT_STATE_SCHEDULED = 1;
    const EXPORT_STATE_STARTED = 2;
    const EXPORT_STATE_COMPLETE = 0;

    /**
     * @var Mage_Catalog_Model_Resource_Eav_Attribute[]|Mage_Catalog_Model_Resource_Product_Attribute_Collection
     */
    protected $_attributeCollections = null;

    /**
     * Get attribute data for attribute
     *
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute
     * @param Mage_Catalog_Model_Product $product
     * @param bool $scalar
     * @param bool $multiplyPrice
     *
     * @return array
     */
    public function getAttributeData(Mage_Catalog_Model_Resource_Eav_Attribute $attribute, Mage_Catalog_Model_Product $product, $scalar = true, $multiplyPrice = true)
    {
        $product->getResource()->getAttribute($attribute->getAttributeCode())->setDataObject($product);
        $value = $product->getData($attribute->getAttributeCode());
        if (!is_array($value)) {
            switch ($attribute->getFrontendInput()) {
                case 'multiselect':
                    $value = $product->getAttributeText($attribute->getAttributeCode());
                    break;
                case 'select':
                case 'boolean':
                    $value = $product->getAttributeText($attribute->getAttributeCode());
                    break;
                default:
                    $value = $product->getData($attribute->getAttributeCode());
                    break;
            }
        }

        if (empty($value)) {
            return null;
        }

        // Tweakwise has prices in cents
        if ($multiplyPrice && $attribute->getFrontendInput() == 'price') {
            if (is_array($value)) {
                foreach ($value as $key => $v) {
                    $value[$key] = round($v * 100);
                }
            } else {
                $value = round($value * 100);
            }
        }

        return $scalar ? $this->scalarValue($value) : $value;
    }

    /**
     * Get scalar value from object, array or scalar value
     *
     * @param mixed $value
     *
     * @return string|array
     */
    public function scalarValue($value)
    {
        if (is_array($value)) {
            $data = [];
            foreach ($value as $key => $childValue) {
                $data[$key] = $this->scalarValue($childValue);
            }

            return $data;
        } else {
            if (is_object($value)) {
                if (method_exists($value, 'toString')) {
                    $value = $value->toString();
                } else {
                    if (method_exists($value, '__toString')) {
                        $value = (string)$value;
                    } else {
                        $value = spl_object_hash($value);
                    }
                }
            }
        }

        return $value;
    }

    /**
     * True if module is enabled in the settings
     *
     * @param mixed $store
     *
     * @return boolean
     */
    public function isEnabled($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/enabled', $store);
    }

    /**
     * True if export should be rendered real time
     *
     * @param mixed $store
     *
     * @return boolean
     */
    public function isRealTime($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/real_time', $store);
    }

    /**
     * Export notification mail
     *
     * @param string $message
     *
     * @return bool
     * @throws Emico_TweakwiseExport_Model_Exception
     */
    public function sendNotificationMail($message)
    {
        if (!$this->isNotificationEmailEnabled()) {
            return false;
        }

        try {
            $mail = Mage::getModel('core/email')
                ->setSubject('TweakwiseExport: Notification email')
                ->setBody('<p>' . $message . '</p>')
                ->setFromName(Mage::getStoreConfig(self::XML_PATH_EMAIL_NAME))
                ->setFromEmail(Mage::getStoreConfig(self::XML_PATH_EMAIL_ADDRESS))
                ->setType('html');
            foreach ($this->getNotificationReceivers() as $receiver) {
                $mail->setToEmail(trim($receiver))
                    ->send();
            }

            return true;
        } catch (Zend_Mail_Transport_Exception $e) {
            throw new Emico_TweakwiseExport_Model_Exception('Possible invalid emailaddress provided in in System -> Configuration -> Tweakwise -> Export -> Notification emailaddress', 0, $e);
        } catch (Exception $e) {
            throw new Emico_TweakwiseExport_Model_Exception('Sending report failed', 0, $e);
        }
    }

    /**
     * Returns email if email notification is enabled
     *
     * @param string $store
     *
     * @return bool
     */
    public function isNotificationEmailEnabled($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/email_notification', $store);
    }

    /**
     * Returns email if email notification is enabled
     *
     * @param string $store
     *
     * @return array
     */
    public function getNotificationReceivers($store = null)
    {
        $receivers = Mage::getStoreConfig('emico_tweakwise/export/email_notification', $store);

        return explode(',', $receivers);
    }

    /**
     * @return bool
     * @throws Emico_TweakwiseExport_Model_Exception
     */
    public function sendImportTriggerToTweakwise()
    {
        if (!$this->istriggerTweakwiseImportEnabled()) {
            return false;
        }

        //Request to Tweakwise for import feed.
        $client = $this->getTweakwiseApiClient();
        if (!$client) {
            return false;
        }
        $response = $client->request();

        if ($response->getStatus() == 200) {
            $this->log('Auto Tweakwise import succesfully started');
        } else {
            $previous = libxml_use_internal_errors(true);
            $xmlElement = simplexml_load_string($response->getBody());
            if ($xmlElement === false) {
                $this->log('Invalid response received by Tweakwise server');
                throw new Emico_TweakwiseExport_Model_Exception('Invalid response received by Tweakwise server');
            }
            libxml_use_internal_errors($previous);

            $this->log('Auto import fails, response is: ' . $xmlElement->message);
        }
    }

    /**
     * True if after successful export, must be trigger the Tweakwise import functionality
     *
     * @param string $store
     *
     * @return boolean
     */
    public function istriggerTweakwiseImportEnabled($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/trigger_import_after_export', $store);
    }

    /**
     * @return null|Zend_Http_Client
     * @throws Emico_Tweakwise_Model_Bus_Request_Exception
     * @throws Emico_TweakwiseExport_Model_Exception
     */
    public function getTweakwiseApiClient()
    {
        $apiKey = Mage::getStoreConfig('emico_tweakwise/export/import_api_key');
        $serverUrl = Mage::getStoreConfig('emico_tweakwise/export/server_api_url');
        if (!empty($apiKey) && !empty($serverUrl)) {
            try {
                $client = new Zend_Http_Client($serverUrl);
            } catch (Zend_Http_Client_Exception $e) {
                throw new Emico_TweakwiseExport_Model_Exception('Invalid uri provided in in System -> Configuration -> Tweakwise -> Export -> Server API url', 0, $e);
            } catch (Zend_Uri_Exception $e) {
                throw new Emico_TweakwiseExport_Model_Exception('Invalid uri provided in in System -> Configuration -> Tweakwise -> Export-> Server API url', 0, $e);
            }

            $key = Mage::getStoreConfig('emico_tweakwise/global/key');
            if (empty($key)) {
                throw new Emico_Tweakwise_Model_Bus_Request_Exception('Please provide a valid tweakwise key in System -> Configuration -> Tweakwise -> Key');
            } else {
                if (!preg_match('/[a-z0-9]{6}/i', $key)) {
                    throw new Emico_Tweakwise_Model_Bus_Request_Exception('Please provide a valid tweakwise key in System -> Configuration -> Tweakwise -> Key');
                }
            }

            $uri = $client->getUri();
            $client->setUri(rtrim($uri . '/' . $key . '/' . $apiKey));

            return $client;
        }

        return null;
    }

    /**
     * Log to tweakwise.log if log setting is enabled.
     *
     * @param string $message
     *
     * @return boolean
     */
    public function log($message)
    {
        if (!Mage::getStoreConfig('emico_tweakwise/global/log_enabled')) {
            return false;
        }

        if (Mage::getStoreConfig('emico_tweakwise/global/log_only_developer_requests') && !Mage::helper('core')->isDevAllowed()) {
            return false;
        }

        Mage::log($message, null, 'tweakwise.log');

        return true;
    }

    /**
     * @param string|int|Mage_Core_Model_Store $store
     *
     * @return int
     */
    public function stockCombineType($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/stock_combination', $store);
    }

    /**
     * @param string|int|Mage_Core_Model_Store $store
     *
     * @return string
     */
    public function feedSuffix($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/protected', $store);
    }

    /**
     * @param string|int|Mage_Core_Model_Store $store
     * @param int $id
     *
     * @return int
     */
    public function toStoreId($store, $id)
    {
        $store = Mage::app()->getStore($store);

        // Prefix 1 is to make sure it stays the same length when casting to int
        return '1' . str_pad($store->getId(), 4, '0', STR_PAD_LEFT) . $id;
    }

    /**
     * @param int $id
     *
     * @return int
     */
    public function fromStoreId($id)
    {
        return substr($id, 5);
    }

    /**
     * @param $categoryId
     * @param int $storeId
     *
     * @return bool
     */
    public function isCategoryTreeActive($categoryId, $storeId = null)
    {
        if (is_null($storeId)) {
            $storeId = Mage::app()->getStore()->getId();
        }
        $category = Mage::getModel('catalog/category')->setStoreId($storeId)->load($categoryId);

        if (!$category->getIsActive()) {
            return false;
        }

        if ($category->getId() == 1) {
            return true;
        }

        if ($category->getData('parent_id')) {
            if ($category->getData('parent_id') == 1) {
                return true;
            }

            return $this->isCategoryTreeActive($category->getData('parent_id'), $storeId);
        }

        return true;
    }

    /**
     * @param string|int|Mage_Core_Model_Store $store
     *
     * @return array
     */
    public function getVisibilityFilter($store = null)
    {
        $config = Mage::getStoreConfig('emico_tweakwise/export/product_selection_filter', $store);
        $visibility = Mage::getSingleton('catalog/product_visibility');
        switch ($config) {
            case Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG:
                return $visibility->getVisibleInCatalogIds();
                break;
            case Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH:
                return $visibility->getVisibleInSearchIds();
                break;
            case Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH:
            default:
                return $visibility->getVisibleInSiteIds();
                break;
        }
    }

    /**
     * @return Mage_Catalog_Model_Resource_Eav_Attribute[]|Mage_Catalog_Model_Resource_Product_Attribute_Collection
     */
    public function getAttributes()
    {
        if (!$this->_attributeCollections) {
            $attributes = [];

            $attributeCollection = Mage::getResourceModel('catalog/product_attribute_collection')
                ->addFieldToFilter(
                    ['used_in_product_listing', 'is_filterable', 'is_filterable_in_search', 'is_searchable', 'is_visible_in_advanced_search', 'used_for_sort_by'],
                    [true, true, true, true, true, true]
                );

            /** @var Mage_Catalog_Model_Resource_Eav_Attribute $attribute */
            foreach ($attributeCollection as $attribute) {
                $attributes[$attribute->getAttributeCode()] = $attribute;
            }

            $this->_attributeCollections = $attributes;
        }

        return $this->_attributeCollections;
    }

    /**
     * @param string $attribute
     *
     * @return bool
     */
    public function isMergedAttribute($attribute)
    {
        $excludedAttributes = explode(',', Mage::getStoreConfig('emico_tweakwise/export/exclude_child_attributes'));

        return !in_array($attribute, $excludedAttributes);
    }

    /**
     * @param string $attribute
     *
     * @return bool
     */
    public function isSpecialAttribute($attribute)
    {
        return in_array(strtolower($attribute), $this->getSpecialAttributes());
    }

    /**
     * @return string[]
     */
    public function getSpecialAttributes()
    {
        return ['price', 'qty'];
    }

    /**
     * @param string|int|Mage_Core_Model_Store $store
     *
     * @return bool
     */
    public function exportOutOfStockChildren($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/oos_children', $store);
    }

    /**
     * @param string|int|Mage_Core_Model_Store $store
     *
     * @return bool
     */
    public function getIsAddStockPercentage($store = null)
    {
        return Mage::getStoreConfig('emico_tweakwise/export/stock_percentage', $store);
    }

    /**
     * @return string
     */
    public function getTweakwiseFeedFile()
    {
        return Mage::getBaseDir('export') . DS . self::TWEAKWISE_FILE_NAME;
    }

    /**
     * @param $state
     * @param $stateCode
     *
     * @return mixed
     */
    public function writeExportState($state, $stateCode)
    {
        return $this->getVariable(self::VARIABLE_SCHEDULED_FOR_EXPORT)
            ->setName($state)
            ->setPlainValue($stateCode)
            ->save();
    }

    /**
     * @param string $identifier
     *
     * @return Mage_Core_Model_Variable
     */
    public function getVariable($identifier)
    {
        if (!($variable = Mage::registry('emico_tweakwiseexport_' . $identifier))) {
            $variable = Mage::getModel('core/variable');
            $variable->setCode('tweakwiseexport_' . $identifier);
            $variable->loadByCode('tweakwiseexport_' . $identifier);
            Mage::register('emico_tweakwiseexport_' . $identifier, $variable);
        }

        return $variable;
    }

    /**
     * @return int
     */
    public function getExportState()
    {
        return $this->getVariable(self::VARIABLE_SCHEDULED_FOR_EXPORT)
            ->getValue();
    }

    /**
     * @param Exception $e
     *
     * @return mixed
     */
    public function setExportException(Exception $e = null)
    {
        if ($e) {
            $value = [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        } else {
            $value = $e;
        }

        return $this->getVariable(self::VARIABLE_EXPORT_EXCEPTION)
            ->setPlainValue(serialize($value))
            ->save();
    }

    /**
     * @return Exception|null
     */
    public function getExportException()
    {
        $variable = $this->getVariable(self::VARIABLE_EXPORT_EXCEPTION);
        $value = $variable->getValue(Mage_Core_Model_Variable::TYPE_TEXT);
        if ($value) {
            return @unserialize($value);
        }

        return null;
    }

    /**
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute
     * @param mixed $originalValue
     * @return array
     */
    public function getAttributeValues(Mage_Catalog_Model_Resource_Eav_Attribute $attribute, $originalValue)
    {
        $originalValue = explode(Emico_TweakwiseExport_Model_Writer_Writer::ATTRIBUTE_SEPARATOR, $originalValue);
        switch ($attribute->getFrontend()->getInputType()) {
            case 'select':
                $actualValues = $this->getSelectAttributeValue($attribute, $originalValue);
                break;
            case 'multiselect':
                $actualValues = $this->getMultiSelectAttributeValue($attribute, $originalValue);
                break;
            default:
                $actualValues = $originalValue;
                break;
        }

        $actualValues = array_filter($actualValues);
        array_unique($actualValues);

        return $actualValues;
    }

    /**
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute
     * @param array $originalValue
     * @return array
     */
    protected function getSelectAttributeValue(Mage_Catalog_Model_Resource_Eav_Attribute $attribute, array $originalValue)
    {
        $displayValues = [];
        $source = $attribute->getSource();
        array_unique($displayValues);

        $newValues = [];
        foreach ($originalValue as $valueId) {
            if (($result = $source->getOptionText($valueId)) === false) {
                $result = $valueId;
            }

            $newValues[] = $result;
        }

        return $newValues;
    }

    /**
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute
     * @param array $originalValue
     * @return array
     */
    protected function getMultiSelectAttributeValue(Mage_Catalog_Model_Resource_Eav_Attribute $attribute, array $originalValue)
    {
        $values = [];
        foreach ($originalValue as $value) {
            $values = array_merge($values, $this->getSelectAttributeValue($attribute, explode(',', $value)));
        }

        return $values;
    }

    /**
     * In some cases we want to know what te parent value of these attributes are
     *
     * @return array
     */
    public function getParentAttributes()
    {
        // Return format: $alias => $attributeCode
        return ['main_sku' => 'sku'];
    }
}
