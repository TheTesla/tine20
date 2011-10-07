<?php
/**
 * Tine 2.0 - http://www.tine20.org
 * 
 * @package     Calendar
 * @license     http://www.gnu.org/licenses/agpl.html
 * @copyright   Copyright (c) 2011-2011 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 */

/**
 * Test helper
 */
require_once dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR . 'TestHelper.php';

if (!defined('PHPUnit_MAIN_METHOD')) {
    define('PHPUnit_MAIN_METHOD', 'Calendar_Frontend_WebDAV_EventTest::main');
}

/**
 * Test class for Calendar_Frontend_WebDAV_Event
 */
class Calendar_Frontend_WebDAV_EventTest extends PHPUnit_Framework_TestCase
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
		$suite  = new PHPUnit_Framework_TestSuite('Tine 2.0 Calendar WebDAV Event Tests');
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
        $this->objects['initialContainer'] = Tinebase_Container::getInstance()->addContainer(new Tinebase_Model_Container(array(
            'name'              => Tinebase_Record_Abstract::generateUID(),
            'type'              => Tinebase_Model_Container::TYPE_PERSONAL,
            'backend'           => 'Sql',
            'application_id'    => Tinebase_Application::getInstance()->getApplicationByName('Calendar')->getId(),
        )));
        
        $this->objects['containerToDelete'][] = $this->objects['initialContainer'];
        
        $this->objects['eventsToDelete'] = array();
    }

    /**
     * Tears down the fixture
     * This method is called after a test is executed.
     *
     * @access protected
     */
    protected function tearDown()
    {
        foreach ($this->objects['eventsToDelete'] as $event) {
            $event->delete();
        }
        
        foreach ($this->objects['containerToDelete'] as $containerId) {
            $containerId = $containerId instanceof Tinebase_Model_Container ? $containerId->getId() : $containerId;
            
            try {
                Tinebase_Container::getInstance()->deleteContainer($containerId);
            } catch (Tinebase_Exception_NotFound $tenf) {
                // do nothing
            }
        }
    }
    
    /**
     * test create contact
     * 
     * @return Calendar_Frontend_WebDAV_Event
     */
    public function testCreate()
    {
        $vcalendarStream = fopen(dirname(__FILE__) . '/../../Import/files/lightning.ics', 'r');
        
        $event = Calendar_Frontend_WebDAV_Event::create($this->objects['initialContainer'], $vcalendarStream);
        
        $this->objects['eventsToDelete'][] = $event;
        
        $record = $event->getRecord();

        $this->assertEquals('New Event', $record->summary);
        
        return $event;
    }    
    
    /**
     * test get vcard
     */
    public function testGet()
    {
        $event = $this->testCreate();
        
        $vcalendar = stream_get_contents($event->get());
        
        $this->assertContains('SUMMARY:New Event', $vcalendar);
    }

    /**
     * test updating existing contact
     */
    public function testPut()
    {
        $event = $this->testCreate();
        
        $vcalendarStream = fopen(dirname(__FILE__) . '/../../Import/files/lightning.ics', 'r');
        
        $event->put($vcalendarStream);
        
        $record = $event->getRecord();
        
        $this->assertEquals('New Event', $record->summary);
    }
    
    /**
     * test get name of vcard
     */
    public function testGetName()
    {
        $event = $this->testCreate();
        
        $record = $event->getRecord();
        
        $this->assertEquals($event->getName(), $record->getId() . '.ics');
    }
}
