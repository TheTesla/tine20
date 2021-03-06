<?php
/**
 * Tine 2.0 - http://www.tine20.org
 * 
 * @package     Tinebase
 * @subpackage  Acl
 * @license     http://www.gnu.org/licenses/agpl.html
 * @copyright   Copyright (c) 2008 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Philipp Schuele <p.schuele@metaways.de>
 * 
 */

/**
 * Test helper
 */
require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'TestHelper.php';

/**
 * Test class for Tinebase_Acl_Roles
 */
class Tinebase_Acl_RightsTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var array test objects
     */
    protected $objects = array();
    
    /**
     * Runs the test methods of this class.
     *
     * @access public
     * @static
     */
    public static function main()
    {
        $suite  = new PHPUnit_Framework_TestSuite('Tinebase_Acl_RightsTest');
        PHPUnit_TextUI_TestRunner::run($suite);
    }

    /**
     * Sets up the fixture.
     * This method is called before a test is executed.
     *
     * @access protected
     */
    protected function setUp()
    {
        return;
    }

    /**
     * Tears down the fixture
     * This method is called after a test is executed.
     *
     * @access protected
     */
    protected function tearDown()
    {
    }

    /**
     * try to check getting application rights
     *
     */   
    public function testGetAllApplicationRights()
    {
        $rights = Tinebase_Acl_Rights::getInstance()->getAllApplicationRights('Tinebase');
        
        //print_r($rights);
        
        $this->assertGreaterThan(0, count($rights));
    } 
    
    /**
     * try to check getting application rights
     *
     */   
    public function testGetTranslatedRightDescriptions()
    {
        $all = Admin_Acl_Rights::getTranslatedRightDescriptions();
        $text = $all[Admin_Acl_Rights::ADMIN];
        
        $this->assertNotEquals('', $text['text']);
        $this->assertNotEquals('', $text['description']);
        $this->assertNotEquals(Admin_Acl_Rights::ADMIN . ' right', $text['description']);
    } 
}
