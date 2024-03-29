<?php

/**
 * @file
 * Contains \Drupal\Tests\Core\Extension\ModuleHandlerTest.
 */

namespace Drupal\Tests\Core\Extension;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\Core\Extension\ModuleHandler
 * @group Extension
 */
class ModuleHandlerTest extends UnitTestCase {

  /**
   * The mocked drupal kernel.
   *
   * @var \Drupal\Core\DrupalKernelInterface
   */
  protected $kernel;

  /**
   * The mocked cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $cacheBackend;

  /**
   * The tested module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   *
   * @covers ::__construct
   */
  protected function setUp() {
    parent::setUp();

    $this->kernel = $this->getMock('Drupal\Core\DrupalKernelInterface');

    $this->cacheBackend = $this->getMock('Drupal\Core\Cache\CacheBackendInterface');
    $this->moduleHandler = new ModuleHandler($this->root, array(
      'module_handler_test' => array(
        'type' => 'module',
        'pathname' => 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml',
        'filename' => 'module_handler_test.module',
      )
    ), $this->kernel, $this->cacheBackend);
  }

  /**
   * Test loading a module.
   *
   * @covers ::load
   */
  public function testLoadModule() {
    $this->assertFalse(function_exists('module_handler_test_hook'));
    $this->assertTrue($this->moduleHandler->load('module_handler_test'));
    $this->assertTrue(function_exists('module_handler_test_hook'));

    $this->moduleHandler->addModule('module_handler_test_added', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_added');
    $this->assertFalse(function_exists('module_handler_test_added_hook'), 'Function does not exist before being loaded.');
    $this->assertTrue($this->moduleHandler->load('module_handler_test_added'));
    $this->assertTrue(function_exists('module_handler_test_added_helper'), 'Function exists after being loaded.');
    $this->assertTrue($this->moduleHandler->load('module_handler_test_added'));

    $this->assertFalse($this->moduleHandler->load('module_handler_test_dne'), 'Non-existent modules returns false.');
  }

  /**
   * Test loading all modules.
   *
   * @covers ::loadAll
   */
  public function testLoadAllModules() {
    $this->moduleHandler->addModule('module_handler_test_all1', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all1');
    $this->moduleHandler->addModule('module_handler_test_all2', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all2');
    $this->assertFalse(function_exists('module_handler_test_all1_hook'), 'Function does not exist before being loaded.');
    $this->assertFalse(function_exists('module_handler_test_all2_hook'), 'Function does not exist before being loaded.');
    $this->moduleHandler->loadAll();
    $this->assertTrue(function_exists('module_handler_test_all1_hook'), 'Function exists after being loaded.');
    $this->assertTrue(function_exists('module_handler_test_all2_hook'), 'Function exists after being loaded.');
  }

  /**
   * Test reload method.
   *
   * @covers ::reload
   */
  public function testModuleReloading() {
    $module_handler = $this->getMockBuilder('Drupal\Core\Extension\ModuleHandler')
      ->setConstructorArgs(array(
        $this->root,
        array(
          'module_handler_test' => array(
            'type' => 'module',
            'pathname' => 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml',
            'filename' => 'module_handler_test.module',
          )
        ), $this->kernel, $this->cacheBackend
      ))
      ->setMethods(array('load'))
      ->getMock();
    // First reload.
    $module_handler->expects($this->at(0))
      ->method('load')
      ->with($this->equalTo('module_handler_test'));
    // Second reload.
    $module_handler->expects($this->at(1))
      ->method('load')
      ->with($this->equalTo('module_handler_test'));
    $module_handler->expects($this->at(2))
      ->method('load')
      ->with($this->equalTo('module_handler_test_added'));
    $module_handler->reload();
    $module_handler->addModule('module_handler_test_added', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_added');
    $module_handler->reload();
  }

  /**
   * Test isLoaded accessor.
   *
   * @covers ::isLoaded
   */
  public function testIsLoaded() {
    $this->assertFalse($this->moduleHandler->isLoaded());
    $this->moduleHandler->loadAll();
    $this->assertTrue($this->moduleHandler->isLoaded());
  }

  /**
   * Confirm we get back the modules set in the constructor.
   *
   * @covers ::getModuleList
   */
  public function testGetModuleList() {
    $this->assertEquals($this->moduleHandler->getModuleList(), array(
      'module_handler_test' => new Extension($this->root, 'module', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml', 'module_handler_test.module'),
    ));
  }

  /**
   * Confirm we get back a module from the module list
   *
   * @covers ::getModule
   */
  public function testGetModuleWithExistingModule() {
    $this->assertEquals($this->moduleHandler->getModule('module_handler_test'), new Extension($this->root, 'module', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml', 'module_handler_test.module'));
  }

  /**
   * @covers ::getModule
   *
   * @expectedException \InvalidArgumentException
   */
  public function testGetModuleWithNonExistingModule() {
    $this->moduleHandler->getModule('claire_alice_watch_my_little_pony_module_that_does_not_exist');
  }

  /**
   * Ensure setting the module list replaces the module list and resets internal structures.
   *
   * @covers ::setModuleList
   */
  public function testSetModuleList() {
    $module_handler = $this->getMockBuilder('Drupal\Core\Extension\ModuleHandler')
      ->setConstructorArgs(array(
        $this->root, array(), $this->kernel, $this->cacheBackend
      ))
      ->setMethods(array('resetImplementations'))
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('resetImplementations');

    // Make sure we're starting empty.
    $this->assertEquals($module_handler->getModuleList(), array());

    // Replace the list with a prebuilt list.
    $module_handler->setModuleList($this->moduleHandler->getModuleList());

    // Ensure those changes are stored.
    $this->assertEquals($this->moduleHandler->getModuleList(), $module_handler->getModuleList());
  }

  /**
   * Test adding a module.
   *
   * @covers ::addModule
   * @covers ::add
   */
  public function testAddModule() {

    $module_handler = $this->getMockBuilder('Drupal\Core\Extension\ModuleHandler')
      ->setConstructorArgs(array(
        $this->root, array(), $this->kernel, $this->cacheBackend
      ))
      ->setMethods(array('resetImplementations'))
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('resetImplementations');

    $module_handler->addModule('module_handler_test', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test');
    $this->assertTrue($module_handler->moduleExists('module_handler_test'));
  }

  /**
   * Test adding a profile.
   *
   * @covers ::addProfile
   * @covers ::add
   */
  public function testAddProfile() {

    $module_handler = $this->getMockBuilder('Drupal\Core\Extension\ModuleHandler')
      ->setConstructorArgs(array(
        $this->root, array(), $this->kernel, $this->cacheBackend
      ))
      ->setMethods(array('resetImplementations'))
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('resetImplementations');

    // @todo this should probably fail since its a module not a profile.
    $module_handler->addProfile('module_handler_test', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test');
    $this->assertTrue($module_handler->moduleExists('module_handler_test'));
  }

  /**
   * Test module exists returns correct module status.
   *
   * @covers ::moduleExists
   */
  public function testModuleExists() {
    $this->assertTrue($this->moduleHandler->moduleExists('module_handler_test'));
    $this->assertFalse($this->moduleHandler->moduleExists('module_handler_test_added'));
  }

  /**
   * @covers ::loadAllIncludes
   */
  public function testLoadAllIncludes() {
    $this->assertTrue(true);
    $module_handler = $this->getMockBuilder('Drupal\Core\Extension\ModuleHandler')
      ->setConstructorArgs(array(
        $this->root,
        array(
          'module_handler_test' => array(
            'type' => 'module',
            'pathname' => 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml',
            'filename' => 'module_handler_test.module',
          )
        ), $this->kernel, $this->cacheBackend
      ))
      ->setMethods(array('loadInclude'))
      ->getMock();

    // Ensure we reset implementations when settings a new modules list.
    $module_handler->expects($this->once())->method('loadInclude');
    $module_handler->loadAllIncludes('hook');
  }

  /**
   * @covers ::loadInclude
   */
  public function testLoadInclude() {
    // Include exists.
    $this->assertEquals(__DIR__ . '/modules/module_handler_test/hook_include.inc', $this->moduleHandler->loadInclude('module_handler_test', 'inc', 'hook_include'));
    $this->assertTrue(function_exists('module_handler_test_hook_include'));
    // Include doesn't exist.
    $this->assertFalse($this->moduleHandler->loadInclude('module_handler_test', 'install'));
  }

  /**
   * Test invoke methods when module is enabled.
   *
   * @covers ::invoke
   */
  public function testInvokeModuleEnabled() {
    $this->assertTrue($this->moduleHandler->invoke('module_handler_test', 'hook', array(TRUE)), 'Installed module runs hook.');
    $this->assertFalse($this->moduleHandler->invoke('module_handler_test', 'hook', array(FALSE)), 'Installed module runs hook.');
    $this->assertNull($this->moduleHandler->invoke('module_handler_test_fake', 'hook', array(FALSE)), 'Installed module runs hook.');
  }

  /**
   * Test implementations methods when module is enabled.
   *
   * @covers ::implementsHook
   * @covers ::loadAllIncludes
   */
  public function testImplementsHookModuleEnabled() {
    $this->assertTrue($this->moduleHandler->implementsHook('module_handler_test', 'hook'), 'Installed module implementation found.');

    $this->moduleHandler->addModule('module_handler_test_added', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_added');
    $this->assertTrue($this->moduleHandler->implementsHook('module_handler_test_added', 'hook'), 'Runtime added module with implementation in include found.');

    $this->moduleHandler->addModule('system', 'core/modules/system');
    $this->assertFalse($this->moduleHandler->implementsHook('system', 'hook', array(TRUE)), 'Missing implementation not found.');
  }

  /**
   * Test getImplementations.
   *
   * @covers ::getImplementations
   * @covers ::getImplementationInfo
   * @covers ::buildImplementationInfo
   */
  public function testGetImplementations() {
    $this->assertEquals(array('module_handler_test'), $this->moduleHandler->getImplementations('hook'));
  }

  /**
   * Test getImplementations.
   *
   * @covers ::getImplementations
   * @covers ::getImplementationInfo
   */
  public function testCachedGetImplementations() {
    $this->cacheBackend->expects($this->exactly(1))
      ->method('get')
      ->will($this->onConsecutiveCalls(
        (object) array('data' => array('hook' => array('module_handler_test' => 'test')))
      ));

    // Ensure buildImplementationInfo doesn't get called and that we work off cached results.
    $module_handler = $this->getMockBuilder('Drupal\Core\Extension\ModuleHandler')
      ->setConstructorArgs(array(
        $this->root, array(
          'module_handler_test' => array(
            'type' => 'module',
            'pathname' => 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml',
            'filename' => 'module_handler_test.module',
          )
        ), $this->kernel, $this->cacheBackend
      ))
      ->setMethods(array('buildImplementationInfo', 'loadInclude'))
      ->getMock();

    $module_handler->expects($this->never())->method('buildImplementationInfo');
    $module_handler->expects($this->once())->method('loadInclude');
    $this->assertEquals(array('module_handler_test'), $module_handler->getImplementations('hook'));
  }

  /**
   * Test getImplementations.
   *
   * @covers ::getImplementations
   * @covers ::getImplementationInfo
   */
  public function testCachedGetImplementationsMissingMethod() {
    $this->cacheBackend->expects($this->exactly(1))
      ->method('get')
      ->will($this->onConsecutiveCalls(
        (object) array('data' => array('hook' => array(
          'module_handler_test' => array(),
          'module_handler_test_missing' => array(),
        )))
      ));

    // Ensure buildImplementationInfo doesn't get called and that we work off cached results.
    $module_handler = $this->getMockBuilder('Drupal\Core\Extension\ModuleHandler')
      ->setConstructorArgs(array(
        $this->root, array(
          'module_handler_test' => array(
            'type' => 'module',
            'pathname' => 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test/module_handler_test.info.yml',
            'filename' => 'module_handler_test.module',
          )
        ), $this->kernel, $this->cacheBackend
      ))
      ->setMethods(array('buildImplementationInfo'))
      ->getMock();

    $module_handler->expects($this->never())->method('buildImplementationInfo');
    $this->assertEquals(array('module_handler_test'), $module_handler->getImplementations('hook'));
  }

  /**
   * Test invoke all.
   *
   * @covers ::invokeAll
   */
  public function testInvokeAll() {
    $this->moduleHandler->addModule('module_handler_test_all1', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all1');
    $this->moduleHandler->addModule('module_handler_test_all2', 'core/tests/Drupal/Tests/Core/Extension/modules/module_handler_test_all2');
    $this->assertEquals(array(TRUE, TRUE, TRUE), $this->moduleHandler->invokeAll('hook', array(TRUE)));
  }

  /**
   * Test that write cache calls through to cache library correctly.
   *
   * @covers ::writeCache
   */
  public function testWriteCache() {
    $this->cacheBackend
      ->expects($this->exactly(2))
      ->method('get')
        ->will($this->returnValue(NULL));
    $this->cacheBackend
      ->expects($this->exactly(2))
      ->method('set')
        ->with($this->logicalOr('module_implements', 'hook_info'));
    $this->moduleHandler->getImplementations('hook');
    $this->moduleHandler->writeCache();
  }

  /**
   * Test hook_hook_info() fetching through getHookInfo().
   *
   * @covers ::getHookInfo
   * @covers ::buildHookInfo
   */
  public function testGetHookInfo() {

    // Set up some synthetic results.
    $this->cacheBackend
      ->expects($this->exactly(2))
      ->method('get')
      ->will($this->onConsecutiveCalls(
        NULL,
        (object) array('data' =>
          array('hook_foo' => array('group' => 'hook'))))
      );

    // Results from building from mocked environment.
    $this->assertEquals(array(
      'hook' => array('group' => 'hook'),
    ), $this->moduleHandler->getHookInfo());

    // Reset local cache so we get our synthetic result from the cache handler.
    $this->moduleHandler->resetImplementations();
    $this->assertEquals(array(
      'hook_foo' => array('group' => 'hook'),
    ), $this->moduleHandler->getHookInfo());
  }

  /**
   * Test internal implementation cache reset.
   *
   * @covers ::resetImplementations()
   */
  public function testResetImplementations() {

    // Prime caches
    $this->moduleHandler->getImplementations('hook');
    $this->moduleHandler->getHookInfo();

    // Reset all caches internal and external.
    $this->cacheBackend
      ->expects($this->once())
      ->method('delete')
      ->with('hook_info');
    $this->cacheBackend
      ->expects($this->exactly(2))
      ->method('set')
      // reset sets module_implements to array() and getHookInfo later
      // populates hook_info.
      ->with($this->logicalOr('module_implements', 'hook_info'));
    $this->moduleHandler->resetImplementations();

    // Request implementation and ensure hook_info and module_implements skip
    // local caches.
    $this->cacheBackend
      ->expects($this->exactly(2))
      ->method('get')
      ->with($this->logicalOr('module_implements', 'hook_info'));
    $this->moduleHandler->getImplementations('hook');
  }

  /**
   * @dataProvider dependencyProvider
   * @covers ::parseDependency
   */
  public function testDependencyParsing($dependency, $expected) {
    $version = ModuleHandler::parseDependency($dependency);
    $this->assertEquals($expected, $version);
  }

  /**
   * Provider for testing dependency parsing.
   */
  public function dependencyProvider() {
    return array(
      array('system', array('name' => 'system')),
      array('taxonomy', array('name' => 'taxonomy')),
      array('views', array('name' => 'views')),
      array('views_ui(8.x-1.0)', array('name' => 'views_ui', 'original_version' => ' (8.x-1.0)', 'versions' => array(array('op' => '=', 'version' => '1.0')))),
      // Not supported?.
      // array('views_ui(8.x-1.1-beta)', array('name' => 'views_ui', 'original_version' => ' (8.x-1.1-beta)', 'versions' => array(array('op' => '=', 'version' => '1.1-beta')))),
      array('views_ui(8.x-1.1-alpha12)', array('name' => 'views_ui', 'original_version' => ' (8.x-1.1-alpha12)', 'versions' => array(array('op' => '=', 'version' => '1.1-alpha12')))),
      array('views_ui(8.x-1.1-beta8)', array('name' => 'views_ui', 'original_version' => ' (8.x-1.1-beta8)', 'versions' => array(array('op' => '=', 'version' => '1.1-beta8')))),
      array('views_ui(8.x-1.1-rc11)', array('name' => 'views_ui', 'original_version' => ' (8.x-1.1-rc11)', 'versions' => array(array('op' => '=', 'version' => '1.1-rc11')))),
      array('views_ui(8.x-1.12)', array('name' => 'views_ui', 'original_version' => ' (8.x-1.12)', 'versions' => array(array('op' => '=', 'version' => '1.12')))),
      array('views_ui(8.x-1.x)', array('name' => 'views_ui', 'original_version' => ' (8.x-1.x)', 'versions' => array(array('op' => '<', 'version' => '2.x'), array('op' => '>=', 'version' => '1.x')))),
      array('views_ui( <= 8.x-1.x)', array('name' => 'views_ui', 'original_version' => ' ( <= 8.x-1.x)', 'versions' => array(array('op' => '<=', 'version' => '2.x')))),
      array('views_ui(<= 8.x-1.x)', array('name' => 'views_ui', 'original_version' => ' (<= 8.x-1.x)', 'versions' => array(array('op' => '<=', 'version' => '2.x')))),
      array('views_ui( <=8.x-1.x)', array('name' => 'views_ui', 'original_version' => ' ( <=8.x-1.x)', 'versions' => array(array('op' => '<=', 'version' => '2.x')))),
      array('views_ui(>8.x-1.x)', array('name' => 'views_ui', 'original_version' => ' (>8.x-1.x)', 'versions' => array(array('op' => '>', 'version' => '2.x')))),
    );
  }

  /**
   * @covers ::getModuleDirectories
   */
  public function testGetModuleDirectories() {
    $this->moduleHandler->setModuleList(array());
    $this->moduleHandler->addModule('module', 'place');
    $this->assertEquals(array('module' => $this->root . '/place'), $this->moduleHandler->getModuleDirectories());
  }
}
