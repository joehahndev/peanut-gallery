<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Kernel;

use Drupal\comment\Entity\CommentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shape of the config shipped in config/install.
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingConfigInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'comment',
    'node',
    'peanut_gallery',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('file');
    $this->installConfig(['filter', 'user', 'node', 'comment', 'peanut_gallery']);
  }

  /**
   * The 'recording' content type exists.
   */
  public function testRecordingNodeTypeExists(): void {
    $type = NodeType::load('recording');

    $this->assertNotNull($type);
    $this->assertSame('Recording', $type->label());
  }

  /**
   * The 'recording_comment' comment type exists and targets nodes.
   */
  public function testRecordingCommentTypeExists(): void {
    $comment_type = CommentType::load('recording_comment');

    $this->assertNotNull($comment_type);
    $this->assertSame('node', $comment_type->getTargetEntityTypeId());
  }

  /**
   * All of the recording's own fields exist with the expected field types.
   */
  public function testRecordingFieldsExistWithExpectedTypes(): void {
    $expected_field_types = [
      'field_audio_file' => 'file',
      'field_visibility' => 'boolean',
      'field_shared_with' => 'entity_reference',
      'field_allow_anonymous_comments' => 'boolean',
      'field_comments_visible_to_shared' => 'boolean',
      'field_comment' => 'comment',
    ];

    foreach ($expected_field_types as $field_name => $expected_type) {
      $field = FieldConfig::loadByName('node', 'recording', $field_name);
      $this->assertNotNull($field, "Field $field_name exists on node:recording.");
      $this->assertSame($expected_type, $field->getType(), "Field $field_name is of type $expected_type.");
    }
  }

  /**
   * Field_shared_with references user entities.
   */
  public function testFieldSharedWithReferencesUsers(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_shared_with');

    $this->assertNotNull($storage);
    $this->assertSame('user', $storage->getSetting('target_type'));
  }

  /**
   * Field_comments_visible_to_shared defaults to TRUE (visible to all).
   *
   * Matches pre-feature behavior for existing/new recordings unless a
   * site owner explicitly turns isolation on.
   */
  public function testFieldCommentsVisibleToSharedDefaultsToTrue(): void {
    $field = FieldConfig::loadByName('node', 'recording', 'field_comments_visible_to_shared');

    $this->assertNotNull($field);
    $this->assertSame([['value' => 1]], $field->getDefaultValueLiteral());
  }

  /**
   * Field_audio_file is a required, private-scheme file field.
   */
  public function testFieldAudioFileIsRequiredAndPrivate(): void {
    $field = FieldConfig::loadByName('node', 'recording', 'field_audio_file');
    $storage = FieldStorageConfig::loadByName('node', 'field_audio_file');

    $this->assertTrue($field->isRequired());
    $this->assertSame('private', $storage->getSetting('uri_scheme'));
  }

  /**
   * Field_timestamp on the comment bundle is a required decimal(10,3).
   */
  public function testFieldTimestampIsRequiredDecimalWithPrecisionAndScale(): void {
    $field = FieldConfig::loadByName('comment', 'recording_comment', 'field_timestamp');
    $storage = FieldStorageConfig::loadByName('comment', 'field_timestamp');

    $this->assertNotNull($field);
    $this->assertSame('decimal', $field->getType());
    $this->assertTrue($field->isRequired());

    $this->assertNotNull($storage);
    $this->assertSame(10, $storage->getSetting('precision'));
    $this->assertSame(3, $storage->getSetting('scale'));
  }

}
