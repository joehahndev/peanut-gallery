<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\peanut_gallery\Push\PushSender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends queued push notifications on cron.
 *
 * Pushing happens on cron rather than during the comment save because a
 * push service round-trip is a third-party HTTP call: doing it inline would
 * make posting a comment as slow as the slowest of Apple's, Google's and
 * Mozilla's endpoints, and would fail the comment if one of them were down.
 *
 * @QueueWorker(
 *   id = "peanut_gallery_push",
 *   title = @Translation("Peanut Gallery push notifications"),
 *   cron = {"time" = 30}
 * )
 */
final class PushNotificationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the push queue worker.
   *
   * The sender property is protected and non-readonly: QueueWorkerBase
   * pulls in DependencySerializationTrait, which supports neither private
   * nor readonly properties.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected PushSender $sender,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('peanut_gallery.push_sender'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!isset($data['subscription'], $data['payload'])) {
      return;
    }

    $this->sender->send($data['subscription'], $data['payload']);
  }

}
