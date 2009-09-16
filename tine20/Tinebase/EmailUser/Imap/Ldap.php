<?php
/**
 * Tine 2.0
 * 
 * @package     Tinebase
 * @subpackage  User
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Philipp Schuele <p.schuele@metaways.de>
 * @version     $Id: Ldap.php 10296 2009-09-02 14:12:35Z p.schuele@metaways.de $
 * 
 * @todo        how to get emailGID / dbmailGID?
 * @todo        add Tinebase_EmailUser_Smtp_Ldap with forward / alias
 * @todo        add other schemas (qmail, ...)?
 */

/**
 * class Tinebase_EmailUser
 * 
 * Email User Settings Managing for dbmail (+ ...) attributes in ldap backend
 * 
 * @package Tinebase
 * @subpackage Ldap
 */
class Tinebase_EmailUser_Imap_Ldap extends Tinebase_EmailUser_Abstract
{

    /**
     * @var Tinebase_Ldap
     */
    protected $_ldap = NULL;

    /**
     * user properties mapping 
     * -> we need to use lowercase for ldap fields because ldap_fetch returns lowercase keys
     *
     * @var array
     */
    protected $_userPropertyNameMapping = array(
        'emailUID'          => 'dbmailuid', 
        'emailGID'          => 'dbmailgid', 
        'emailMailQuota'    => 'mailquota',
    /*
        'emailUID'          => 'dbmailUID', 
        'emailGID'          => 'dbmailGID', 
        'emailMailQuota'    => 'mailQuota',
        */
    );
    
    /**
     * objectclasses required for users
     *
     * @var array
     */
    protected $_requiredObjectClass = array(
        'dbmailUser',
    );
    
    /**
     * ldap / email user options array
     *
     * @var array
     */
    protected $_options = array();
    
    /**
     * the constructor
     *
     */
    public function __construct()
    {
        if (Tinebase_User::getConfiguredBackend() != Tinebase_User::LDAP) {
            throw new Tinebase_Exception('No LDAP config found.');
        }

        $ldapOptions = Tinebase_User::getBackendConfiguration();
        $imapConfig = Tinebase_EmailUser::getConfig(Tinebase_Model_Config::IMAP);
        $this->_options = array_merge($ldapOptions, $imapConfig);
        
        // set emailGID
        $this->_options['emailGID'] = 1208394888;

        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Binding to ldap server ' . $ldapOptions['host']);
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . print_r($ldapOptions, TRUE));
        
        $this->_ldap = new Tinebase_Ldap($ldapOptions);
        $this->_ldap->bind();
    }
    
    /**
     * get user by id
     *
     * @param   int         $_userId
     * @return  Tinebase_Model_EmailUser user
     */
    public function getUserById($_userId) 
    {
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . 'Trying to get ldap user with id ' . $_userId);
        
        try {
            $userId = Tinebase_Model_User::convertUserIdToInt($_userId);
            $ldapData = $this->_ldap->fetch($this->_options['userDn'], $this->_options['userUUIDAttribute'] . '=' . $userId);
            $user = $this->_ldap2User($ldapData);
        } catch (Exception $e) {
            throw new Exception('User not found: ' . $e->getMessage());
        }

        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . print_r($user->toArray(), TRUE));
        
        return $user;
    }

    /**
     * adds email properties for a new user
     * 
     * @param  Tinebase_Model_FullUser $_user
     * @param  Tinebase_Model_EmailUser  $_emailUser
     * @return Tinebase_Model_EmailUser
     */
	public function addUser($_user, Tinebase_Model_EmailUser $_emailUser)
	{
	    $_emailUser->emailGID = $this->_options['emailGID'];
	    $_emailUser->emailUID = $_user->accountLoginName;
	    
        $metaData = $this->_getUserMetaData($_user);
        $ldapData = $this->_user2ldap($_emailUser);
        
        $ldapData['objectclass'] = array_unique(array_merge($metaData['objectClass'], $this->_requiredObjectClass));
                
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn']);
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $ldapData: ' . print_r($ldapData, true));
        
        $this->_ldap->update($metaData['dn'], $ldapData);
        
        return $this->getUserById($_user->getId());
	}
	
	/**
     * updates email properties for an existing user
     * 
     * @param  Tinebase_Model_FullUser $_user
     * @param  Tinebase_Model_EmailUser  $_emailUser
     * @return Tinebase_Model_EmailUser
     */
	public function updateUser($_user, Tinebase_Model_EmailUser $_emailUser)
	{
        $_emailUser->emailUID = $_user->accountLoginName;
	    
        $metaData = $this->_getUserMetaData($_user);
        $ldapData = $this->_user2ldap($_emailUser);
        
        // check if user has all required object classes.
        foreach ($this->_requiredObjectClass as $className) {
            if (! in_array($className, $metaData['objectClass'])) {
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn'] . ' had no email objectclass.');

                return $this->addUser($_user, $_emailUser);
            }
        }

        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn']);
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $ldapData: ' . print_r($ldapData, true));
        
        $this->_ldap->update($metaData['dn'], $ldapData);
        
        return $this->getUserById($_user->getId());
	}

	/**
     * delete user by id
     *
     * @param   string         $_userId
     */
    public function deleteUser($_userId)
    {
        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' This does nothing at the moment.');
    }
    
    /**
     * update/set email user password
     * 
     * @param string $_userId
     * @param string $_password
     * @return void
     */
    public function setPassword($_userId, $_password)
    {
        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' This does nothing at the moment.');
    }
	
    /**
     * get metatada of existing account
     *
     * @param  int         $_userId
     * @return string 
     */
    protected function _getUserMetaData($_userId)
    {
        $userId = Tinebase_Model_User::convertUserIdToInt($_userId);
        $result = $this->_ldap->getMetaData($this->_options['userDn'], $this->_options['userUUIDAttribute'] . '=' . $userId);
        return $result;
    }
    
    /**
     * Returns a user obj with raw data from ldap
     *
     * @param array $_userData
     * @param string $_accountClass
     * @return Tinebase_Record_Abstract
     * 
     * @todo add generic function for this in Tinebase_User_Ldap or Tinebase_Ldap?
     */
    protected function _ldap2User($_userData, $_accountClass = 'Tinebase_Model_EmailUser')
    {
        $accountArray = array();
        
        foreach ($_userData as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            $keyMapping = array_search($key, $this->_userPropertyNameMapping);
            if ($keyMapping !== FALSE) {
                switch($keyMapping) {
                    default: 
                        $accountArray[$keyMapping] = $value[0];
                        break;
                }
            }
        }
        
        $accountObject = new $_accountClass($accountArray);
        
        return $accountObject;
    }
    
    /**
     * returns array of ldap data
     *
     * @param  Tinebase_Model_EmailUser $_user
     * @return array
     * 
     * @todo add generic function for this?
     */
    protected function _user2ldap(Tinebase_Model_EmailUser $_user)
    {
        $ldapData = array();
        foreach ($_user as $key => $value) {
            $ldapProperty = array_key_exists($key, $this->_userPropertyNameMapping) ? $this->_userPropertyNameMapping[$key] : false;
            if ($ldapProperty) {
                switch ($key) {
                    default:
                        $ldapData[$ldapProperty] = $value;
                        break;
                }
            }
        }
        
        return $ldapData;
    }
}  
