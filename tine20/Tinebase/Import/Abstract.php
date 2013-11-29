<?php
/**
 * Tine 2.0
 * 
 * @package     Tinebase
 * @subpackage  Import
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2010-2013 Metaways Infosystems GmbH (http://www.metaways.de)
 */

/**
 * abstract Tinebase Import
 * 
 * @package Tinebase
 * @subpackage  Import
 */
abstract class Tinebase_Import_Abstract implements Tinebase_Import_Interface
{
    /**
     * import result array
     * 
     * @var array
     */
    protected $_importResult = array(
        'results'           => NULL,
        'exceptions'        => NULL,
        'totalcount'        => 0,
        'updatecount'       => 0,
        'failcount'         => 0,
        'duplicatecount'    => 0,
    );
    
    /**
     * possible configs with default values
     * 
     * @var array
     */
    protected $_options = array(
        'dryrun'            => false,
        'updateMethod'      => 'update',
        'createMethod'      => 'create',
        'model'             => '',
        'shared_tags'       => 'create', //'onlyexisting',
        'autotags'          => array(),
        'encoding'          => 'auto',
        'encodingTo'        => 'UTF-8',
        'useStreamFilter'   => true,
        'postMappingHook'   => null,
        // if this is set, always resolve duplicates
        'duplicateResolveStrategy' => null,
    );
    
    /**
     * additional config options (to be added by child classes)
     * 
     * @var array
     */
    protected $_additionalOptions = array();
    
    /**
     * the record controller
     *
     * @var Tinebase_Controller_Record_Interface
     */
    protected $_controller = NULL;
    
    /**
     * constructs a new importer from given config
     * 
     * @param array $_options
     */
    public function __construct(array $_options = array())
    {
        $this->_options = array_merge($this->_options, $this->_additionalOptions);
        
        foreach($_options as $key => $cfg) {
            if (array_key_exists($key, $this->_options)) {
                $this->_options[$key] = $cfg;
            }
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ 
            . ' Creating importer with following config: ' . print_r($this->_options, TRUE));
    }
    
    /**
     * import given filename
     * 
     * @param string $_filename
     * @param array $_clientRecordData
     * @return @see{Tinebase_Import_Interface::import}
     */
    public function importFile($_filename, $_clientRecordData = array())
    {
        if (preg_match('/^win/i', PHP_OS)) {
           $_filename = utf8_decode($_filename);
        }
        if (! file_exists($_filename)) {
            throw new Tinebase_Exception_NotFound("File $_filename not found.");
        }
        $resource = fopen($_filename, 'r');
        
        $retVal = $this->import($resource, $_clientRecordData);
        fclose($resource);
        
        return $retVal;
    }
    
    /**
     * import from given data
     * 
     * @param string $_data
     * @param array $_clientRecordData
     * @return @see{Tinebase_Import_Interface::import}
     */
    public function importData($_data, $_clientRecordData = array())
    {
        $resource = fopen('php://memory', 'w+');
        fwrite($resource, $_data);
        rewind($resource);
        
        $retVal = $this->import($resource);
        fclose($resource);
        
        return $retVal;
    }
    
    /**
     * import the data
     *
     * @param resource $_resource (if $_filename is a stream)
     * @param array $_clientRecordData
     * @return array with import data (imported records, failures, duplicates and totalcount)
     */
    public function import($_resource = NULL, $_clientRecordData = array())
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
            . ' Starting import of ' . ((! empty($this->_options['model'])) ? $this->_options['model'] . 's' : ' records'));
        
        $this->_initImportResult();
        $this->_appendStreamFilters($_resource);
        $this->_beforeImport($_resource);
        $this->_doImport($_resource, $_clientRecordData);
        $this->_logImportResult();
        
        return $this->_importResult;
    }
    
    /**
     * append stream filter for correct linebreaks
     * - iconv with IGNORE
     * - replace linebreaks
     * 
     * @param resource $resource
     */
    protected function _appendStreamFilters($resource)
    {
        if (! $resource || ! isset($this->_options['useStreamFilter']) || ! $this->_options['useStreamFilter']) {
            return;
        }

        if (! isset($this->_options['encoding']) || $this->_options['encoding'] === 'auto' && extension_loaded('mbstring')) {
            require_once 'StreamFilter/ConvertMbstring.php';
            $filter = 'convert.mbstring';
        } else if (isset($this->_options['encoding']) && $this->_options['encoding'] !== $this->_options['encodingTo']) {
            $filter = 'convert.iconv.' . $this->_options['encoding'] . '/' . $this->_options['encodingTo'] . '//IGNORE';
        } else {
            $filter = NULL;
        }
            
        if ($filter !== NULL) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
                . ' Add convert stream filter: ' . $filter);
            stream_filter_append($resource, $filter);
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
            . ' Adding streamfilter for correct linebreaks');
        require_once 'StreamFilter/StringReplace.php';
        $filter = stream_filter_append($resource, 'str.replace', STREAM_FILTER_READ, array(
            'search'            => '/\r\n{0,1}/',
            'replace'           => "\r\n",
            'searchIsRegExp'    => TRUE
        ));
    }
    
    /**
     * init import result data
     */
    protected function _initImportResult()
    {
        $this->_importResult['results']     = (! empty($this->_options['model'])) ? new Tinebase_Record_RecordSet($this->_options['model']) : array();
        $this->_importResult['exceptions']  = new Tinebase_Record_RecordSet('Tinebase_Model_ImportException');
    }
    
    /**
     * do something before the import
     * 
     * @param resource $_resource
     */
    protected function _beforeImport($_resource = NULL)
    {
    }
    
    /**
     * do import: loop data -> convert to records -> import records
     * 
     * @param mixed $_resource
     * @param array $_clientRecordData
     */
    protected function _doImport($_resource = NULL, $_clientRecordDatas = array())
    {
        $clientRecordDatas = $this->_sortClientRecordsByIndex($_clientRecordDatas);
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__
            . ' Client record data: ' . print_r($clientRecordDatas, TRUE));
        
        $recordIndex = 0;
        while (($recordData = $this->_getRawData($_resource)) !== FALSE) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
                . ' Importing record ' . $recordIndex . ' ...');
            $recordToImport = NULL;
            try {
                // client record overwrites record in import data (only if set)
                $clientRecordData = array_key_exists($recordIndex, $clientRecordDatas) ? $clientRecordDatas[$recordIndex]['recordData'] : NULL;
                if ($clientRecordData && Tinebase_Core::isLogLevel(Zend_Log::TRACE)) {
                    Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' Client record: ' . print_r($clientRecordData, TRUE));
                }
                
                // NOTE _processRawData might return multiple recordDatas
                // NOTE $clientRecordData is always one record
                $recordDataToImport = $clientRecordData ? array($clientRecordData) : $this->_processRawData($recordData);
                $resolveStrategy = $clientRecordData ? $clientRecordDatas[$recordIndex]['resolveStrategy'] : NULL;
                
                foreach ($recordDataToImport as $idx => $processedRecordData) {
                    $recordToImport = $this->_createRecordToImport($processedRecordData);
                    if ($resolveStrategy !== 'discard') {
                        $importedRecord = $this->_importRecord($recordToImport, $resolveStrategy, $processedRecordData);
                    } else {
                        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
                                . ' Discarding record ' . $recordIndex);
                        
                        // just add autotags to record (if id is available)
                        if ($recordToImport->getId()) {
                            $record = call_user_func(array($this->_controller, 'get'), $recordToImport->getId());
                            $this->_addAutoTags($record);
                            call_user_func(array($this->_controller, $this->_options['updateMethod']), $record);
                        }
                    }
                }
            } catch (Exception $e) {
                $this->_handleImportException($e, $recordIndex, $recordToImport);
            }
            $recordIndex++;
        }
    }
    
    /**
     * sort client data array
     * 
     * @param array $_clientRecordData
     * @return array
     */
    protected function _sortClientRecordsByIndex($_clientRecordData)
    {
        $result = array();
        
        foreach ($_clientRecordData as $data) {
            if (isset($data['index'])) {
                $result[$data['index']] = $data;
            }
        }
        
        return $result;
    }
    
    /**
     * process raw data (mapping + conversions)
     * 
     * NOTE: returns empty Traversable if record should be skipped on purpose
     * NOTE: If there will occur any import error the client management will only work for 1 imported entry
     *       and not if multiple were stored in ArrayObject
     *
     * @param array $_data
     * @return Traversable
     * @throws Tinebase_Exception_Record_Validation on broken mapping
     */
    protected function _processRawData($_data)
    {
        $result = array();
        
        $mappedData = $this->_doMapping($_data);
        
        if (array_key_exists("postMappingHook", $this->_options)) {
            if (isset($this->_options['postMappingHook']['path'])) {
               $mappedData = $this->_postMappingHook($mappedData);
            }
        }
        
        if (empty($mappedData) && empty($_data)) {
            Throw new Tinebase_Exception_UnexpectedValue("_processRawData got no data and could not map any.");
        }
        
        $mappedData = $mappedData instanceof ArrayObject ? $mappedData : new ArrayObject(array($mappedData), ArrayObject::STD_PROP_LIST);
        
        if (! empty($mappedData)) {
            foreach ($mappedData as $idx => $recordArray) {
                $convertedData = $this->_doConversions($recordArray);
                $mappedData[$idx] = array_merge($convertedData, $this->_addData($convertedData));
            }
            $result = $mappedData;
            
            if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ 
                . ' Merged data: ' . print_r($result, true));
        } else {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                . ' Got empty record from mapping! Was: ' . print_r($_data, TRUE));
        }
        return $result;
    }
    
    /**
     * do the mapping and replacements
     *
     * @param array $_data
     * @return array
     */
    protected function _doMapping($_data)
    {
        return $_data;
    }
    
    /**
     * Runs a user defined script
     *
     * After your data are mapped and the hook is enabled in your definition every data set will be
     *  parsed through this hook.
     * 
     * It will convert the data set to a json object and send it to the stdin of a script.
     *  The script should usualy print a json object of the extended, manipulated or corrected data set. 
     * 
     * But if you intend to split the data to two or more sets or import data from different sources
     *  you have to print a json array.
     * 
     * @param array $data
     * @return Array|ArrayObject
     */
    protected function _postMappingHook ($data)
    {
        $jsonEncodedData = Zend_Json::encode($data);
        $jsonDecodedData = null;
        
        $script = $this->_options['postMappingHook']['path'];
        //The path given in the xml is not dynamic. Therefore it must be absolute or relative to the tine20 directory.
        if ($script[0] !== DIRECTORY_SEPARATOR) {
            $script = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . $script;
        }
        if (! is_executable($script)) {
            throw new Tinebase_Exception_UnexpectedValue("Script does not exists or isn't executable. Path: " . $script);
        }
        
        $jDataToSend =  escapeshellarg($jsonEncodedData);
        $jsonReceivedData = shell_exec(escapeshellcmd($script) . " $jDataToSend");
        
        $returnJDecodedData = Zend_Json_Decoder::decode($jsonReceivedData);
        if (! $returnJDecodedData) {
            throw new Tinebase_Exception_UnexpectedValue("Something went wrong by decoding the received json data!");
        }
        
        if (strpos($jsonReceivedData, '[') === 0) {
            $return = new ArrayObject(array(), ArrayObject::STD_PROP_LIST);
            foreach ($returnJDecodedData as $key => $val)
                $return[$key] = $val;
        } else {
            $return = $returnJDecodedData;
        }
        
        return $return;
    }
    
    /**
     * do conversions (transformations, charset, replacements ...)
     *
     * @param array $_data
     * @return array
     * 
     * @todo add date and other conversions
     * @todo add generic mechanism for value pre/postfixes? (see accountLoginNamePrefix in Admin_User_Import)
     */
    protected function _doConversions($_data)
    {
        if (isset($this->_options['mapping'])) {
            $data = $this->_doMappingConversion($_data);
        } else {
            $data = $_data;
        }
        
        foreach ($data as $key => $value) { 
            $data[$key] = $this->_convertEncoding($value);
        }
        
        return $data;
    }
    
    /**
     * convert encoding
     * NOTE: always do encoding with //IGNORE as we do not know the actual encoding in some cases
     * 
     * @param string|array $_value
     * @return string|array
     */
    protected function _convertEncoding($_value)
    {
        if (empty($_value) || (! isset($this->_options['encodingTo']) || (isset($this->_options['useStreamFilter']) && $this->_options['useStreamFilter']))) {
            return $_value;
        }
        
        if (is_array($_value)) {
            $result = array();
            foreach ($_value as $singleValue) {
                $result[] = $this->_doConvert($singleValue);
            }
        } else {
            $result = $this->_doConvert($_value);
        }
        
        return $result;
    }
    
    /**
     * convert string with iconv or mb_convert_encoding
     * 
     * @param string $string
     * @return string
     */
    protected function _doConvert($string)
    {
        if ((! isset($this->_options['encoding']) || $this->_options['encoding'] === 'auto') && extension_loaded('mbstring')) {
            $encoding = mb_detect_encoding($string, array('utf-8', 'iso-8859-1', 'windows-1252', 'iso-8859-15'));
            if ($encoding !== FALSE) {
                $encodingFn = 'mb_convert_encoding';
                $result = @mb_convert_encoding($string, $this->_options['encodingTo'], $encoding);
            }
        } else if (isset($this->_options['encoding'])) {
            $encoding = $this->_options['encoding'];
            $encodingFn = 'iconv';
            $result = @iconv($encoding, $this->_options['encodingTo'] . '//TRANSLIT', $string);
        } else {
            return $string;
        }

        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ 
            . ' Encoded ' . $string . ' from ' . $encoding . ' to ' . $this->_options['encodingTo'] . ' using ' . $encodingFn . ' . => ' . $result);
        return $result;
    }
    
    /**
     * do the mapping conversions defined in field configs
     *
     * @param array $_data
     * @return array
     */
    protected function _doMappingConversion($_data)
    {
        $data = $_data;
        foreach ($this->_options['mapping']['field'] as $index => $field) {
            if (! array_key_exists('destination', $field) || $field['destination'] == '' || ! isset($_data[$field['destination']])) {
                continue;
            }
        
            $key = $field['destination'];
        
            if (isset($field['replace'])) {
                if ($field['replace'] === '\n') {
                    $data[$key] = str_replace("\\n", "\r\n", $_data[$key]);
                }
            } else if (isset($field['separator'])) {
                $data[$key] = preg_split('/\s*' . $field['separator'] . '\s*/', $_data[$key]);
            } else if (isset($field['fixed'])) {
                $data[$key] = $field['fixed'];
            } else if (isset($field['append'])) {
                $data[$key] .= $field['append'] . $_data[$key];
            } else if (isset($field['typecast'])) {
                switch ($field['typecast']) {
                    case 'int':
                    case 'integer':
                        $data[$key] = (integer) $_data[$key];
                        break; 
                    case 'string':
                        $data[$key] = (string) $_data[$key];
                        break;
                    case 'bool':
                    case 'boolean':
                        $data[$key] = (string) $_data[$key];
                        break;
                    case 'datetime':
                        if (isset($_data[$key])) {
                            $datetime = isset($field["datetime_pattern"]) ?
                                DateTime::createFromFormat($field["datetime_pattern"], $_data[$key]) :
                                new DateTime($_data[$key]);
                            
                            $data[$key] = $datetime instanceof DateTime ? $datetime->format('Y-m-d H:i:s') : null;
                        }
                        break;
                    default:
                        $data[$key] = $_data[$key];
                }
            } else {
                $data[$key] = $_data[$key];
            }
        }
        
        return $data;
    }

    /**
     * add some more values (overwrite that if you need some special/dynamic fields)
     *
     * @param  array recordData
     */
    protected function _addData()
    {
        return array();
    }
    
    /**
     * create record from record data
     * 
     * @param array $_recordData
     * @return Tinebase_Record_Abstract
     */
    protected function _createRecordToImport($_recordData)
    {
        $record = new $this->_options['model'](array(), TRUE);
        $record->setFromJsonInUsersTimezone($_recordData);
        
        return $record;
    }
    
    /**
     * import single record
     *
     * @param Tinebase_Record_Abstract $_record
     * @param string $_resolveStrategy
     * @param array $_recordData
     * @return void
     * @throws Tinebase_Exception_Record_Validation
     */
    protected function _importRecord($_record, $_resolveStrategy = NULL, $_recordData = array())
    {
        $_record->isValid(TRUE);
        
        if ($this->_options['dryrun']) {
            $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction(Tinebase_Core::getDb());
        }
        
        $this->_handleTags($_record, $_resolveStrategy);
        $importedRecord = $this->_importAndResolveConflict($_record, $_resolveStrategy);
        
        $this->_importResult['results']->addRecord($importedRecord);
        
        if ($this->_options['dryrun']) {
            Tinebase_TransactionManager::getInstance()->rollBack();
        } else if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) {
            Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Successfully imported record with id ' . $importedRecord->getId());
        }
        
        $this->_importResult['totalcount']++;
    }
    
    /**
     * handle record tags
     * 
     * @param Tinebase_Record_Abstract $_record
     * @param string $_resolveStrategy
     */
    protected function _handleTags($_record, $_resolveStrategy = NULL)
    {
        if (isset($_record->tags) && is_array($_record->tags)) {
            $_record->tags = $this->_addSharedTags($_record->tags);
        } else {
            $_record->tags = NULL;
        }
        
        if ($_resolveStrategy === NULL && ! empty($this->_options['autotags'])) {
            // only add autotags for "new" records
            $this->_addAutoTags($_record);
        }
    }
    
    /**
    * add/create shared tags if they don't exist
    *
    * @param   array $_tags array of tag strings
    * @return  Tinebase_Record_RecordSet with Tinebase_Model_Tag
    */
    protected function _addSharedTags($_tags)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' Adding tags: ' . print_r($_tags, TRUE));
    
        $result = new Tinebase_Record_RecordSet('Tinebase_Model_Tag');
        foreach ($_tags as $tagData) {
            $tagData = (is_array($tagData)) ? $tagData : array('name' => $tagData);
            $tagName = trim($tagData['name']);
    
            // only check non-empty tags
            if (empty($tagName)) {
                continue;
            }
    
            $createTag = (isset($this->_options['shared_tags']) && $this->_options['shared_tags'] == 'create');
            $tagToAdd = $this->_getSingleTag($tagName, $tagData, $createTag);
            if ($tagToAdd) {
                $result->addRecord($tagToAdd);
            }
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ 
            . ' ' . print_r($result->toArray(), TRUE));
    
        return $result;
    }
    
    /**
     * get tag / create on the fly
     * 
     * @param string $_name
     * @param array $_tagData
     * @param boolean $_create
     * @return Tinebase_Model_Tag
     */
    protected function _getSingleTag($_name, $_tagData = array(), $_create = TRUE)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__
            . ' Tag name: ' . $_name . ' / data: ' . print_r($_tagData, TRUE));
        
        $name = $_name;
        if (isset($_tagData['name'])) {
            $_tagData['name'] = $name;
        }
        
        $tag = NULL;
        
        if (isset($_tagData['id'])) {
            try {
                $tag = Tinebase_Tags::getInstance()->get($_tagData['id']);
                return $tag;
            } catch (Tinebase_Exception_NotFound $tenf) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
                    . ' Could not find tag by id: ' . $_tagData['id']);
            }
        }
        
        try {
            $tag = Tinebase_Tags::getInstance()->getTagByName($name, Tinebase_Model_TagRight::USE_RIGHT, NULL);
            return $tag;
        } catch (Tinebase_Exception_NotFound $tenf) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
                . ' Could not find tag by name: ' . $name);
        }
        
        if ($_create) {
            $tagData = (! empty($_tagData)) ? $_tagData : array(
                'name' => $name,
            );
            $tag = $this->_createTag($tagData);
        }
        
        return $tag;
    }
    
    /**
     * create new tag
     * 
     * @param array $_tagData
     * @return Tinebase_Model_Tag
     * 
     * @todo allow to set contexts / application / rights
     * @todo only ignore acl for autotags that are present in import definition
     */
    protected function _createTag($_tagData)
    {
        $description  = substr((isset($_tagData['description'])) ? $_tagData['description'] : $_tagData['name'] . ' (imported)', 0, 50);
        $type         = (isset($_tagData['type']) && ! empty($_tagData['type'])) ? $_tagData['type'] : Tinebase_Model_Tag::TYPE_SHARED;
        $color        = (isset($_tagData['color'])) ? $_tagData['color'] : '#ffffff';
        
        $newTag = new Tinebase_Model_Tag(array(
            'name'          => $_tagData['name'],
            'description'   => $description,
            'type'          => strtolower($type),
            'color'         => $color,
            'type'          => $type,
        ));
        
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
            . ' Creating new ' . $type . ' tag: ' . $_tagData['name']);
        
        $tag = Tinebase_Tags::getInstance()->createTag($newTag, TRUE);
        
        // @todo should be moved to Tinebase_Tags / always be done for all kinds of tags on create
        if ($type === Tinebase_Model_Tag::TYPE_SHARED) {
            $right = new Tinebase_Model_TagRight(array(
                'tag_id'        => $newTag->getId(),
                'account_type'  => Tinebase_Acl_Rights::ACCOUNT_TYPE_ANYONE,
                'account_id'    => 0,
                'view_right'    => TRUE,
                'use_right'     => TRUE,
            ));
            Tinebase_Tags::getInstance()->setRights($right);
            Tinebase_Tags::getInstance()->setContexts(array('any'), $newTag->getId());
        }
        
        return $tag;
    }
    
    /**
    * add auto tags from options
    *
    * @param Tinebase_Record_Abstract $_record
    */
    protected function _addAutoTags($_record)
    {
        $autotags = $this->_sanitizeAutotagsOption();
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .
            ' Trying to add ' . count($autotags) . ' autotag(s) to record.');
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . print_r($autotags, TRUE));
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . print_r($_record->toArray(), TRUE));
        
        $tags = ($_record->tags instanceof Tinebase_Record_RecordSet) ? $_record->tags : new Tinebase_Record_RecordSet('Tinebase_Model_Tag');
        foreach ($autotags as $tagData) {
            if (is_string($tagData)) {
                try {
                    $tag = Tinebase_Tags::getInstance()->get($tagData);
                } catch (Tinebase_Exception_NotFound $tenf) {
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $tenf);
                    $tag = NULL;
                }
            } else {
                $tagData = $this->_doAutoTagReplacements($tagData);
                $tag = $this->_getSingleTag($tagData['name'], $tagData);
            }
            if ($tag !== NULL) {
                $tags->addRecord($tag);
            }
        }
        $_record->tags = $tags;
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . print_r($tags->toArray(), TRUE));
    }
    
    /**
     * replace some strings in autotags (name + description)
     * 
     * @param array $_tagData
     * @return array
     */
    protected function _doAutoTagReplacements($_tagData)
    {
        $result = $_tagData;
        
        $search = array(
            '###CURRENTDATE###', 
            '###CURRENTTIME###', 
            '###USERFULLNAME###'
        );
        $now = Tinebase_DateTime::now();
        $replacements = array(
            Tinebase_Translation::dateToStringInTzAndLocaleFormat($now, NULL, NULL, 'date'),
            Tinebase_Translation::dateToStringInTzAndLocaleFormat($now, NULL, NULL, 'time'),
            Tinebase_Core::getUser()->accountDisplayName
        );
        $fields = array('name', 'description');
        
        foreach ($fields as $field) {
            if (isset($result[$field])) {
                $result[$field] = str_replace($search, $replacements, $result[$field]);
            }
        }
        
        return $result;
    }
    
    /**
     * sanitize autotag option
     * 
     * @return array
     */
    protected function _sanitizeAutotagsOption()
    {
        $autotags = (array_key_exists('tag', $this->_options['autotags']) && count($this->_options['autotags']) == 1) 
            ? $this->_options['autotags']['tag'] : $this->_options['autotags'];

        $autotags = (array_key_exists('name', $autotags)) ? array($autotags) : $autotags;
        
        if (array_key_exists('tag', $autotags)) {
            unset($autotags['tag']);
        }
        
        return $autotags;
    }
    
    /**
     * import record and resolve possible conflicts
     * 
     * supports $_resolveStrategy(s): ['mergeTheirs', ('Merge, keeping existing details')],
     *                              ['mergeMine',   ('Merge, keeping my details')],
     *                              ['keep',        ('Keep both records')]
     * 
     * @param Tinebase_Record_Abstract $record
     * @param string $resolveStrategy
     * @param Tinebase_Record_Abstract $existingRecord
     * @return Tinebase_Record_Abstract
     */
    protected function _importAndResolveConflict(Tinebase_Record_Abstract $record, $resolveStrategy = null, $existingRecord = null)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
            . ' resolveStrategy: ' . $resolveStrategy);
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__
            . ' record to import: ' . print_r($record->toArray(), TRUE));
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE) && $existingRecord) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__
            . ' existing record: ' . print_r($existingRecord->toArray(), TRUE));
        
        switch ($resolveStrategy) {
            case 'mergeTheirs':
            case 'mergeMine':
                if ($resolveStrategy === 'mergeTheirs') {
                    $record = $record->merge($existingRecord);
                } else if ($existingRecord) {
                    $record = $existingRecord->merge($record);
                }
                $record = call_user_func(array($this->_controller, $this->_options['updateMethod']), $record, FALSE);
                break;
            case 'keep':
                // do not check for duplicates (keep both)
                $record = call_user_func(array($this->_controller, $this->_options['createMethod']), $record, FALSE);
                break;
            default:
                $record = call_user_func(array($this->_controller, $this->_options['createMethod']), $record);
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' ' . print_r($record->toArray(), TRUE));
        
        return $record;
    }
    
    /**
     * handle import exceptions
     * 
     * @param Exception $e
     * @param integer $recordIndex
     * @param Tinebase_Record_Abstract|array $record
     * @param boolean $allowToResolveDuplicates
     * 
     * @todo use json converter for client record
     */
    protected function _handleImportException(Exception $e, $recordIndex, $record = null, $allowToResolveDuplicates = true)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' ' . $e->getMessage());
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' ' . $e->getTraceAsString());
        
        if ($e instanceof Tinebase_Exception_Duplicate) {
            $exception = $this->_handleDuplicateExceptions($e, $recordIndex, $record, $allowToResolveDuplicates);
        } else {
            $this->_importResult['failcount']++;
            $exception = array(
                'code'         => $e->getCode(),
                'message'      => $e->getMessage(),
                'clientRecord' => ($record !== NULL && $record instanceof Tinebase_Record_Abstract) ? $record->toArray() 
                    : (is_array($record) ? $record : array()),
            );
        }
        
        if ($exception) {
            $this->_importResult['exceptions']->addRecord(new Tinebase_Model_ImportException(array(
                'code'          => $e->getCode(),
                'message'       => $e->getMessage(),
                'exception'     => $exception,
                'index'         => $recordIndex,
            )));
        }
    }
    
    /**
     * handle duplicate exceptions
     * 
     * @param Tinebase_Exception_Duplicate $ted
     * @param integer $recordIndex
     * @param Tinebase_Record_Abstract|array $record
     * @param boolean $allowToResolveDuplicates
     * @return array|null exception
     */
    protected function _handleDuplicateExceptions(Tinebase_Exception_Duplicate $ted, $recordIndex, $record = null, $allowToResolveDuplicates = true)
    {
        if (! empty($this->_options['duplicateResolveStrategy']) && $allowToResolveDuplicates) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                . ' Trying to resolve with configured strategy: ' . $this->_options['duplicateResolveStrategy']);
            
            try {
                $updatedRecord = $this->_importAndResolveConflict($ted->getData()->getFirstRecord(), $this->_options['duplicateResolveStrategy'], $ted->getClientRecord());
                $this->_importResult['updatecount']++;
                $this->_importResult['results']->addRecord($updatedRecord);
            } catch (Exception $newException) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                    . " Resolving failed. Don't try to resolve duplicates this time");
                
                $this->_handleImportException($newException, $recordIndex, $record, false);
            }
            $result = null;
        } else {
            $this->_importResult['duplicatecount']++;
            $result = $ted->toArray();
        }
        
        return $result;
    }
    
    /**
     * log import result
     */
    protected function _logImportResult()
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
            . ' Import finished. (total: ' . $this->_importResult['totalcount'] 
            . ' fail: ' . $this->_importResult['failcount'] 
            . ' duplicates: ' . $this->_importResult['duplicatecount'] 
            . ' updates: ' . $this->_importResult['updatecount'] 
            . ')');
    }
    
    /**
     * returns config from definition
     * 
     * @param Tinebase_Model_ImportExportDefinition $_definition
     * @param array                                 $_options
     * @return array
     */
    public static function getOptionsArrayFromDefinition($_definition, $_options)
    {
        $options = Tinebase_ImportExportDefinition::getOptionsAsZendConfigXml($_definition, $_options);
        $optionsArray = $options->toArray();
        if (! isset($optionsArray['model'])) {
            $optionsArray['model'] = $_definition->model;
        }
        
        return $optionsArray;
    }
    
    /**
     * set controller
     */
    protected function _setController()
    {
        $this->_controller = Tinebase_Core::getApplicationInstance($this->_options['model']);
    }
}
