<?php

/**
 * @file
 * Contains \Drupal\simpletest\KernelTestBase.
 */

namespace Drupal\simpletest;

use Drupal\Component\Utility\String;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Language\Language;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\Parameter;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base class for integration tests.
 *
 * Tests extending this base class can access files and the database, but the
 * entire environment is initially empty. Drupal runs in a minimal mocked
 * environment, comparable to the one in the early installer.
 *
 * The module/hook system is functional and operates on a fixed module list.
 * Additional modules needed in a test may be loaded and added to the fixed
 * module list.
 *
 * @see \Drupal\simpletest\KernelTestBase::$modules
 * @see \Drupal\simpletest\KernelTestBase::enableModules()
 *
 * @ingroup testing
 */
abstract class KernelTestBase extends TestBase {

  use AssertContentTrait;

  /**
   * Modules to enable.
   *
   * Test classes extending this class, and any classes in the hierarchy up to
   * this class, may specify individual lists of modules to enable by setting
   * this property. The values of all properties in all classes in the hierarchy
   * are merged.
   *
   * Any modules specified in the $modules property are automatically loaded and
   * set as the fixed module list.
   *
   * Unlike WebTestBase::setUp(), the specified modules are loaded only, but not
   * automatically installed. Modules need to be installed manually, if needed.
   *
   * @see \Drupal\simpletest\KernelTestBase::enableModules()
   * @see \Drupal\simpletest\KernelTestBase::setUp()
   *
   * @var array
   */
  public static $modules = array();

  private $moduleFiles;
  private $themeFiles;

  /**
   * The configuration directories for this test run.
   *
   * @var array
   */
  protected $configDirectories = array();

  /**
   * A KeyValueMemoryFactory instance to use when building the container.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueMemoryFactory.
   */
  protected $keyValueFactory;

  /**
   * Array of registered stream wrappers.
   *
   * @var array
   */
  protected $streamWrappers = array();

  /**
   * {@inheritdoc}
   */
  function __construct($test_id = NULL) {
    parent::__construct($test_id);
    $this->skipClasses[__CLASS__] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function beforePrepareEnvironment() {
    // Copy/prime extension file lists once to avoid filesystem scans.
    if (!isset($this->moduleFiles)) {
      $this->moduleFiles = \Drupal::state()->get('system.module.files') ?: array();
      $this->themeFiles = \Drupal::state()->get('system.theme.files') ?: array();
    }
  }

  /**
   * Create and set new configuration directories.
   *
   * @see config_get_config_directory()
   *
   * @throws \RuntimeException
   *   Thrown when CONFIG_ACTIVE_DIRECTORY or CONFIG_STAGING_DIRECTORY cannot
   *   be created or made writable.
   */
  protected function prepareConfigDirectories() {
    $this->configDirectories = array();
    include_once DRUPAL_ROOT . '/core/includes/install.inc';
    foreach (array(CONFIG_ACTIVE_DIRECTORY, CONFIG_STAGING_DIRECTORY) as $type) {
      // Assign the relative path to the global variable.
      $path = $this->siteDirectory . '/config_' . $type;
      $GLOBALS['config_directories'][$type] = $path;
      // Ensure the directory can be created and is writeable.
      if (!install_ensure_config_directory($type)) {
        throw new \RuntimeException("Failed to create '$type' config directory $path");
      }
      // Provide the already resolved path for tests.
      $this->configDirectories[$type] = $path;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    $this->keyValueFactory = new KeyValueMemoryFactory();

    // Allow for test-specific overrides.
    $settings_services_file = DRUPAL_ROOT . '/' . $this->originalSite . '/testing.services.yml';
    if (file_exists($settings_services_file)) {
      // Copy the testing-specific service overrides in place.
      copy($settings_services_file, DRUPAL_ROOT . '/' . $this->siteDirectory . '/services.yml');
    }

    // Create and set new configuration directories.
    $this->prepareConfigDirectories();

    // Add this test class as a service provider.
    // @todo Remove the indirection; implement ServiceProviderInterface instead.
    $GLOBALS['conf']['container_service_providers']['TestServiceProvider'] = 'Drupal\simpletest\TestServiceProvider';

    // Back up settings from TestBase::prepareEnvironment().
    $settings = Settings::getAll();
    // Bootstrap a new kernel. Don't use createFromRequest so we don't mess with settings.
    $class_loader = require DRUPAL_ROOT . '/core/vendor/autoload.php';
    $this->kernel = new DrupalKernel('testing', $class_loader, FALSE);
    $request = Request::create('/');
    $this->kernel->setSitePath(DrupalKernel::findSitePath($request));
    $this->kernel->boot();

    // Restore and merge settings.
    // DrupalKernel::boot() initializes new Settings, and the containerBuild()
    // method sets additional settings.
    new Settings($settings + Settings::getAll());

    // Set the request scope.
    $this->container = $this->kernel->getContainer();
    $this->container->get('request_stack')->push($request);

    // Re-inject extension file listings into state, unless the key/value
    // service was overridden (in which case its storage does not exist yet).
    if ($this->container->get('keyvalue') instanceof KeyValueMemoryFactory) {
      $this->container->get('state')->set('system.module.files', $this->moduleFiles);
      $this->container->get('state')->set('system.theme.files', $this->themeFiles);
    }

    // Create a minimal core.extension configuration object so that the list of
    // enabled modules can be maintained allowing
    // \Drupal\Core\Config\ConfigInstaller::installDefaultConfig() to work.
    // Write directly to active storage to avoid early instantiation of
    // the event dispatcher which can prevent modules from registering events.
    \Drupal::service('config.storage')->write('core.extension', array('module' => array(), 'theme' => array()));

    // Collect and set a fixed module list.
    $class = get_class($this);
    $modules = array();
    while ($class) {
      if (property_exists($class, 'modules')) {
        // Only add the modules, if the $modules property was not inherited.
        $rp = new \ReflectionProperty($class, 'modules');
        if ($rp->class == $class) {
          $modules[$class] = $class::$modules;
        }
      }
      $class = get_parent_class($class);
    }
    // Modules have been collected in reverse class hierarchy order; modules
    // defined by base classes should be sorted first. Then, merge the results
    // together.
    $modules = array_reverse($modules);
    $modules = call_user_func_array('array_merge_recursive', $modules);
    if ($modules) {
      $this->enableModules($modules);
    }
    // In order to use theme functions default theme config needs to exist.
    \Drupal::config('system.theme')->set('default', 'stark');

    // Tests based on this class are entitled to use Drupal's File and
    // StreamWrapper APIs.
    // @todo Move StreamWrapper management into DrupalKernel.
    // @see https://drupal.org/node/2028109
    file_prepare_directory($this->public_files_directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
    $this->settingsSet('file_public_path', $this->public_files_directory);
    $this->streamWrappers = array();
    $this->registerStreamWrapper('public', 'Drupal\Core\StreamWrapper\PublicStream');
    // The temporary stream wrapper is able to operate both with and without
    // configuration.
    $this->registerStreamWrapper('temporary', 'Drupal\Core\StreamWrapper\TemporaryStream');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    if ($this->kernel instanceof DrupalKernel) {
      $this->kernel->shutdown();
    }
    // Before tearing down the test environment, ensure that no stream wrapper
    // of this test leaks into the parent environment. Unlike all other global
    // state variables in Drupal, stream wrappers are a global state construct
    // of PHP core, which has to be maintained manually.
    // @todo Move StreamWrapper management into DrupalKernel.
    // @see https://drupal.org/node/2028109
    foreach ($this->streamWrappers as $scheme => $type) {
      $this->unregisterStreamWrapper($scheme, $type);
    }
    parent::tearDown();
  }

  /**
   * Sets up the base service container for this test.
   *
   * Extend this method in your test to register additional service overrides
   * that need to persist a DrupalKernel reboot. This method is called whenever
   * the kernel is rebuilt.
   *
   * @see \Drupal\simpletest\KernelTestBase::setUp()
   * @see \Drupal\simpletest\KernelTestBase::enableModules()
   * @see \Drupal\simpletest\KernelTestBase::disableModules()
   */
  public function containerBuild(ContainerBuilder $container) {
    // Keep the container object around for tests.
    $this->container = $container;

    // Set the default language on the minimal container.
    $this->container->setParameter('language.default_values', Language::$defaultValues);

    $container->register('lock', 'Drupal\Core\Lock\NullLockBackend');
    $container->register('cache_factory', 'Drupal\Core\Cache\MemoryBackendFactory');

    $container
      ->register('config.storage', 'Drupal\Core\Config\DatabaseStorage')
      ->addArgument(Database::getConnection())
      ->addArgument('config');

    if ($this->strictConfigSchema) {
      $container
        ->register('simpletest.config_schema_checker', 'Drupal\Core\Config\Testing\ConfigSchemaChecker')
        ->addArgument(new Reference('config.typed'))
        ->addTag('event_subscriber');
    }

    $keyvalue_options = $container->getParameter('factory.keyvalue') ?: array();
    $keyvalue_options['default'] = 'keyvalue.memory';
    $container->setParameter('factory.keyvalue', $keyvalue_options);
    $container->set('keyvalue.memory', $this->keyValueFactory);
    if (!$container->has('keyvalue')) {
      // TestBase::setUp puts a completely empty container in
      // $this->container which is somewhat the mirror of the empty
      // environment being set up. Unit tests need not to waste time with
      // getting a container set up for them. Drupal Unit Tests might just get
      // away with a simple container holding the absolute bare minimum. When
      // a kernel is overridden then there's no need to re-register the keyvalue
      // service but when a test is happy with the superminimal container put
      // together here, it still might a keyvalue storage for anything using
      // \Drupal::state() -- that's why a memory service was added in the first
      // place.
      $container->register('settings', 'Drupal\Core\Site\Settings')
        ->setFactoryClass('Drupal\Core\Site\Settings')
        ->setFactoryMethod('getInstance');

      $container
        ->register('keyvalue', 'Drupal\Core\KeyValueStore\KeyValueFactory')
        ->addArgument(new Reference('service_container'))
        ->addArgument(new Parameter('factory.keyvalue'));

      $container->register('state', 'Drupal\Core\State\State')
        ->addArgument(new Reference('keyvalue'));
    }

    if ($container->hasDefinition('path_processor_alias')) {
      // Prevent the alias-based path processor, which requires a url_alias db
      // table, from being registered to the path processor manager. We do this
      // by removing the tags that the compiler pass looks for. This means the
      // url generator can safely be used within tests.
      $definition = $container->getDefinition('path_processor_alias');
      $definition->clearTag('path_processor_inbound')->clearTag('path_processor_outbound');
    }

    if ($container->hasDefinition('password')) {
      $container->getDefinition('password')->setArguments(array(1));
    }

    // Register the stream wrapper manager.
    $container
      ->register('stream_wrapper_manager', 'Drupal\Core\StreamWrapper\StreamWrapperManager')
      ->addArgument(new Reference('module_handler'))
      ->addMethodCall('setContainer', array(new Reference('service_container')));

    $request = Request::create('/');
    $container->get('request_stack')->push($request);
  }

  /**
   * Installs default configuration for a given list of modules.
   *
   * @param array $modules
   *   A list of modules for which to install default configuration.
   *
   * @throws \RuntimeException
   *   Thrown when any module listed in $modules is not enabled.
   */
  protected function installConfig(array $modules) {
    foreach ($modules as $module) {
      if (!$this->container->get('module_handler')->moduleExists($module)) {
        throw new \RuntimeException(format_string("'@module' module is not enabled.", array(
          '@module' => $module,
        )));
      }
      \Drupal::service('config.installer')->installDefaultConfig('module', $module);
    }
    $this->pass(format_string('Installed default config: %modules.', array(
      '%modules' => implode(', ', $modules),
    )));
  }

  /**
   * Installs a specific table from a module schema definition.
   *
   * @param string $module
   *   The name of the module that defines the table's schema.
   * @param string|array $tables
   *   The name or an array of the names of the tables to install.
   *
   * @throws \RuntimeException
   *   Thrown when $module is not enabled or when the table schema cannot be
   *   found in the module specified.
   */
  protected function installSchema($module, $tables) {
    // drupal_get_schema_unprocessed() is technically able to install a schema
    // of a non-enabled module, but its ability to load the module's .install
    // file depends on many other factors. To prevent differences in test
    // behavior and non-reproducible test failures, we only allow the schema of
    // explicitly loaded/enabled modules to be installed.
    if (!$this->container->get('module_handler')->moduleExists($module)) {
      throw new \RuntimeException(format_string("'@module' module is not enabled.", array(
        '@module' => $module,
      )));
    }
    $tables = (array) $tables;
    foreach ($tables as $table) {
      $schema = drupal_get_schema_unprocessed($module, $table);
      if (empty($schema)) {
        throw new \RuntimeException(format_string("Unknown '@table' table schema in '@module' module.", array(
          '@module' => $module,
          '@table' => $table,
        )));
      }
      $this->container->get('database')->schema()->createTable($table, $schema);
    }
    // We need to refresh the schema cache, as any call to drupal_get_schema()
    // would not know of/return the schema otherwise.
    // @todo Refactor Schema API to make this obsolete.
    drupal_get_schema(NULL, TRUE);
    $this->pass(format_string('Installed %module tables: %tables.', array(
      '%tables' => '{' . implode('}, {', $tables) . '}',
      '%module' => $module,
    )));
  }



  /**
   * Installs the storage schema for a specific entity type.
   *
   * @param string $entity_type_id
   *   The ID of the entity type.
   */
  protected function installEntitySchema($entity_type_id) {
    /** @var \Drupal\Core\Entity\EntityManagerInterface $entity_manager */
    $entity_manager = $this->container->get('entity.manager');
    $entity_type = $entity_manager->getDefinition($entity_type_id);
    $entity_manager->onEntityTypeCreate($entity_type);

    // For test runs, the most common storage backend is a SQL database. For
    // this case, ensure the tables got created.
    $storage = $entity_manager->getStorage($entity_type_id);
    if ($storage instanceof SqlEntityStorageInterface) {
      $tables = $storage->getTableMapping()->getTableNames();
      $db_schema = $this->container->get('database')->schema();
      $all_tables_exist = TRUE;
      foreach ($tables as $table) {
        if (!$db_schema->tableExists($table)) {
          $this->fail(String::format('Installed entity type table for the %entity_type entity type: %table', array(
            '%entity_type' => $entity_type_id,
            '%table' => $table,
          )));
          $all_tables_exist = FALSE;
        }
      }
      if ($all_tables_exist) {
        $this->pass(String::format('Installed entity type tables for the %entity_type entity type: %tables', array(
          '%entity_type' => $entity_type_id,
          '%tables' => '{' . implode('}, {', $tables) . '}',
        )));
      }
    }
  }

  /**
   * Enables modules for this test.
   *
   * @param array $modules
   *   A list of modules to enable. Dependencies are not resolved; i.e.,
   *   multiple modules have to be specified with dependent modules first.
   *   The new modules are only added to the active module list and loaded.
   */
  protected function enableModules(array $modules) {
    // Set the list of modules in the extension handler.
    $module_handler = $this->container->get('module_handler');

    // Write directly to active storage to avoid early instantiation of
    // the event dispatcher which can prevent modules from registering events.
    $active_storage =  \Drupal::service('config.storage');
    $extensions = $active_storage->read('core.extension');

    foreach ($modules as $module) {
      $module_handler->addModule($module, drupal_get_path('module', $module));
      // Maintain the list of enabled modules in configuration.
      $extensions['module'][$module] = 0;
    }
    $active_storage->write('core.extension', $extensions);

    // Update the kernel to make their services available.
    $module_filenames = $module_handler->getModuleList();
    $this->kernel->updateModules($module_filenames, $module_filenames);

    // Ensure isLoaded() is TRUE in order to make _theme() work.
    // Note that the kernel has rebuilt the container; this $module_handler is
    // no longer the $module_handler instance from above.
    $this->container->get('module_handler')->reload();
    $this->pass(format_string('Enabled modules: %modules.', array(
      '%modules' => implode(', ', $modules),
    )));
  }

  /**
   * Disables modules for this test.
   *
   * @param array $modules
   *   A list of modules to disable. Dependencies are not resolved; i.e.,
   *   multiple modules have to be specified with dependent modules first.
   *   Code of previously active modules is still loaded. The modules are only
   *   removed from the active module list.
   */
  protected function disableModules(array $modules) {
    // Unset the list of modules in the extension handler.
    $module_handler = $this->container->get('module_handler');
    $module_filenames = $module_handler->getModuleList();
    $extension_config = $this->container->get('config.factory')->get('core.extension');
    foreach ($modules as $module) {
      unset($module_filenames[$module]);
      $extension_config->clear('module.' . $module);
    }
    $extension_config->save();
    $module_handler->setModuleList($module_filenames);
    $module_handler->resetImplementations();
    // Update the kernel to remove their services.
    $this->kernel->updateModules($module_filenames, $module_filenames);

    // Ensure isLoaded() is TRUE in order to make _theme() work.
    // Note that the kernel has rebuilt the container; this $module_handler is
    // no longer the $module_handler instance from above.
    $module_handler = $this->container->get('module_handler');
    $module_handler->reload();
    $this->pass(format_string('Disabled modules: %modules.', array(
      '%modules' => implode(', ', $modules),
    )));
  }

  /**
   * Registers a stream wrapper for this test.
   *
   * @param string $scheme
   *   The scheme to register.
   * @param string $class
   *   The fully qualified class name to register.
   * @param int $type
   *   The Drupal Stream Wrapper API type. Defaults to
   *   StreamWrapperInterface::NORMAL.
   */
  protected function registerStreamWrapper($scheme, $class, $type = StreamWrapperInterface::NORMAL) {
    $this->container->get('stream_wrapper_manager')->registerWrapper($scheme, $class, $type);
  }

  /**
   * Renders a render array.
   *
   * @param array $elements
   *   The elements to render.
   *
   * @return string
   *   The rendered string output (typically HTML).
   */
  protected function render(array &$elements) {
    $content = drupal_render($elements);
    drupal_process_attached($elements);
    $this->setRawContent($content);
    $this->verbose('<pre style="white-space: pre-wrap">' . String::checkPlain($content));
    return $content;
  }

}
