<?php

/**
 * @file
 * Contains \Drupal\node\Tests\NodeBodyFieldStorageTest.
 */

namespace Drupal\node\Tests;

use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\simpletest\KernelTestBase;
use Drupal\system\Tests\Entity\EntityUnitTestBase;

/**
 * Tests node body field storage.
 *
 * @group node
 */
class NodeBodyFieldStorageTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('user', 'system', 'field', 'node', 'text', 'filter', 'entity_reference');

  protected function setUp() {
    parent::setUp();
    $this->installSchema('system', 'sequences');
    // Necessary for module uninstall.
    $this->installSchema('user', 'users_data');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(array('field'));
  }

  /**
   * Tests node body field storage persistence even if there are no instances.
   */
  public function testFieldOverrides() {
    $field_storage = FieldStorageConfig::loadByName('node', 'body');
    $this->assertTrue($field_storage, 'Node body field storage exists.');
    $type = NodeType::create(['name' => 'Ponies', 'type' => 'ponies']);
    $type->save();
    node_add_body_field($type);
    $field_storage = FieldStorageConfig::loadByName('node', 'body');
    $this->assertTrue(count($field_storage->getBundles()) == 1, 'Node body field storage is being used on the new node type.');
    $field = FieldConfig::loadByName('node', 'ponies', 'body');
    $field->delete();
    $field_storage = FieldStorageConfig::loadByName('node', 'body');
    $this->assertTrue(count($field_storage->getBundles()) == 0, 'Node body field storage exists after deleting the only instance of a field.');
    \Drupal::moduleHandler()->uninstall(array('node'));
    $field_storage = FieldStorageConfig::loadByName('node', 'body');
    $this->assertFalse($field_storage, 'Node body field storage does not exist after uninstalling the Node module.');
  }

}
