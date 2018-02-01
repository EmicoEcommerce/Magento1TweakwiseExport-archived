<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Export feed controller
 *
 * Class Emico_TweakwiseExport_FeedController
 */
class Emico_TweakwiseExport_Adminhtml_FeedController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Rebuild the feed manual with button in the admin area.
     */
    public function rebuildAction()
    {
        if (!$this->hasValidSuffix()) {
            Mage::getSingleton('adminhtml/session')->addError('Tweakwise feed export failed: No valid suffix');

            return $this->_redirectReferer();
        }

        try {
            /* @var $helper Emico_TweakwiseExport_Helper_Data */
            $helper = Mage::helper('emico_tweakwiseexport');

            Mage::getModel('emico_tweakwiseexport/writer')->write($helper->getTweakwiseFeedFile());
            Mage::getSingleton('adminhtml/session')->addSuccess('Tweakwise export feed successfully rebuild.');
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError('Tweakwise feed export failed: ' . $e->getMessage());
        }
        $this->_redirectReferer();
    }

    /**
     * Checks if suffix is used en valid for the request
     *
     * @return bool
     */
    private function hasValidSuffix()
    {
        $urlSuffix = $this->getRequest()->getParam('suffix');

        $configSuffix = Mage::helper('emico_tweakwiseexport')->feedSuffix();
        if ($configSuffix && $urlSuffix != $configSuffix) {
            return false;
        }

        return true;
    }

    /**
     * Schedule the export taks for the next cron iteration.
     */
    public function scheduleBuildFeedAction()
    {
        if (!$this->hasValidSuffix()) {
            Mage::getSingleton('adminhtml/session')->addError('Failed to schedule the  export task: No valid suffix');

            return $this->_redirectReferer();
        }

        /* @var $helper Emico_TweakwiseExport_Helper_Data */
        $helper = Mage::helper('emico_tweakwiseexport');

        try {
            $session = Mage::getSingleton('adminhtml/session');

            // Don't render for real time stores
            if ($helper->isRealTime() || !$helper->isEnabled()) {
                $session->addError($helper->__('Feed wont export in the background when disabled or set to real time.'));
            } else {
                $helper->writeExportState('Scheduled', Emico_TweakwiseExport_Helper_Data::EXPORT_STATE_SCHEDULED);
                $session->addSuccess($helper->__('Tweakwise feed successfully scheduled for export.'));
            }

        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($helper->__('Failed to schedule the  export task: %s', $e->getMessage()));
        }
        $this->_redirectReferer();
    }
}
