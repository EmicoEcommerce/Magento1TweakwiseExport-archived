<?php
/**
 * @copyright (c) Emico 2015
 */

$abstractFile = dirname(__FILE__).'/abstract.php';
if (file_exists($abstractFile)) {
    require_once $abstractFile;
} else {
    require_once __DIR__ . '/../../../../shell/abstract.php';
}


class Emico_Tweakwise_Shell extends Mage_Shell_Abstract
{
    /**
     * Retrieve Usage Help Message
     *
     * @return string
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:  php -f tweakwise.php -- [options]

  --validate [<file>]           Validate latest or provided export file.
  --export                      Run new export
  --index                       Run required indices for export

USAGE;
    }

    /**
     * Run script
     *
     * @return void
     */
    public function run()
    {
        $hasValidArgument = false;
        $processSuccess = true;

        if ($this->getArg('index')) {
            $processSuccess = $processSuccess && $this->indexAction();
            $hasValidArgument = true;
        }

        if ($this->getArg('export')) {
            $processSuccess = $processSuccess && $this->exportAction();
            $hasValidArgument = true;
        }

        if ($this->getArg('validate')) {
            $processSuccess = $processSuccess && $this->validateAction();
            $hasValidArgument = true;
        }


        if (!$hasValidArgument) {
            echo $this->usageHelp();
        }

        if (!$processSuccess) {
            die(1);
        }
    }

    /**
     * @param string $message
     * @param string $coloredMessage
     */
    protected function printError($message, $coloredMessage)
    {
        $this->printColored('31', $message, $coloredMessage);
    }

    /**
     * @param string $message
     * @param string $coloredMessage
     */
    protected function printSuccess($message, $coloredMessage)
    {
        $this->printColored('32', $message, $coloredMessage);
    }

    /**
     * @param int $color
     * @param string $message
     * @param string $coloredMessage
     */
    protected function printColored($color, $message, $coloredMessage)
    {
        printf($message . PHP_EOL, "\033[0;{$color}m{$coloredMessage}\033[0m");
    }

    /**
     * Run required magento indices
     *
     * @return bool
     */
    public function indexAction()
    {
        $indexer = Mage::getModel('index/indexer');
        $indexes = array(
            'catalog_product_flat',
            'catalog_category_flat',
            'catalog_category_product',
            'catalog_product_price',
        );

        foreach ($indexes as $index) {
            $this->printSuccess('Running index %s', $index);
            $process = $indexer->getProcessByCode($index);

            if ($process instanceof Mirasvit_AsyncIndex_Model_Process) {
                $process->reindexEverything(true);
            } else {
                $process->reindexEverything();
            }
        }
    }

    /**
     * Run feed validation
     *
     * @return bool
     */
    public function validateAction()
    {
        $file = $this->getArg('validate');
        if (!is_string($file)) {
            $file = Mage::helper('emico_tweakwiseexport')->getTweakwiseFeedFile();
        }

        if (!file_exists($file)) {
            $this->printError('File not found: %s', $file);
            return false;
        }

        if (!is_readable($file)) {
            $this->printError('Could not read file: %s', $file);
            return false;
        }

        try {
            $this->printSuccess('Validating feed %s', $file);
            Mage::getModel('emico_tweakwiseexport/validate')->validate($file);
        } catch (Emico_TweakwiseExport_Model_Exception_InvalidFeedException $exception) {
            $this->printError('Feed validation failed: %s', $exception->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Run feed export
     *
     * @return bool
     */
    public function exportAction()
    {
        echo 'Started export feed' . PHP_EOL;
        $start = microtime(true);
        Mage::getModel('emico_tweakwiseexport/observer')->generateExport();
        $end = microtime(true);

        $helper = Mage::helper('emico_tweakwiseexport');
        $exception = $helper->getExportException();
        if ($exception) {
            $this->printError('Export failed: %s', $exception['message']);
            return false;
        } else {
            $this->printSuccess('Export finished. Duration: %s seconds', ($end - $start));
            if (!$this->getArg('validate')) {
                $this->printSuccess('Try validating with %s', '--validate');
            }
            return true;
        }
    }
}

$shell = new Emico_Tweakwise_Shell();
$shell->run();
