<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Writer_Xml
 */
class Emico_TweakwiseExport_Model_Writer_Xml extends XMLWriter
{
    /**
     * @var resource
     */
    protected $handle;

    /**
     * @param resource $handle
     */
    public function __construct($handle)
    {
        $this->openMemory();
        if (Mage::getIsDeveloperMode()) {
            $this->setIndent(true);
            $this->setIndentString('    ');
        } else {
            $this->setIndent(false);
        }

        $this->handle = $handle;
    }

    /**
     * Start document
     *
     * @return Emico_TweakwiseExport_Model_Writer_Xml
     */
    public function documentStart()
    {
        $this->startDocument('1.0', 'UTF-8');
        parent::startElement('tweakwise'); // Start root
        $this->writeElement('shop', Mage::app()->getDefaultStoreView()->getFrontendName());
        $this->writeElement('timestamp', date('Y-m-d\TH:i:s.uP'));
        $output = $this->flush();
        fwrite($this->handle, $output);

        return $this;
    }

    /**
     * Write value in a single element. $value must be a scalar value
     *
     * @param string $elementName
     * @param mixed $value
     * @return Emico_TweakwiseExport_Model_Writer_Xml
     */
    public function writeElement($elementName, $value = null)
    {
        parent::startElement($elementName);
        if (!is_numeric($value) && !empty($value)) {
            $this->startCdata();
        }

        $this->text($value);

        if (!is_numeric($value) && !empty($value)) {
            $this->endCdata();
        }
        parent::endElement();

        return $this;
    }

    /**
     * Finish xml document
     *
     * @return Emico_TweakwiseExport_Model_Writer_Xml
     */
    public function documentFinish()
    {
        $this->endElement(); // </tweakwise>
        $this->endDocument();
        fwrite($this->handle, $this->flush());

        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return Emico_TweakwiseExport_Model_Writer_Xml
     */
    public function writeAttribute($name, $value)
    {
        parent::startElement('attribute');
        parent::writeAttribute('datatype', is_numeric($value) ? 'numeric' : 'text');
        $this->writeElement('name', $name);
        $this->writeElement('value', $this->xmlPrepare($value));
        parent::endElement(); // </attribute>

        return $this;
    }

    /**
     * @return void
     */
    public function flush($empty = true)
    {
        fwrite($this->handle, parent::flush($empty));
    }

    /**
     * @param string $value
     * @return string
     */
    protected function xmlPrepare($value)
    {
        $result = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $value);

        return $result;
    }
}
