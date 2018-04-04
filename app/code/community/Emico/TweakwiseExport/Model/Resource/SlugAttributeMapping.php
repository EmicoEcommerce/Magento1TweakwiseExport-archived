<?php
/**
 * @author Bram Gerritsen <bgerritsen@emico.nl>
 * @copyright (c) Emico B.V. 2017
 */ 
class Emico_TweakwiseExport_Model_Resource_SlugAttributeMapping extends Mage_Core_Model_Resource_Db_Abstract
{

    protected function _construct()
    {
        $this->_init('emico_tweakwiseexport/slug_attribute_mapping', 'mapping_id');
    }

    /**
     * @param array $records
     * @return int Number of affected rows
     */
    public function truncateAndinsertBatch(array $records)
    {
        $mainTable = $this->getMainTable();
        $this->_getWriteAdapter()->truncateTable($mainTable);
        return $this->_getWriteAdapter()->insertMultiple($mainTable, $records);
    }
}