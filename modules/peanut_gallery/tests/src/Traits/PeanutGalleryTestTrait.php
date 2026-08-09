<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Traits;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Shared fixture helpers for Peanut Gallery Kernel and Functional tests.
 *
 * Usable from either test environment: it relies only on entity APIs and
 * the 'file.repository' service, which are available in both once the
 * 'peanut_gallery', 'node', 'file', and 'user' modules are enabled (Kernel)
 * or installed (Functional).
 */
trait PeanutGalleryTestTrait {

  /**
   * Registers the private:// stream wrapper for Kernel tests.
   *
   * Core's own CoreServiceProvider only registers 'stream_wrapper.private'
   * when Settings::get('file_private_path') is already set AT CONTAINER
   * COMPILE TIME — which happens during parent::setUp()'s kernel boot, well
   * before a Kernel test's own setUp() body gets to call
   * $this->setSetting(). That makes the usual $this->setSetting() call too
   * late for this particular setting; registering the service directly
   * here (the same workaround core's own
   * \Drupal\KernelTests\Core\File\FileTestBase uses) is what actually makes
   * private:// resolvable in a Kernel test.
   *
   * Kernel test classes using this trait must call this from their own
   * register() override:
   * @code
   * public function register(ContainerBuilder $container): void {
   *   parent::register($container);
   *   $this->registerPrivateStreamWrapper($container);
   * }
   * @endcode
   */
  protected function registerPrivateStreamWrapper(ContainerBuilder $container): void {
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * Creates a 'recording' node with sensible, overridable defaults.
   *
   * Defaults to a published, public recording with anonymous comments
   * disabled and a real (fake-content) private audio file attached, since
   * field_audio_file is required on the bundle. Pass any field/property to
   * override, including 'field_shared_with' as an array of user entities
   * or plain uids.
   *
   * @param array $values
   *   Field/property values to set on the node, overriding the defaults.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createRecordingNode(array $values = []): NodeInterface {
    if (isset($values['field_shared_with'])) {
      $values['field_shared_with'] = array_map(
        static fn ($user_or_id) => $user_or_id instanceof UserInterface ? $user_or_id->id() : $user_or_id,
        $values['field_shared_with'],
      );
    }

    $values += [
      'type' => 'recording',
      'title' => 'Test recording ' . $this->randomMachineName(8),
      'status' => NodeInterface::PUBLISHED,
      // Public by default so tests that don't care about visibility aren't
      // accidentally exercising the private-access code paths.
      'field_visibility' => TRUE,
      'field_allow_anonymous_comments' => FALSE,
    ];

    if (!isset($values['field_audio_file'])) {
      $file = $this->createRecordingAudioFile();
      $values['field_audio_file'] = ['target_id' => $file->id()];
    }

    $node = Node::create($values);
    $node->save();

    return $node;
  }

  /**
   * Creates and saves a real private file suitable for field_audio_file.
   *
   * Writes actual bytes (rather than pointing at a bare File entity with
   * no content) so that file-download tests exercise a real private://
   * file the same way a production upload would.
   *
   * @param array $values
   *   Optional overrides: 'data' (file contents) and 'filename'.
   *
   * @return \Drupal\file\FileInterface
   *   The saved, permanent file entity.
   */
  protected function createRecordingAudioFile(array $values = []): FileInterface {
    $data = $values['data'] ?? 'not really audio, but real bytes for ' . $this->randomMachineName(16);
    $filename = $values['filename'] ?? $this->randomMachineName(8) . '.mp3';

    // writeData() -> move() only ever passes MODIFY_PERMISSIONS to
    // prepareDirectory(), never CREATE_DIRECTORY — it will not create a
    // missing 'recordings' subdirectory itself, unlike a real upload
    // (which goes through the field widget's own directory preparation).
    // prepareDirectory() takes its argument by reference, hence the
    // intermediate variable rather than passing the literal directly.
    $recordings_directory = 'private://recordings';
    \Drupal::service('file_system')->prepareDirectory($recordings_directory, FileSystemInterface::CREATE_DIRECTORY);

    /** @var \Drupal\file\FileRepositoryInterface $file_repository */
    $file_repository = \Drupal::service('file.repository');
    $file = $file_repository->writeData($data, 'private://recordings/' . $filename, FileExists::Replace);

    // writeData() is expected to save the file entity as permanent already,
    // but don't assume it: field_audio_file usage/downloads depend on it.
    if (!$file->isPermanent()) {
      $file->setPermanent();
      $file->save();
    }

    return $file;
  }

}
