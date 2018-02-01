<?php
/**
 * @copyright (c) Emico 2015
 */

/**
 * Class Emico_TweakwiseExport_Model_Writer_CategoryFilterIterator
 */
class Emico_TweakwiseExport_Model_Writer_CategoryFilterIterator extends FilterIterator
{
    /**
     * @var array
     */
    protected $_exportIds = [];

    /**
     * {@inheritDoc}
     */
    public function accept()
    {

        $current = $this->current();

        if (!isset($current['path'])) {
            return true;
        }

        if (!isset($this->_exportIds[$current['store_id']])) {
            $this->_exportIds[$current['store_id']] = [];
        }

        $path = explode('/', $current['path']);
        foreach ($path as $parentId) {
            if ($parentId == 1) {
                continue;
            }
            if ($parentId == $current['entity_id']) {
                continue;
            }
            if (!isset($this->_exportIds[$current['store_id']][$parentId])) {
                return false;
            }
        }

        $this->_exportIds[$current['store_id']][$current['entity_id']] = true;

        return true;
    }
}
