<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Writer
 */
class Emico_TweakwiseExport_Model_Writer
{
    /**
     * @var bool
     */
    protected $_outputFlag = true;

    /**
     * Write output of currently set store, category and product iterator to output stream.
     *
     * @see http://php.net/manual/en/wrappers.php.php
     *
     * @param string $output
     * @param bool $realtime
     *
     * @return bool
     * @throws Exception
     */
    public function write($output, $realtime = false)
    {
        $start = microtime(true); // Start timing xml generation

        /* @var $appEmulation Mage_Core_Model_App_Emulation */
        $appEmulation = Mage::getSingleton('core/app_emulation');

        $helper = Mage::helper('emico_tweakwiseexport');

        if (!$realtime) {
            $output = Mage::getBaseDir('export') . DS . 'tweakwise-feed-tmp.xml';
        }

        $initialEnvironmentInfo = null;
        $handle = null;
        $lockHandle = null;
        try {
            // Open output stream
            $handle = fopen($output, 'w');
            if ($realtime) {
                $lockHandle = fopen(Mage::getBaseDir('var') . DS . 'locks' . DS . 'twexport.lock', 'w');
            } else {
                $lockHandle = $handle;
            }

            if (flock($lockHandle, LOCK_EX)) { // acquire an exclusive lock

                // Start store emulator to make sure all our collections select the correct default values
                $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation(Mage_Core_Model_App::ADMIN_STORE_ID);

                if (!$this->getWriter()->write($handle)) {
                    throw new Emico_TweakwiseExport_Model_Exception_ExportException('Feed export unsuccessful. Please try again.');
                }
                $this->setOutputFlag(true);

                Mage::dispatchEvent('emico_tweakwiseexport_before_copy_feed', [
                    'file' => $output,
                    'writer' => $this,
                ]);
                if (!$this->getOutputFlag(true)) {
                    throw new Emico_TweakwiseExport_Model_Exception_ExportException('Output flag set to false by one of the listeners from "emico_tweakwiseexport_before_copy_feed". Please use a "Emico_TweakwiseExport_Model_Exception_ExportException" for a better error message.');
                }

                //Move tmp file to production file
                if (!$realtime) {
                    if (rename($output, Mage::getBaseDir('export') . DS . 'tweakwise-feed.xml') === false) {
                        $this->setOutputFlag(false);
                    }
                }

                // Finish store emulation
                $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
                $duration = (microtime(true) - $start);
                $helper->log('Generated emico_tweakwise took ' . round($duration, 2) . ' seconds');

                flock($lockHandle, LOCK_UN); // release the lock
                if ($lockHandle !== $handle) {
                    fclose($lockHandle);
                }
                fclose($handle);
            } else {
                throw new Emico_TweakwiseExport_Model_Exception_ExportException('Unable to obtain lock');
            }

            $helper->setExportException();
            $helper->sendImportTriggerToTweakwise();

            Mage::dispatchEvent('emico_tweakwiseexport_after_export', [
                'file' => $output,
                'writer' => $this,
                'duration' => $duration,
            ]);
            return $this->getOutputFlag();
        } catch (Emico_TweakwiseExport_Model_Exception_ExportException $e) {
            $this->handleExportException($e, $lockHandle, $handle, $initialEnvironmentInfo);

            return false;
        } catch (Exception $e) {
            $this->handleExportException($e, $lockHandle, $handle, $initialEnvironmentInfo);
            throw $e;
        }
    }

    /**
     * @return Emico_TweakwiseExport_Model_Writer_Writer
     */
    public function getWriter()
    {
        return Mage::getSingleton('emico_tweakwiseexport/writer_writer');
    }

    /**
     * @return boolean
     */
    public function getOutputFlag()
    {
        return $this->_outputFlag;
    }

    /**
     * @param boolean $state
     *
     * @return $this
     */
    public function setOutputFlag($state)
    {
        $this->_outputFlag = (boolean)$state;

        return $this;
    }

    /**
     * @param Exception $e
     * @param resource|null $lockHandle
     * @param resource|null $feedHandle
     * @param Varien_Object|null $environmentInfo
     */
    protected function handleExportException(Exception $e, $lockHandle, $feedHandle, $environmentInfo)
    {
        // Finish store emulation
        if ($environmentInfo) {
            Mage::getSingleton('core/app_emulation')->stopEnvironmentEmulation($environmentInfo);
        }

        if (is_resource($feedHandle)) {
            fclose($feedHandle);
        }

        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        $helper = Mage::helper('emico_tweakwiseexport');
        $message = $helper->__('Feed is not generated due to: "%s"', $e->getMessage());

        $helper->setExportException($e);
        $helper->sendNotificationMail($message);
        $helper->log($message);
        $helper->log($e->__toString());
    }
}
