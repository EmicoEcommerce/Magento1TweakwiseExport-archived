<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Export feed controller
 *
 * Class Emico_TweakwiseExport_FeedController
 */
class Emico_TweakwiseExport_FeedController extends Mage_Core_Controller_Front_Action
{
    /**
     * Action feed suffix
     *
     * @param string $urlSuffix
     * @return bool
     */
    public function hasAction($urlSuffix)
    {
        $configSuffix = Mage::helper('emico_tweakwiseexport')->feedSuffix();
        if ($configSuffix && $urlSuffix != $configSuffix) {
            return false;
        }

        return true;
    }

    /**
     * Extended to force the feed action
     *
     * {@inheritDoc}
     */
    public function getActionMethodName($storeCode)
    {
        return 'feedAction';
    }

    /**
     * Export products & categories according to tweakwise specifications
     */
    public function feedAction()
    {
        if (Mage::helper('emico_tweakwiseexport')->isRealTime()) {
            try {
                $this->getResponse()->setHeader('Content-Type', 'text/xml');
                $this->getResponse()->sendHeaders();
                Mage::getModel('emico_tweakwiseexport/writer')->write('php://output', true);
                exit(0);
            } catch (Exception $e) {
            }
        } else {
            /* @var $helper Emico_TweakwiseExport_Helper_Data */
            $helper = Mage::helper('emico_tweakwiseexport');

            $file = $helper->getTweakwiseFeedFile();
            if (!file_exists($file)) {
                $result = Mage::getModel('emico_tweakwiseexport/writer')->write($file);
            }

            if (file_exists($file)) {
                $this->getResponse()->setHeader('Content-Type', 'text/xml');
                $this->getResponse()->sendHeaders();
                fpassthru(fopen($file, 'rb'));
                exit(0);
            } else {
                echo 'No feed';
            }
        }
    }
}
