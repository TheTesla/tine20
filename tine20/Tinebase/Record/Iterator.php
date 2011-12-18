<?php
/**
 * Tine 2.0
 *
 * @package     Tinebase
 * @subpackage  Record
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @copyright   Copyright (c) 2011 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Philipp Schüle <p.schuele@metaways.de>
 */

/**
 * class Tinebase_Record_Iterator
 *
 * @package     Tinebase
 * @subpackage  Record
 */
class Tinebase_Record_Iterator
{
    /**
     * class with function to call for each record
     *
     * @var Tinebase_Record_IteratableInterface
     */
    protected $_iteratable = NULL;

    /**
     * the function name to call in each iteration
     * 
     * @var string
     */
    protected $_function = NULL;

    /**
     * controller with search fn
     *
     * @var Tinebase_Controller_Record_Abstract
     */
    protected $_controller = NULL;

    /**
     * filter group
     *
     * @var Tinebase_Model_Filter_FilterGroup
     */
    protected $_filter = NULL;

    /**
     * pagination start
     * 
     * @var integer
     */
    protected $_start = 0;

    /**
     * options array
     * 
     * @var array
     */
    protected $_options = array(
        'limit'		    => 100,
        'searchAction'	=> 'get',
        'sortInfo'		=> NULL,
        'getRelations'  => FALSE,
    );

    /**
     * the constructor
     *
     * @param array $_params
     * 
     * @todo check interfaces
     */
    public function __construct($_params)
    {
        $requiredParams = array('controller', 'filter', 'function', 'iteratable');
        foreach ($requiredParams as $param) {
            if (isset($_params[$param])) {
                $this->{'_' . $param} = $_params[$param];
            } else {
                throw new Tinebase_Exception_InvalidArgument($param . ' required');
            }
        }

        if (isset($_params['options'])) {
            $this->_options = array_merge($this->_options, $_params['options']);
        }
    }

    /**
     * iterator batches of records
     * 
     * @return boolean|number
     */
    public function iterate()
    {
        $records = $this->_getRecords();
        if (count($records) < 1) {
            return FALSE;
        }

        $totalcount = count($records);
        while (count($records) > 0) {
            $arguments = array_merge(array($records), func_get_args());
            call_user_func_array(array($this->_iteratable, $this->_function), $arguments);

            $this->_start += $this->_options['limit'];
            $records = $this->_getRecords();
            $totalcount += count($records);
        }
        
        return $totalcount;
    }

    /**
     * get records and resolve fields
     *
     * @param integer  $_start
     * @param integer  $_limit
     * @return Tinebase_Record_RecordSet
     */
    protected function _getRecords()
    {
        // get records by filter (ensure acl)
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Getting records using filter: ' . print_r($this->_filter->toArray(), TRUE));
        $pagination = (! empty($this->_options['_sortInfo'])) ? new Tinebase_Model_Pagination($this->_options['_sortInfo']) : new Tinebase_Model_Pagination();
        if ($this->_start !== NULL) {
            $pagination->start = $this->_start;
            $pagination->limit = $this->_options['limit'];
        }
        $records = $this->_controller->search($this->_filter, $pagination, $this->_options['getRelations'], FALSE, $this->_options['searchAction']);

        return $records;
    }
}
