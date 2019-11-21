<?php
/**
 * Tine 2.0
 * @package     Tinebase
 * @subpackage  Frontend
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schüle <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2008-2019 Metaways Infosystems GmbH (http://www.metaways.de)
 */

/**
 * cli server
 *
 * This class handles all requests from cli scripts
 *
 * @package     Tinebase
 * @subpackage  Frontend
 */
class Tinebase_Frontend_Cli extends Tinebase_Frontend_Cli_Abstract
{
    /**
     * the internal name of the application
     *
     * @var string
     */
    protected $_applicationName = 'Tinebase';

    /**
     * needed by demo data fns
     *
     * @var array
     */
    protected $_applicationsToWorkOn = array();

    /**
     * @param Zend_Console_Getopt $opts
     * @return boolean success
     */
    public function increaseReplicationMasterId($opts)
    {
        $this->_checkAdminRight();

        $args = $this->_parseArgs($opts, array());
        $count = isset($args['count']) ? $args['count'] : 1;

        Tinebase_Timemachine_ModificationLog::getInstance()->increaseReplicationMasterId($count);

        return true;
    }

    /**
     * @param Zend_Console_Getopt $opts
     * @return boolean success
     */
    public function readModifictionLogFromMaster($opts)
    {
        $this->_checkAdminRight();

        Tinebase_Timemachine_ModificationLog::getInstance()->readModificationLogFromMaster();

        return true;
    }

    /**
     * rebuildPaths
     *
    * @param Zend_Console_Getopt $opts
    * @return integer success
    */
    public function rebuildPaths($opts)
    {
        $this->_checkAdminRight();

        $result = Tinebase_Controller::getInstance()->rebuildPaths();

        return $result ? true : 1;
    }

    public function forceResync($_opts)
    {
        $this->_checkAdminRight();

        $args = $this->_parseArgs($_opts, array());
        $userIds = isset($args['userIds']) ? (is_array($args['userIds']) ? $args['userIds'] : [$args['userIds']])
            : [];
        $contentClasses = isset($args['contentClasses']) ? (is_array($args['contentClasses'])
            ? $args['contentClasses'] : [$args['contentClasses']]) : [];
        $apis = isset($args['apis']) ? (is_array($args['apis']) ? $args['apis'] : [$args['apis']]) : [];

        // NOTE: this needs to be adjusted in Tinebase_Controller::forceResync() too
        $allowedContentClasses = [
            Tinebase_Controller::SYNC_CLASS_CONTACTS,
            Tinebase_Controller::SYNC_CLASS_EMAIL,
            Tinebase_Controller::SYNC_CLASS_EVENTS,
            Tinebase_Controller::SYNC_CLASS_TASKS,
        ];
        $allowedApis = [
            Tinebase_Controller::SYNC_API_ACTIVESYNC,
            Tinebase_Controller::SYNC_API_DAV,
        ];

        if (empty($apis)) {
            $apis = $allowedApis;
        } else {
            $apis = array_intersect($allowedApis, $apis);
        }

        if (empty($contentClasses)) {
            $contentClasses = $allowedContentClasses;
        } else {
            $contentClasses = array_intersect($allowedContentClasses, $contentClasses);
        }

        $msg = 'forcing resync for APIs: ' . join($apis, ', ') . ' with content classes: ' .
            join($contentClasses, ', ') . (empty($userIds) ? ' for all users' : ' for users: ' . join($userIds, ', '));
        echo $msg . PHP_EOL;

        if (Tinebase_Core::isLogLevel(Zend_Log::INFO))
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' ' . $msg);

        Tinebase_Controller::getInstance()->forceResync($contentClasses, $userIds, $apis);
    }

    /**
     * forces containers that support sync token to resync via WebDAV sync tokens
     *
     * this will DELETE the complete content history for the affected containers
     * this will increate the sequence for all records in all affected containers
     * this will increate the sequence of all affected containers
     *
     * this will cause 2 BadRequest responses to sync token requests
     * the first one as soon as the client notices that something changed and sends a sync token request
     * eventually the client receives a false sync token (as we increased content sequence, but we dont have a content history entry)
     * eventually not (if something really changed in the calendar in the meantime)
     *
     * in case the client got a fake sync token, the clients next sync token request (once something really changed) will fail again
     * after something really changed valid sync tokens will be handed out again
     *
     * @param Zend_Console_Getopt $_opts
     */
    public function forceSyncTokenResync($_opts)
    {
        $this->_checkAdminRight();

        $args = $this->_parseArgs($_opts, array());

        if (isset($args['userIds'])) {
            $args['userIds'] = !is_array($args['userIds']) ? array($args['userIds']) : $args['userIds'];
            $filter = new Tinebase_Model_ContainerFilter(array(
                array('field' => 'owner_id', 'operator' => 'in', 'value' => $args['userIds'])
            ));
        } elseif (isset($args['containerIds'])) {
            if (!is_array($args['containerIds'])) {
                $args['containerIds'] = array($args['containerIds']);
            }
            $filter = new Tinebase_Model_ContainerFilter(array(
                array('field' => 'id', 'operator' => 'in', 'value' => $args['containerIds'])
            ));
        } else {
            echo 'userIds or containerIds need to be provided';
            return;
        }

        Tinebase_Container::getInstance()->forceSyncTokenResync($filter);
    }

    /**
     * clean timemachine_modlog for records that have been pruned (not deleted!)
     */
    public function cleanModlog()
    {
        $this->_checkAdminRight();

        $deleted = Tinebase_Timemachine_ModificationLog::getInstance()->clean();

        echo "\ndeleted $deleted modlogs records\n";
    }

    /**
     * clean relations, set relation to deleted if at least one of the ends has been set to deleted or pruned
     */
    public function cleanRelations()
    {
        $this->_checkAdminRight();

        $relations = Tinebase_Relations::getInstance();
        $filter = new Tinebase_Model_Filter_FilterGroup();
        $pagination = new Tinebase_Model_Pagination();
        $pagination->limit = 10000;
        $pagination->sort = 'id';

        $totalCount = 0;
        $date = Tinebase_DateTime::now()->subYear(1);

        while ( ($recordSet = $relations->search($filter, $pagination)) && $recordSet->count() > 0 ) {
            $filter = new Tinebase_Model_Filter_FilterGroup();
            $pagination->start += $pagination->limit;
            $models = array();

            foreach($recordSet as $relation) {
                $models[$relation->own_model][$relation->own_id][] = $relation->id;
                $models[$relation->related_model][$relation->related_id][] = $relation->id;
            }
            foreach ($models as $model => &$ids) {
                $doAll = false;

                try {
                    $app = Tinebase_Core::getApplicationInstance($model, '', true);
                } catch (Tinebase_Exception_NotFound $tenf) {
                    if (Tinebase_Core::isLogLevel(Zend_Log::INFO))
                        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' model: ' . $model . ' no application found for it');
                    $doAll = true;
                }
                if (!$doAll) {
                    if ($app instanceof Tinebase_Container)
                    {
                        $backend = $app;
                    } else {
                        if (!$app instanceof Tinebase_Controller_Record_Abstract) {
                            if (Tinebase_Core::isLogLevel(Zend_Log::INFO))
                                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' model: ' . $model . ' controller: ' . get_class($app) . ' not an instance of Tinebase_Controller_Record_Abstract');
                            continue;
                        }

                        $backend = $app->getBackend();
                    }
                    if (!$backend instanceof Tinebase_Backend_Interface) {
                        if (Tinebase_Core::isLogLevel(Zend_Log::INFO))
                            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' model: ' . $model . ' backend: ' . get_class($backend) . ' not an instance of Tinebase_Backend_Interface');
                        continue;
                    }
                    $record = new $model(null, true);

                    $idFilter = Tinebase_Model_Filter_FilterGroup::getFilterForModel($model, [], '', ['ignoreAcl' => true]);
                    $idFilter->addFilter(new Tinebase_Model_Filter_Id(array(
                        'field' => $record->getIdProperty(), 'operator' => 'in', 'value' => array_keys($ids)
                    )));

                    $existingIds = $backend->search($idFilter, null, true);

                    if (!is_array($existingIds)) {
                        throw new Exception('search for model: ' . $model . ' returned not an array!');
                    }
                    foreach ($existingIds as $id) {
                        unset($ids[$id]);
                    }
                }

                if ( count($ids) > 0 ) {
                    $toDelete = array();
                    foreach ($ids as $idArrays) {
                        foreach ($idArrays as $id) {
                            $toDelete[$id] = true;
                        }
                    }

                    $toDelete = array_keys($toDelete);

                    foreach($toDelete as $id) {
                        if ( $recordSet->getById($id)->creation_time && $recordSet->getById($id)->creation_time->isLater($date) ) {
                            Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' relation is about to get deleted that is younger than 1 year: ' . print_r($recordSet->getById($id)->toArray(false), true));
                        }
                    }

                    $relations->delete($toDelete);
                    $totalCount += count($toDelete);
                }
            }
        }

        $message = 'Deleted ' . $totalCount . ' relations in total';
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO))
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' ' . $message);
        echo $message . "\n";
    }

    /**
     * authentication
     *
     * @param string $_username
     * @param string $_password
     */
    public function authenticate($_username, $_password)
    {
        $authResult = Tinebase_Auth::getInstance()->authenticate($_username, $_password);
        
        if ($authResult->isValid()) {
            $accountsController = Tinebase_User::getInstance();
            try {
                $account = $accountsController->getFullUserByLoginName($authResult->getIdentity());
            } catch (Tinebase_Exception_NotFound $e) {
                echo 'account ' . $authResult->getIdentity() . ' not found in account storage'."\n";
                exit();
            }
            
            Tinebase_Core::set('currentAccount', $account);

            $ipAddress = '127.0.0.1';
            $account->setLoginTime($ipAddress);

            Tinebase_AccessLog::getInstance()->create(new Tinebase_Model_AccessLog(array(
                'sessionid'     => 'cli call',
                'login_name'    => $authResult->getIdentity(),
                'ip'            => $ipAddress,
                'li'            => Tinebase_DateTime::now()->get(Tinebase_Record_Abstract::ISO8601LONG),
                'lo'            => Tinebase_DateTime::now()->get(Tinebase_Record_Abstract::ISO8601LONG),
                'result'        => $authResult->getCode(),
                'account_id'    => Tinebase_Core::getUser()->getId(),
                'clienttype'    => 'TineCli',
            )));
            
        } else {
            echo "Wrong username and/or password.\n";
            exit();
        }
    }
    
    /**
     * handle request (call -ApplicationName-_Cli.-MethodName- or -ApplicationName-_Cli.getHelp)
     *
     * @param Zend_Console_Getopt $_opts
     * @return boolean success
     */
    public function handle($_opts)
    {
        list($application, $method) = explode('.', $_opts->method);
        $class = $application . '_Frontend_Cli';
        
        if (@class_exists($class)) {
            $object = new $class;
            if ($_opts->info) {
                $result = $object->getHelp();
            } else if (method_exists($object, $method)) {
                $result = call_user_func(array($object, $method), $_opts);
            } else {
                $result = FALSE;
                echo "Method $method not found.\n";
            }
        } else {
            echo "Class $class does not exist.\n";
            $result = FALSE;
        }
        
        return $result;
    }

    /**
     * trigger async events (for example via cronjob)
     *
     * @param Zend_Console_Getopt $_opts
     * @return boolean success
     */
    public function triggerAsyncEvents($_opts)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
            . ' Triggering async events from CLI.');

        if (Tinebase_Core::inMaintenanceModeAll()) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' .
                __LINE__ . ' maintenance mode prevents trigger async events.');
            return false;
        }

        $userController = Tinebase_User::getInstance();

        try {
            $cronuser = $userController->getFullUserByLoginName($_opts->username);
        } catch (Tinebase_Exception_NotFound $tenf) {
            $cronuser = $this->_getCronuserFromConfigOrCreateOnTheFly();
        }
        Tinebase_Core::set(Tinebase_Core::USER, $cronuser);
        
        $scheduler = Tinebase_Core::getScheduler();
        $result = $scheduler->run();
        
        return $result;
    }

    /**
     * process given queue job
     *  --jobId the queue job id to execute
     *
     * @param Zend_Console_Getopt $_opts
     * @return bool success
     * @throws Tinebase_Exception_InvalidArgument
     */
    public function executeQueueJob($_opts)
    {
        try {
            $cronuser = Tinebase_User::getInstance()->getFullUserByLoginName($_opts->username);
        } catch (Tinebase_Exception_NotFound $tenf) {
            $cronuser = $this->_getCronuserFromConfigOrCreateOnTheFly();
        }
        
        Tinebase_Core::set(Tinebase_Core::USER, $cronuser);
        
        $args = $_opts->getRemainingArgs();
        $jobId = preg_replace('/^jobId=/', '', $args[0]);
        
        if (! $jobId) {
            throw new Tinebase_Exception_InvalidArgument('mandatory parameter "jobId" is missing');
        }

        if (isset($args[1]) && $args[1] === 'longRunning=true') {
            $actionQueue = Tinebase_ActionQueueLongRun::getInstance();
        } else {
            $actionQueue = Tinebase_ActionQueue::getInstance();
        }
        $job = $actionQueue->receive($jobId);

        if (isset($job['account_id'])) {
            Tinebase_Core::set(Tinebase_Core::USER, Tinebase_User::getInstance()->getFullUserById($job['account_id']));
        }

        $result = $actionQueue->executeAction($job);
        
        return false !== $result;
    }
    
    /**
     * clear table as defined in arguments
     * can clear the following tables:
     * - credential_cache
     * - access_log
     * - async_job
     * - temp_files
     * 
     * if param date is given (date=2010-09-17), all records before this date are deleted (if the table has a date field)
     * 
     * @param $_opts
     * @return boolean success
     */
    public function clearTable(Zend_Console_Getopt $_opts)
    {
        $this->_checkAdminRight();
        
        $args = $this->_parseArgs($_opts, array('tables'), 'tables');
        $dateString = (isset($args['date']) || array_key_exists('date', $args)) ? $args['date'] : NULL;

        $db = Tinebase_Core::getDb();
        foreach ((array)$args['tables'] as $table) {
            switch ($table) {
                case 'access_log':
                    $date = ($dateString) ? new Tinebase_DateTime($dateString) : NULL;
                    Tinebase_AccessLog::getInstance()->clearTable($date);
                    break;
                case 'async_job':
                    echo 'async_job has been dropped, no need to clear it anymore' . PHP_EOL;
                    break;
                case 'credential_cache':
                    Tinebase_Auth_CredentialCache::getInstance()->clearCacheTable($dateString);
                    break;
                case 'temp_files':
                    Tinebase_TempFile::getInstance()->clearTableAndTempdir($dateString);
                    break;
                default:
                    echo 'Table ' . $table . " not supported or argument missing.\n";
            }
            echo "\nCleared table $table.";
        }
        echo "\n\n";
        
        return TRUE;
    }
    
    /**
     * purge deleted records
     * 
     * if param date is given (for example: date=2010-09-17), all records before this date are deleted (if the table has a date field)
     * if table names are given, purge only records from this tables
     * 
     * @param $_opts
     * @return boolean success
     *
     * TODO move purge logic to applications, purge Tinebase tables at the end
     */
    public function purgeDeletedRecords(Zend_Console_Getopt $_opts)
    {
        $this->_checkAdminRight();

        $args = $this->_parseArgs($_opts, array(), 'tables');
        $doEverything = false;

        if (! (isset($args['tables']) || array_key_exists('tables', $args)) || empty($args['tables'])) {
            echo "No tables given.\nPurging records from all tables!\n";
            $args['tables'] = $this->_getAllApplicationTables();
            $doEverything = true;
        }
        
        $db = Tinebase_Core::getDb();
        
        if ((isset($args['date']) || array_key_exists('date', $args))) {
            echo "\nRemoving all deleted entries before {$args['date']} ...";
            $where = array(
                $db->quoteInto($db->quoteIdentifier('deleted_time') . ' < ?', $args['date'])
            );
        } else {
            echo "\nRemoving all deleted entries ...";
            $where = array();
        }
        $where[] = $db->quoteInto($db->quoteIdentifier('is_deleted') . ' = ?', 1);

        $orderedTables = $this->_orderTables($args['tables']);
        $this->_purgeTables($orderedTables, $where);

        if ($doEverything) {
            echo "\nCleaning relations...";
            $this->cleanRelations();

            echo "\nCleaning modlog...";
            $this->cleanModlog();

            echo "\nCleaning customfields...";
            $this->cleanCustomfields();

            echo "\nCleaning notes...";
            $this->cleanNotes($_opts);
        }

        echo "\n\n";
        
        return TRUE;
    }

    /**
     * cleanNotes: removes notes of records that have been deleted
     */
    public function cleanNotes(Zend_Console_Getopt $_opts)
    {
        $this->_checkAdminRight();

        $args = $this->_parseArgs($_opts, array(), 'cleanNotesOffset');

        $notesController = Tinebase_Notes::getInstance();
        $limit = 1000;
        $offset = (isset($args['cleanNotesOffset']) ? $args['cleanNotesOffset'] : 0);
        $controllers = array();
        $models = array();
        $deleteIds = array();
        $deletedCount = 0;

        do {
            echo "\noffset $offset...";

            $notes = $notesController->getAllNotes('id ASC', $limit, $offset);
            $offset += $limit;

            /** @var Tinebase_Model_Note $note */
            foreach ($notes as $note) {
                if (!isset($controllers[$note->record_model])) {
                    if (strpos($note->record_model, 'Tinebase') === 0) {
                        continue;
                    }
                    try {
                        $controllers[$note->record_model] = Tinebase_Core::getApplicationInstance($note->record_model);
                    } catch (Tinebase_Exception_AccessDenied $e) {
                        // TODO log
                        continue;
                    } catch (Tinebase_Exception_NotFound $tenf) {
                        $deleteIds[] = $note->getId();
                        continue;
                    }
                    $oldACLCheckValue = $controllers[$note->record_model]->doContainerACLChecks(false);
                    $models[$note->record_model] = array(
                        0 => new $note->record_model(),
                        1 => ($note->record_model !== 'Filemanager_Model_Node' ? class_exists($note->record_model . 'Filter') : false),
                        2 => $note->record_model . 'Filter',
                        3 => $oldACLCheckValue
                    );
                }
                $controller = $controllers[$note->record_model];
                $model = $models[$note->record_model];

                if ($model[1]) {
                    $filter = new $model[2](array(
                        array(
                            'field' => $model[0]->getIdProperty(),
                            'operator' => 'equals',
                            'value' => $note->record_id
                        )
                    ));
                    if ($model[0]->has('is_deleted')) {
                        $filter->addFilter(new Tinebase_Model_Filter_Int(array(
                            'field' => 'is_deleted',
                            'operator' => 'notnull',
                            'value' => null
                        )));
                    }
                    $result = $controller->searchCount($filter);

                    if (is_bool($result) || (is_string($result) && $result === ((string)intval($result)))) {
                        $result = (int)$result;
                    }

                    if (!is_int($result)) {
                        if (is_array($result) && isset($result['totalcount'])) {
                            $result = (int)$result['totalcount'];
                        } elseif (is_array($result) && isset($result['count'])) {
                            $result = (int)$result['count'];
                        } else {
                            // todo log
                            // dummy line, remove!
                            $result = 1;
                        }
                    }

                    if ($result === 0) {
                        $deleteIds[] = $note->getId();
                    }
                } else {
                    try {
                        $controller->get($note->record_id, null, false, true);
                    } catch (Tinebase_Exception_NotFound $tenf) {
                        $deleteIds[] = $note->getId();
                    }
                }
            }
            if (count($deleteIds) > 0) {
                $deletedCount += count($deleteIds);
                $offset -= $notesController->purgeNotes($deleteIds);
                if ($offset < 0) $offset = 0;
                $deleteIds = [];
            }
            echo ' done';
        } while ($notes->count() === $limit);



        foreach($controllers as $model => $controller) {
            $controller->doContainerACLChecks($models[$model][3]);
        }

        echo "\ndeleted " . $deletedCount . " notes\n";
    }

    /**
     * cleanCustomfields
     */
    public function cleanCustomfields()
    {
        $this->_checkAdminRight();

        $customFieldController = Tinebase_CustomField::getInstance();
        $customFieldConfigs = $customFieldController->searchConfig();
        $deleteCount = 0;

        /** @var Tinebase_Model_CustomField_Config $customFieldConfig */
        foreach($customFieldConfigs as $customFieldConfig) {
            $deleteAll = false;
            try {
                $controller = Tinebase_Core::getApplicationInstance($customFieldConfig->model);

                $oldACLCheckValue = $controller->doContainerACLChecks(false);
                if ($customFieldConfig->model !== 'Filemanager_Model_Node') {
                    $filterClass = $customFieldConfig->model . 'Filter';
                } else {
                    $filterClass = 'ClassThatDoesNotExist';
                }
            } catch(Tinebase_Exception_AccessDenied $e) {
                // TODO log
                continue;
            } catch(Tinebase_Exception_NotFound $tenf) {
                $deleteAll = true;
            }



            $filter = new Tinebase_Model_CustomField_ValueFilter(array(
                array('field' => 'customfield_id', 'operator' => 'equals', 'value' => $customFieldConfig->id)
            ));
            $customFieldValues = $customFieldController->search($filter);
            $deleteIds = array();

            if (true === $deleteAll) {
                $deleteIds = $customFieldValues->getId();
            } elseif (class_exists($filterClass)) {
                $model = new $customFieldConfig->model();
                /** @var Tinebase_Model_CustomField_Value $customFieldValue */
                foreach ($customFieldValues as $customFieldValue) {
                    $filter = new $filterClass(array(
                        array('field' => $model->getIdProperty(), 'operator' => 'equals', 'value' => $customFieldValue->record_id)
                    ));
                    if ($model->has('is_deleted')) {
                        $filter->addFilter(new Tinebase_Model_Filter_Int(array('field' => 'is_deleted', 'operator' => 'notnull', 'value' => NULL)));
                    }

                    $result = $controller->searchCount($filter);

                    if (is_bool($result) || (is_string($result) && $result === ((string)intval($result)))) {
                        $result = (int)$result;
                    }

                    if (!is_int($result)) {
                        if (is_array($result) && isset($result['totalcount'])) {
                            $result = (int)$result['totalcount'];
                        } elseif(is_array($result) && isset($result['count'])) {
                            $result = (int)$result['count'];
                        } else {
                            // todo log
                            // dummy line, remove!
                            $result = 1;
                        }
                    }

                    if ($result === 0) {
                        $deleteIds[] = $customFieldValue->getId();
                    }
                }
            } else {
                /** @var Tinebase_Model_CustomField_Value $customFieldValue */
                foreach ($customFieldValues as $customFieldValue) {
                    try {
                        $controller->get($customFieldValue->record_id, null, false, true);
                    } catch(Tinebase_Exception_NotFound $tenf) {
                        $deleteIds[] = $customFieldValue->getId();
                    }
                }
            }

            if (count($deleteIds) > 0) {
                $customFieldController->deleteCustomFieldValue($deleteIds);
                $deleteCount += count($deleteIds);
            }

            if (true !== $deleteAll) {
                $controller->doContainerACLChecks($oldACLCheckValue);
            }
        }

        echo "\ndeleted " . $deleteCount . " customfield values\n";
    }
    
    /**
     * get all app tables
     * 
     * @return array
     */
    protected function _getAllApplicationTables()
    {
        $result = array();
        
        $enabledApplications = Tinebase_Application::getInstance()->getApplicationsByState(Tinebase_Application::ENABLED);
        foreach ($enabledApplications as $application) {
            $result = array_merge($result, Tinebase_Application::getInstance()->getApplicationTables($application));
        }
        
        return $result;
    }

    /**
     * order tables for purging deleted records in a defined order
     *
     * @param array $tables
     * @return array
     *
     * TODO could be improved by using usort
     */
    protected function _orderTables($tables)
    {
        // tags should be deleted first
        // containers should be deleted last

        $orderedTables = array();
        $lastTables = array();
        foreach($tables as $table) {
            switch ($table) {
                case 'container':
                    $lastTables[] = $table;
                    break;
                case Timetracker_Model_Timeaccount::TABLE_NAME:
                    array_unshift($lastTables, $table);
                    break;
                case 'tags':
                    array_unshift($orderedTables, $table);
                    break;
                case 'cal_attendee':
                    array_unshift($orderedTables, $table);
                    break;
                default:
                    $orderedTables[] = $table;
            }
        }
        $orderedTables = array_merge($orderedTables, $lastTables);

        return $orderedTables;
    }

    /**
     * purge tables
     *
     * @param $orderedTables
     * @param $where
     */
    protected function _purgeTables($orderedTables, $where)
    {
        foreach ($orderedTables as $table) {
            try {
                $schema = Tinebase_Db_Table::getTableDescriptionFromCache(SQL_TABLE_PREFIX . $table);
            } catch (Zend_Db_Statement_Exception $zdse) {
                echo "\nCould not get schema (" . $zdse->getMessage() . "). Skipping table $table";
                continue;
            }
            if (!(isset($schema['is_deleted']) || array_key_exists('is_deleted', $schema)) || !(isset($schema['deleted_time']) || array_key_exists('deleted_time', $schema))) {
                continue;
            }

            $deleteCount = 0;
            try {
                $deleteCount = Tinebase_Core::getDb()->delete(SQL_TABLE_PREFIX . $table, $where);
            } catch (Zend_Db_Statement_Exception $zdse) {
                echo "\nFailed to purge deleted records for table $table. " . $zdse->getMessage();
            }
            if ($deleteCount > 0) {
                echo "\nCleared table $table (deleted $deleteCount records).";
            }
            // TODO this should only be echoed with --verbose or written to the logs
            else {
                echo "\nNothing to purge from $table";
            }
        }
    }

    /**
     * add new customfield config
     *
     * example:
     * $ php tine20.php --method=Tinebase.addCustomfield -- \
         application="Addressbook" model="Addressbook_Model_Contact" name="datefield" \
         definition='{"label":"Date","type":"datetime", "uiconfig": {"group":"Dates", "order": 30}}'
     * @see Tinebase_Model_CustomField_Config for full list
     *
     * @param $_opts
     * @return boolean success
     */
    public function addCustomfield(Zend_Console_Getopt $_opts)
    {
        $this->_checkAdminRight();
        
        // parse args
        $args = $_opts->getRemainingArgs();
        $data = array();
        foreach ($args as $idx => $arg) {
            list($key, $value) = explode('=', $arg);
            if ($key == 'application') {
                $key = 'application_id';
                $value = Tinebase_Application::getInstance()->getApplicationByName($value)->getId();
            }
            $data[$key] = $value;
        }
        
        $customfieldConfig = new Tinebase_Model_CustomField_Config($data);
        $cf = Tinebase_CustomField::getInstance()->addCustomField($customfieldConfig);

        echo "\nCreated customfield: ";
        print_r($cf->toArray());
        echo "\n";
        
        return 0;
    }

    /**
     * set customfield acl
     *
     * example:
     * $ php tine20.php --method Tinebase.setCustomfieldAcl -- application=Addressbook \
     *   model=Addressbook_Model_Contact name=$CFNAME \
     *   grants='[{"account":"$USERNAME","account_type":"user","readGrant":1,"writeGrant":1},{"account_type":"anyone","readGrant":1}]'
     *
     * @param $_opts
     * @return integer
     * @throws Tinebase_Exception_InvalidArgument
     */
    public function setCustomfieldAcl(Zend_Console_Getopt $_opts)
    {
        $this->_checkAdminRight();

        // parse args
        $args = $_opts->getRemainingArgs();
        $data = array();
        foreach ($args as $idx => $arg) {
            list($key, $value) = explode('=', $arg);
            if ($key == 'application') {
                $key = 'application_id';
                $value = Tinebase_Application::getInstance()->getApplicationByName($value)->getId();
            }
            $data[$key] = $value;
        }

        if (! isset($data['grants']) || ! isset($data['name'])) {
            throw new Tinebase_Exception_InvalidArgument('grants and name params are required');
        }

        $cf = Tinebase_CustomField::getInstance()->getCustomFieldByNameAndApplication(
            $data['application_id'],
            $data['name']);

        if (! $cf) {
            throw new Tinebase_Exception_InvalidArgument('customfield not found');
        }

        $grantsArray = Tinebase_Helper::jsonDecode($data['grants']);
        $removeOldGrants = true;
        foreach ($grantsArray as $grant) {
            $accountType = isset($grant['account_type']) ? $grant['account_type'] : null;
            if (isset($grant['account'])) {
                if ($accountType === Tinebase_Acl_Rights::ACCOUNT_TYPE_GROUP) {
                    $group = Tinebase_Group::getInstance()->getGroupByName($grant['account']);
                    $accountId = $group->getId();
                } else {
                    $user = Tinebase_User::getInstance()->getFullUserByLoginName($grant['account']);
                    $accountId = $user->getId();
                    $accountType === Tinebase_Acl_Rights::ACCOUNT_TYPE_USER;
                }
            } else {
                $accountId = isset($grant['account_id']) ? $grant['account_id'] : null;
            }
            $grants = [];
            $allGrants = Tinebase_Model_CustomField_Grant::getAllGrants();
            foreach ($grant as $key => $value) {
                if (in_array($key, $allGrants) && $value) {
                    $grants[] = $key;
                }
            }
            Tinebase_CustomField::getInstance()->setGrants($cf->getId(), $grants, $accountType, $accountId, $removeOldGrants);
            // prevent overwrite
            $removeOldGrants = false;
        }

        return 0;
    }

    /**
     * nagios monitoring for tine 2.0 database connection
     * 
     * @return integer
     * @see http://nagiosplug.sourceforge.net/developer-guidelines.html#PLUGOUTPUT
     */
    public function monitoringCheckDB()
    {
        $result = 0;
        $message = 'DB CONNECTION FAIL';
        try {
            if (! Setup_Core::isRegistered(Setup_Core::CONFIG)) {
                Setup_Core::setupConfig();
            }
            if (! Setup_Core::isRegistered(Setup_Core::LOGGER)) {
                Setup_Core::setupLogger();
            }
            $time_start = microtime(true);
            $dbcheck = Setup_Core::setupDatabaseConnection();
            $time = (microtime(true) - $time_start) * 1000;
        } catch (Exception $e) {
            $message .= ': ' . $e->getMessage();
            $dbcheck = FALSE;
        }
        
        if ($dbcheck) {
            $message = "DB CONNECTION OK | connecttime={$time}ms;;;;";
        } else {
            $result = 2;
        }
        
        echo $message . "\n";
        $this->_logMonitoringResult($result, $message);

        return $result;
    }
    
    /**
     * nagios monitoring for tine 2.0 config file
     * 
     * @return integer
     * @see http://nagiosplug.sourceforge.net/developer-guidelines.html#PLUGOUTPUT
     */
    public function monitoringCheckConfig()
    {
        $message = 'CONFIG FAIL';
        $configcheck = FALSE;
        $result = 0;
        
        $configfile = Setup_Core::getConfigFilePath();
        if ($configfile) {
            $configfile = escapeshellcmd($configfile);
            if (preg_match('/^win/i', PHP_OS)) {
                exec("php -l $configfile 2> NUL", $error, $code);
            } else {
                exec("php -l $configfile 2> /dev/null", $error, $code);
            }
            if ($code == 0) {
                $configcheck = TRUE;
            } else {
                $message .= ': CONFIG FILE SYNTAX ERROR';
            }
        } else {
            $message .= ': CONFIG FILE MISSING';
        }
        
        if ($configcheck) {
            $message = "CONFIG FILE OK";
        } else {
            $result = 2;
        }

        echo $message . "\n";
        $this->_logMonitoringResult($result, $message);

        return $result;
    }
    
    /**
    * nagios monitoring for tine 2.0 async cronjob run
    *
    * @return integer
    * 
    * @see http://nagiosplug.sourceforge.net/developer-guidelines.html#PLUGOUTPUT
    * @see 0008038: monitoringCheckCron -> check if cron did run in the last hour
    */
    public function monitoringCheckCron()
    {
        $message = 'CRON FAIL';

        try {
            $lastJob = Tinebase_Scheduler::getInstance()->getLastRun();
            
            if ($lastJob === NULL || ! $lastJob->last_run instanceof Tinebase_DateTime) {
                $message .= ': NO LAST JOB FOUND';
                $result = 1;
            } else {
                $valueString = ' | duration=' . $lastJob->last_duration . 's;;;;';
                $valueString .= ' end=' . $lastJob->last_run->getClone()->addSecond($lastJob->last_duration)->getIso() . ';;;;';
                
                if ($lastJob->server_time->isLater($lastJob->last_run->getClone()->addHour(1))) {
                    $message .= ': NO JOB IN THE LAST HOUR';
                    $result = 1;
                } else {
                    $message = 'CRON OK';
                    $result = 0;
                }
                $message .= $valueString;
            }
        } catch (Exception $e) {
            $message .= ': ' . $e->getMessage();
            $result = 2;
        }

        $this->_logMonitoringResult($result, $message);
        echo $message . "\n";
        return $result;
    }

    protected function _logMonitoringResult($result, $message)
    {
        if ($result > 0) {
            try {
                Tinebase_Exception::log(new Tinebase_Exception($message));
            } catch (Throwable $t) {
                // just logging
            }
        }
    }
    
    /**
     * nagios monitoring for tine 2.0 logins during the last 5 mins
     * 
     * @return number
     * 
     * @todo allow to configure timeslot
     */
    public function monitoringLoginNumber()
    {
        $message = 'LOGINS';
        $result  = 0;
        
        try {
            $filter = new Tinebase_Model_AccessLogFilter(array(
                array('field' => 'li', 'operator' => 'after', 'value' => Tinebase_DateTime::now()->subMinute(5))
            ));
            $accesslogs = Tinebase_AccessLog::getInstance()->search($filter, NULL, FALSE, TRUE);
            $valueString = ' | count=' . count($accesslogs) . ';;;;';
            $message .= ' OK' . $valueString;
        } catch (Exception $e) {
            $message .= ' FAIL: ' . $e->getMessage();
            $result = 2;
        }

        $this->_logMonitoringResult($result, $message);
        
        echo $message . "\n";
        return $result;
    }

    /**
     * nagios monitoring for tine 2.0 active users
     *
     * @return number
     *
     * @todo allow to configure timeslot / currently the active users of the last month are returned
     */
    public function monitoringActiveUsers()
    {
        $message = 'ACTIVE USERS';
        $result  = 0;

        try {
            $userCount = Tinebase_User::getInstance()->getActiveUserCount();
            $valueString = ' | count=' . $userCount . ';;;;';
            $message .= ' OK' . $valueString;
        } catch (Exception $e) {
            $message .= ' FAIL: ' . $e->getMessage();
            $result = 2;
        }

        $this->_logMonitoringResult($result, $message);

        echo $message . "\n";
        return $result;
    }

    /**
     * nagios monitoring for tine 2.0 action queue
     *
     * @return integer
     *
     * @see http://nagiosplug.sourceforge.net/developer-guidelines.html#PLUGOUTPUT
     */
    public function monitoringCheckQueue()
    {
        $result = 0;
        $queueConfig = Tinebase_Config::getInstance()->get(Tinebase_Config::ACTIONQUEUE);
        if (! $queueConfig->{Tinebase_Config::ACTIONQUEUE_ACTIVE}) {
            $message = 'QUEUE INACTIVE';
        } else {
            $actionQueue = Tinebase_ActionQueue::getInstance();
            if (! $actionQueue->hasAsyncBackend()) {
                $message = 'QUEUE INACTIVE';
            } else {
                try {
                    if (null === ($lastDuration = Tinebase_Application::getInstance()->getApplicationState('Tinebase',
                            Tinebase_Application::STATE_ACTION_QUEUE_LAST_DURATION))) {
                        throw new Tinebase_Exception('state ' . Tinebase_Application::STATE_ACTION_QUEUE_LAST_DURATION .
                            ' not set');
                    }
                    if (null === ($lastDurationUpdate = Tinebase_Application::getInstance()->getApplicationState('Tinebase',
                            Tinebase_Application::STATE_ACTION_QUEUE_LAST_DURATION_UPDATE))) {
                        throw new Tinebase_Exception('state ' .
                            Tinebase_Application::STATE_ACTION_QUEUE_LAST_DURATION_UPDATE . ' not set');
                    }
                    $lastDuration = floatval($lastDuration);
                    $lastDurationUpdate = intval($lastDurationUpdate);

                    $now = time();
                    $diff = 0;
                    $warn = null;
                    if (false !== ($currentJobId = $actionQueue->peekJobId())) {
                        if ($currentJobId === ($lastJobId = Tinebase_Application::getInstance()->getApplicationState(
                                'Tinebase', Tinebase_Application::STATE_ACTION_QUEUE_LAST_JOB_ID))) {
                            if (null === ($lastChange = Tinebase_Application::getInstance()->getApplicationState('Tinebase',
                                    Tinebase_Application::STATE_ACTION_QUEUE_LAST_JOB_CHANGE))) {
                                throw new Tinebase_Exception('state ' .
                                    Tinebase_Application::STATE_ACTION_QUEUE_LAST_JOB_CHANGE . ' not set');
                            }
                            if (($diff = $now - intval($lastChange)) > (15 * 60)) {
                                throw new Tinebase_Exception('last job id change > ' . (15 * 60) . ' sec - ' . $diff);
                            }

                        } else {
                            Tinebase_Application::getInstance()->setApplicationState('Tinebase',
                                Tinebase_Application::STATE_ACTION_QUEUE_LAST_JOB_CHANGE, (string)$now);
                            Tinebase_Application::getInstance()->setApplicationState('Tinebase',
                                Tinebase_Application::STATE_ACTION_QUEUE_LAST_JOB_ID, $currentJobId);
                        }
                    } else {
                        Tinebase_Application::getInstance()->setApplicationState('Tinebase',
                            Tinebase_Application::STATE_ACTION_QUEUE_LAST_JOB_ID, '');
                    }

                    if ($lastDuration > $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_DURATION_CRIT}) {
                        throw new Tinebase_Exception('last duration > '
                            . $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_DURATION_CRIT} . ' sec - ' . $lastDuration);
                    }
                    if ($now - $lastDurationUpdate > $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_LASTUPDATE_CRIT}) {
                        throw new Tinebase_Exception('last duration update > '
                            . $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_LASTUPDATE_CRIT} . ' sec - ' . ($now - $lastDurationUpdate));
                    }

                    if ($diff > $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_DURATION_WARN} && null === $warn) {
                        $warn = 'last job id change > '
                            . $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_DURATION_WARN} . ' sec - ' . $diff;
                    }

                    if ($lastDuration > $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_DURATION_WARN} && null === $warn) {
                        $warn = 'last duration > '
                            . $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_DURATION_WARN} . ' sec - ' . $lastDuration;
                    }

                    if ($now - $lastDurationUpdate > $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_LASTUPDATE_WARN}
                        && null === $warn
                    ) {
                        $warn = 'last duration update > '
                            . $queueConfig->{Tinebase_Config::ACTIONQUEUE_MONITORING_LASTUPDATE_WARN} . ' sec - '
                            . ($now - $lastDurationUpdate);
                    }


                    if (null !== $warn) {
                        $message = 'QUEUE WARN: ' . $warn;
                        $result = 1;
                    } else {
                        $message = 'QUEUE OK';
                    }
                    $queueSize = $actionQueue->getQueueSize();
                    $message .= ' | size=' . $queueSize . ';lastJobId=' . $diff . ';lastDuration=' . $lastDuration .
                        ';lastDurationUpdate=' . ($now - $lastDurationUpdate) . ';';
                } catch (Exception $e) {
                    $message = 'QUEUE FAIL: ' . get_class($e) . ' - ' . $e->getMessage();
                    $result = 2;
                }

                $this->_logMonitoringResult($result, $message);
            }
        }

        echo $message . "\n";
        return $result;
    }

    /**
     * nagios monitoring for tine 2.0 cache
     *
     * @return integer
     *
     * @see http://nagiosplug.sourceforge.net/developer-guidelines.html#PLUGOUTPUT
     */
    public function monitoringCheckCache()
    {
        $result = 0;
        $cacheConfig = Tinebase_Config::getInstance()->get(Tinebase_Config::CACHE);
        $active = ($cacheConfig && $cacheConfig->active);
        if (! $active) {
            $message = 'CACHE INACTIVE';
        } else {
            try {
                $cache = Tinebase_Core::getCache();

                // TODO support size / see https://redis.io/commands/dbsize
                //$cacheSize = $cache->getSize();
                $cacheSize = 'unknown';
                // TODO add cache access time?

                // write, read and delete to test cache
                $cacheId = Tinebase_Helper::convertCacheId(__METHOD__);
                $cache->save(true, $cacheId);
                $value = $cache->load($cacheId);
                $cache->remove($cacheId);

                if ($value) {
                    $message = 'CACHE OK | size=' . $cacheSize . ';;;;';
                } else {
                    $message = 'CACHE FAIL: loading value failed';
                    $result = 1;
                }
            } catch (Exception $e) {
                $message = 'CACHE FAIL: ' . $e->getMessage();
                $result = 2;
            }

            $this->_logMonitoringResult($result, $message);
        }
        echo $message . "\n";
        return $result;
    }

    /**
     * undo changes to records defined by certain criteria (user, date, fields, ...)
     * 
     * example: $ php tine20.php --username pschuele --method Tinebase.undo -d 
     *   -- record_type=Addressbook_Model_Contact modification_time=2013-05-08 modification_account=3263
     * 
     * @param Zend_Console_Getopt $opts
     * @return integer
     */
    public function undo(Zend_Console_Getopt $opts)
    {
        $this->_checkAdminRight();
        
        $data = $this->_parseArgs($opts, array('modification_time'));
        
        // build filter from params
        $filterData = array();
        $allowedFilters = array(
            'record_type',
            'modification_time',
            'modification_account',
            'record_id',
            'client',
        );
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFilters)) {
                $operator = ($key === 'modification_time') ? 'within' : 'equals';
                $filterData[] = array('field' => $key, 'operator' => $operator, 'value' => $value);
            }
        }
        $filter = new Tinebase_Model_ModificationLogFilter($filterData);
        
        $dryrun = $opts->d;
        $overwrite = (isset($data['overwrite']) && $data['overwrite']) ? TRUE : FALSE;
        $result = Tinebase_Timemachine_ModificationLog::getInstance()->undo($filter, $overwrite, $dryrun, (isset($data['modified_attribute'])?$data['modified_attribute']:null));
        
        if (! $dryrun) {
            $this->clearCache();
            echo 'Reverted ' . $result['totalcount'] . " change(s)\n";
        } else {
            echo "Dry run\n";
            echo 'Would revert ' . $result['totalcount'] . " change(s):\n";
            foreach ($result['undoneModlogs'] as $modlog) {
                $modifiedAttribute = $modlog->modified_attribute;
                if (!empty($modifiedAttribute)) {
                    echo 'id ' . $modlog->record_id . ' [' . $modifiedAttribute . ']: ' . $modlog->new_value . ' -> ' . $modlog->old_value . PHP_EOL;
                } else {
                    if ($modlog->change_type === Tinebase_Timemachine_ModificationLog::CREATED) {
                        echo 'id ' . $modlog->record_id . ' DELETE' . PHP_EOL;
                    } elseif ($modlog->change_type === Tinebase_Timemachine_ModificationLog::DELETED) {
                        echo 'id ' . $modlog->record_id . ' UNDELETE' . PHP_EOL;
                    } else {
                        $diff = new Tinebase_Record_Diff(json_decode($modlog->new_value));
                        foreach($diff->diff as $key => $val) {
                            echo 'id ' . $modlog->record_id . ' [' . $key . ']: ' . $val . ' -> ' . $diff->oldData[$key] . PHP_EOL;
                        }
                    }
                }
            }
        }
        echo 'Failcount: ' . $result['failcount'] . "\n";
        return 0;
    }
    
    /**
     * creates demo data for all applications
     * accepts same arguments as Tinebase_Frontend_Cli_Abstract::createDemoData
     * and the additional argument "skipAdmin" to force no user/group/role creation
     * 
     * @param Zend_Console_Getopt $_opts
     */
    public function createAllDemoData($_opts)
    {
        $this->_checkAdminRight();
        
        // fetch all applications and check if required are installed, otherwise remove app from array
        $applications = Tinebase_Application::getInstance()->getApplicationsByState(Tinebase_Application::ENABLED)->name;
        foreach($applications as $appName) {
            echo 'Searching for DemoData in application "' . $appName . '"...' . PHP_EOL;
            $className = $appName.'_Setup_DemoData';
            if (class_exists($className)) {
                echo 'DemoData in application "' . $appName . '" found!' . PHP_EOL;
                $required = $className::getRequiredApplications();
                foreach($required as $requiredApplication) {
                    if (! Tinebase_Helper::in_array_case($applications, $requiredApplication)) {
                        echo 'Creating DemoData for Application ' . $appName . ' is impossible, because application "' . $requiredApplication . '" is not installed.' . PHP_EOL;
                        continue 2;
                    }
                }
                $this->_applicationsToWorkOn[$appName] = array('appName' => $appName, 'required' => $required);
            } else {
                echo 'DemoData in application "' . $appName . '" not found.' . PHP_EOL . PHP_EOL;
            }
        }
        unset($applications);
        
        foreach($this->_applicationsToWorkOn as $app => $cfg) {
            $this->_createDemoDataRecursive($app, $cfg, $_opts);
        }

        return 0;
    }
    
    /**
     * creates demo data and calls itself if there are required apps
     * 
     * @param string $app
     * @param array $cfg
     * @param Zend_Console_Getopt $opts
     */
    protected function _createDemoDataRecursive($app, $cfg, $opts)
    {
        if (isset($cfg['required']) && is_array($cfg['required'])) {
            foreach($cfg['required'] as $requiredApp) {
                $this->_createDemoDataRecursive($requiredApp, $this->_applicationsToWorkOn[$requiredApp], $opts);
            }
        }
        
        $className = $app . '_Frontend_Cli';
        
        $classNameDD = $app . '_Setup_DemoData';
        
        if (class_exists($className)) {
            if (! $classNameDD::hasBeenRun()) {
                echo 'Creating DemoData in application "' . $app . '"...' . PHP_EOL;
                $class = new $className();
                $class->createDemoData($opts, FALSE);
            } else {
                echo 'DemoData for ' . $app . ' has been run already, skipping...' . PHP_EOL;
            }
        } else {
            echo 'Could not found ' . $className . ', so DemoData for application "' . $app . '" could not be created!';
        }
    }
    
    /**
     * clears deleted files from filesystem
     *
     * @return int
     */
    public function clearDeletedFiles()
    {
        $this->_checkAdminRight();
        
        $this->_addOutputLogWriter();
        
        Tinebase_FileSystem::getInstance()->clearDeletedFiles();

        return 0;
    }

    /**
     * clears deleted files from the database, use -- d=false or -- d=0 to turn off dryRun. Default is -- d=true
     *
     * @param Zend_Console_Getopt $opts
     * @return int
     */
    public function clearDeletedFilesFromDatabase(Zend_Console_Getopt $opts)
    {
        $this->_checkAdminRight();

        $this->_addOutputLogWriter();

        $data = $this->_parseArgs($opts);
        if (isset($data['d']) && ($data['d'] === 'false' || $data['d'] === '0')) {
            $dryrun = false;
        } else {
            $dryrun = true;
        }

        echo PHP_EOL . ($dryrun ? 'would delete ' : 'deleted ') . Tinebase_FileSystem::getInstance()
                ->clearDeletedFilesFromDatabase((bool)$dryrun) . ' hashes from the database' . PHP_EOL;

        return 0;
    }

    /**
     * recalculates the revision sizes and then the folder sizes
     *
     * @return int
     */
    public function fileSystemSizeRecalculation()
    {
        $this->_checkAdminRight();

        Tinebase_FileSystem::getInstance()->recalculateRevisionSize();

        Tinebase_FileSystem::getInstance()->recalculateFolderSize();

        return 0;
    }

    /**
     * checks if there are not yet indexed file objects and adds them to the index synchronously
     * that means this can be very time consuming
     *
     * @return int
     */
    public function fileSystemCheckIndexing()
    {
        $this->_checkAdminRight();

        Tinebase_FileSystem::getInstance()->checkIndexing();

        return 0;
    }

    /**
     * checks if there are files missing previews and creates them synchronously
     * that means this can be very time consuming
     * also deletes previews of files that no longer exist
     *
     * @return int
     */
    public function fileSystemCheckPreviews()
    {
        $this->_checkAdminRight();

        Tinebase_FileSystem::getInstance()->sanitizePreviews();

        return 0;
    }

    /**
     * recreates all previews
     *
     * @return int
     */
    public function fileSystemRecreateAllPreviews()
    {
        $this->_checkAdminRight();

        // TODO reset preview_error_count
        Tinebase_FileSystem_Previews::getInstance()->deleteAllPreviews();
        Tinebase_FileSystem::getInstance()->sanitizePreviews();

        return 0;
    }

    /**
     * repair a table
     * 
     * @param Zend_Console_Getopt $opts
     * 
     * @todo add more tables
     */
    public function repairTable($opts)
    {
        $this->_checkAdminRight();
        
        $this->_addOutputLogWriter();
        
        $data = $this->_parseArgs($opts, array('table'));
        
        switch ($data['table']) {
            case 'importexport_definition':
                Tinebase_ImportExportDefinition::getInstance()->repairTable();
                $result = 0;
                break;
            default:
                if (Tinebase_Core::isLogLevel(Zend_Log::NOTICE)) Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__
                    . ' No repair script found for ' . $data['table']);
                $result = 1;
        }
        
        exit($result);
    }

    /**
     * transfer relations
     * 
     * @param Zend_Console_Getopt $opts
     */
    public function transferRelations($opts)
    {
        $this->_checkAdminRight();
        
        $this->_addOutputLogWriter();
        
        try {
            $args = $this->_parseArgs($opts, array('oldId', 'newId', 'model'));
        } catch (Tinebase_Exception_InvalidArgument $e) {
            if (Tinebase_Core::isLogLevel(Zend_Log::NOTICE)) {
                Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . ' Parameters "oldId", "newId" and "model" are required!');
            }
            exit(1);
        }
        
        $skippedEntries = Tinebase_Relations::getInstance()->transferRelations($args['oldId'], $args['newId'], $args['model']);

        if (! empty($skippedEntries) && Tinebase_Core::isLogLevel(Zend_Log::NOTICE)) {
            Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . ' ' . count($skippedEntries) . ' entries has been skipped:');
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) {
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' The operation has been terminated successfully.');
        }

        return 0;
    }

    /**
     * repair function for persistent filters (favorites) without grants: this adds default grants for those filters.
     *
     * @return int
     */
    public function setDefaultGrantsOfPersistentFilters()
    {
        $this->_checkAdminRight();

        $this->_addOutputLogWriter(6);

        // get all persistent filters without grants
        // TODO this could be enhanced by allowing to set default grants for other filters, too
        Tinebase_PersistentFilter::getInstance()->doContainerACLChecks(false);
        $filters = Tinebase_PersistentFilter::getInstance()->search(new Tinebase_Model_PersistentFilterFilter(array(),'', array('ignoreAcl' => true)));
        $filtersWithoutGrants = 0;

        foreach ($filters as $filter) {
            if (count($filter->grants) == 0) {
                // update to set default grants
                $filter = Tinebase_PersistentFilter::getInstance()->update($filter);
                $filtersWithoutGrants++;

                if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) {
                    Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__
                        . ' Updated filter: ' . print_r($filter->toArray(), true));
                }
            }
        }

        if (Tinebase_Core::isLogLevel(Zend_Log::NOTICE)) {
            Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__
                . ' Set default grants for ' . $filtersWithoutGrants . ' filters'
                . ' (checked ' . count($filters) . ' in total).');
        }

        return 0;
    }

    /**
     *
     *
     * @return int
     */
    public function repairContainerOwner()
    {
        $this->_checkAdminRight();

        $this->_addOutputLogWriter(6);
        Tinebase_Container::getInstance()->setContainerOwners();

        return 0;
    }

    /**
     * show user report (number of enabled, disabled, ... users)
     *
     * TODO add system user count
     * TODO use twig?
     */
    public function userReport()
    {
        $this->_checkAdminRight();

        $translation = Tinebase_Translation::getTranslation('Tinebase');

        $userStatus = array(
            'total' => array(),
            Tinebase_Model_User::ACCOUNT_STATUS_ENABLED => array(/* 'showUserNames' => true, 'showClients' => true */),
            Tinebase_Model_User::ACCOUNT_STATUS_DISABLED => array(),
            Tinebase_Model_User::ACCOUNT_STATUS_BLOCKED => array(),
            Tinebase_Model_User::ACCOUNT_STATUS_EXPIRED => array(),
            //'system' => array(),
            'lastmonth' => array('lastMonths' => 1, 'showUserNames' => true, 'showClients' => true),
            'last 3 months' => array('lastMonths' => 3),
        );

        foreach ($userStatus as $status => $options) {
            switch ($status) {
                case 'lastmonth':
                case 'last 3 months':
                    $userCount = Tinebase_User::getInstance()->getActiveUserCount($options['lastMonths']);
                    $text = $translation->_("Number of distinct users") . " (" . $status . "): " . $userCount . "\n";
                    break;
                case 'system':
                    $text = "TODO add me\n";
                    break;
                default:
                    $userCount = Tinebase_User::getInstance()->getUserCount($status);
                    $text = $translation->_("Number of users") . " (" . $status . "): " . $userCount . "\n";
            }
            echo $text;

            if (isset($options['showUserNames']) && $options['showUserNames']
                && in_array($status, array('lastmonth', 'last 3 months'))
                && isset($options['lastMonths'])
            ) {
                // TODO allow this for other status
                echo $translation->_("  User Accounts:\n");
                $userIds = Tinebase_User::getInstance()->getActiveUserIds($options['lastMonths']);
                foreach ($userIds as $userId) {
                    $user = Tinebase_User::getInstance()->getUserByProperty('accountId', $userId, 'Tinebase_Model_FullUser');
                    echo "  * " . $user->accountLoginName . ' / ' . $user->accountDisplayName . "\n";
                    if (isset($options['showClients']) && $options['showClients']) {
                        $userClients = Tinebase_AccessLog::getInstance()->getUserClients($user, $options['lastMonths']);
                        echo "    Clients: \n";
                        foreach ($userClients as $client) {
                            echo "     - $client\n";
                        }
                        echo "\n";
                    }
                }
            }
            echo "\n";
        }

        return 0;
    }

    public function cleanFileObjects()
    {
        $this->_checkAdminRight();

        Tinebase_FileSystem::getInstance()->clearFileObjects();
    }

    public function clearCache()
    {
        $this->_checkAdminRight();

        Tinebase_Core::getCache()->clean(Zend_Cache::CLEANING_MODE_ALL);
    }

    public function cleanAclTables()
    {
        $this->_checkAdminRight();

        Tinebase_Controller::getInstance()->cleanAclTables();
    }

    public function waitForActionQueueToEmpty()
    {
        $actionQueue = Tinebase_ActionQueue::getInstance();
        if (!$actionQueue->hasAsyncBackend()) {
            return 0;
        }

        $startTime = time();
        while ($actionQueue->getQueueSize() > 0 && time() - $startTime < 300) {
            usleep(1000);
        }

        return $actionQueue->getQueueSize();
    }

    /**
     * default is dryRun, to make changes use "-- dryRun=[0|false]
     * @param Zend_Console_Getopt $opts
     * @return int
     */
    public function sanitizeGroupListSync(Zend_Console_Getopt $opts)
    {
        $this->_checkAdminRight();

        $data = $this->_parseArgs($opts);
        if (isset($data['dryRun']) && ($data['dryRun'] === '0' || $data['dryRun'] === 'false')) {
            $dryRun = false;
        } else {
            $dryRun = true;
        }

        Tinebase_Group::getInstance()->sanitizeGroupListSync($dryRun);

        return 0;
    }

    /**
     * re-adds all scheduler tasks (if they are missing)
     *
     * @param Zend_Console_Getopt $opts
     * @return int
     */
    public function resetSchedulerTasks(Zend_Console_Getopt $opts)
    {
        $this->_checkAdminRight();

        Tinebase_Setup_Initialize::addSchedulerTasks();

        return 0;
    }

    /**
     * @param Zend_Console_Getopt $opts
     * @return int
     * @throws Tinebase_Exception_InvalidArgument
     */
    public function reportPreviewStatus(Zend_Console_Getopt $opts)
    {
        $this->_checkAdminRight();

        print_r(Tinebase_FileSystem::getInstance()->reportPreviewStatus());

        return 0;
    }

    /**
     * @param Zend_Console_Getopt $opts
     * @return int
     */
    public function reReplicateContainer(Zend_Console_Getopt $opts)
    {
        $this->_checkAdminRight();

        $data = $this->_parseArgs($opts);
        if (!isset($data['container'])) {
            echo 'usage: --reReplicateContainer -- container={containerId}' . PHP_EOL;
            return 1;
        }

        $db = Tinebase_Core::getDb();
        $transId = Tinebase_TransactionManager::getInstance()->startTransaction($db);

        /** @var Tinebase_Model_Container $container */
        $container = Tinebase_Container::getInstance()->get($data['container']);
        $container->application_id;
        $container->model;

        $filter = new Tinebase_Model_ContainerContentFilter([
            ['field' => 'container_id', 'operator' => 'equals',  'value' => $container->getId()],
        ]);
        $result = array_keys(Tinebase_Container::getInstance()->getContentBackend()
            ->search($filter, null, ['record_id']));

        if (count($result) > 0) {
            $db->query('SELECT @i := (SELECT MAX(instance_seq) FROM ' . SQL_TABLE_PREFIX . 'timemachine_modlog)');

            $db->query('UPDATE ' . SQL_TABLE_PREFIX . 'timemachine_modlog SET instance_seq = @i:=@i+1, instance_id = "'
                . Tinebase_Core::getTinebaseId() . '" WHERE record_type = "' . $container->model .
                '" AND application_id = "' . $container->application_id . '" AND record_id IN ("' .
                join('","', $result) . '") ORDER BY instance_seq ASC');

            $autoInc = $db->query('SELECT @i:=@i+1')->fetchColumn();

            $db->query('ALTER TABLE ' . SQL_TABLE_PREFIX . 'timemachine_modlog AUTO_INCREMENT ' . $autoInc);
        }

        Tinebase_TransactionManager::getInstance()->commitTransaction($transId);

        return 0;
    }

    public function testNotification()
    {
        $this->_checkAdminRight();

        $recipient = Addressbook_Controller_Contact::getInstance()->getContactByUserId(Tinebase_Core::getUser()->getId());
        $messageSubject = 'Tine 2.0 test notification';
        $messageBody = 'Tine 2.0 test notification has been sent successfully';
        Tinebase_Notification::getInstance()->send(null, array($recipient), $messageSubject, $messageBody);
        return 0;
    }

    /**
     * Delete duplicate personal container without content.
     *
     * e.g. php tine20.php --method=Tinebase.duplicatePersonalContainerCheck app=Addressbook [-d]
     *
     * @param $opts
     * @throws Tinebase_Exception_AccessDenied
     * @throws Tinebase_Exception_InvalidArgument
     * @throws Tinebase_Exception_NotFound
     * @throws Tinebase_Exception_Record_SystemContainer
     */
    public function duplicatePersonalContainerCheck($opts)
    {
        $this->_checkAdminRight();
        $args = $this->_parseArgs($opts, array('app'));

        $removeCount = Tinebase_Container::getInstance()->deleteDuplicateContainer($args['app'], $opts->d);
        if ($opts->d) {
            echo "Would remove " . $removeCount . " duplicates\n";
        } else {
            echo $removeCount . " duplicates removed\n";
        }
    }

    public function repairTreeIsDeletedState($opts)
    {
        $this->_checkAdminRight();
        Tinebase_FileSystem::getInstance()->repairTreeIsDeletedState();
    }

    /**
     * activate saving of email user id in xprops and converts existing accounts
     */
    public function emailUserIdInXprops()
    {
        $this->_checkAdminRight();

        // convert fmail accounts
        if (Tinebase_Application::getInstance()->isInstalled('Felamimail')) {
            Felamimail_Controller_Account::getInstance()->convertAccountsToSaveUserIdInXprops();
        }

        // TODO convert users
        // TODO convert lists

        // TODO activate config
    }
}
