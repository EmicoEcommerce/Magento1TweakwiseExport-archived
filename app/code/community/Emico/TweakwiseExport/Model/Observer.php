<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Observer observes events and cronjobs
 */
class Emico_TweakwiseExport_Model_Observer
{
    /**
     * Generate export file to var/export
     */
    public function generateExport()
    {
        /* @var $helper Emico_TweakwiseExport_Helper_Data */
        $helper = Mage::helper('emico_tweakwiseexport');

        // Don't render for real time stores
        if ($helper->isRealTime() || !$helper->isEnabled()) {
            return;
        }

        // Render and measure time
        Mage::getModel('emico_tweakwiseexport/writer')
            ->write($helper->getTweakwiseFeedFile());

        // Always reset export state to complete after a finished export
        $helper->writeExportState('Completed', Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_COMPLETE);
    }

    /**
     * Check a single execution of generating the feed is desirable
     */
    public function manualExport()
    {
        /* @var $helper Emico_TweakwiseExport_Helper_Data */
        $helper = Mage::helper('emico_tweakwiseexport');

        // Don't render for real time stores
        if ($helper->isRealTime() || !$helper->isEnabled()) {
            $helper->writeExportState('Completed', Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_COMPLETE);
            return;
        }

        if ($helper->getExportState() == Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_SCHEDULED) {
            $helper->writeExportState('Scheduled', Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_STARTED);
            $helper->log('Manual feed generation is started');

            //Render and measure time
            Mage::getModel('emico_tweakwiseexport/writer')
                ->write($helper->getTweakwiseFeedFile());

            $helper->writeExportState('Completed', Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_COMPLETE);
        } elseif ($helper->getExportState() == Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_STARTED) {
            // Check if not running for to long
            $exportFile = new SplFileInfo(Mage::getBaseDir('export') . DS . 'tweakwise-feed-tmp.xml');
            if (!$exportFile->isFile()) {
                $helper->writeExportState('Completed', Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_COMPLETE);
                $helper->log('Reset export state since there is no tmp export file');
            } elseif ($exportFile->getMTime() < (time() - 3600 * 12)) {
                $helper->writeExportState('Completed', Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_COMPLETE);
                $helper->log('Reset export state since tmp export file has not been modified for the last 12 hours');
            }
        }
    }

    /**
     * @param Varien_Event_Observer $observer
     *
     * @throws Emico_TweakwiseExport_Model_Exception_ExportException
     */
    public function emicoTweakwiseValidateExport(Varien_Event_Observer $observer)
    {
        if (!Mage::getStoreConfigFlag('emico_tweakwise/export/validate_export')) {
            return;
        }

        /** @var Array $product */
        $file = $observer->getData('file');
        if (!$file) {
            throw new Emico_TweakwiseExport_Model_Exception_ExportException('Only able to check non real time exports. Please disable real time export.');
        }

        if (!file_exists($file)) {
            throw new Emico_TweakwiseExport_Model_Exception_ExportException('Only able to check non real time exports. Please disable real time export.');
        }

        Mage::getModel('emico_tweakwiseexport/validate')->validate($file);
    }

    /**
     * Export non flat attributes
     *
     * @param Varien_Event_Observer $observer
     */
    public function addNotExportedAttributes(Varien_Event_Observer $observer)
    {
        if (!Mage::getStoreConfigFlag('emico_tweakwise/export/export_non_flat_attributes')) {
            return;
        }

        /** @var Emico_TweakwiseExport_Model_Writer_Xml $writer */
        $writer = $observer->getData('writer');
        /** @var array $product */
        $product = $observer->getData('product');

        Mage::getSingleton('emico_tweakwiseexport/writer_productAttributes')->writeExtraAttributes($writer, $product['store_id'], $product['entity_id']);
    }
}
