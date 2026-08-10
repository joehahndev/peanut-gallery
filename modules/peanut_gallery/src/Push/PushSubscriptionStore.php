<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Push;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Reads and writes browser push subscriptions.
 *
 * A subscription is identified by its endpoint URL, which is issued by the
 * browser's push service and can be well over 255 characters, so rows are
 * keyed on a hash of it rather than the value itself.
 */
final class PushSubscriptionStore {

  private const TABLE = 'peanut_gallery_push_subscription';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Stores a subscription, replacing any existing row for the same browser.
   *
   * Re-subscribing is routine — browsers rotate endpoints, and the client
   * re-registers on every page load — so this upserts instead of inserting,
   * otherwise one device would accumulate rows and get N notifications.
   */
  public function save(int $uid, string $endpoint, string $p256dh, string $auth): void {
    $this->database->merge(self::TABLE)
      ->key('endpoint_hash', hash('sha256', $endpoint))
      ->fields([
        'uid' => $uid,
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Deletes the subscription for one endpoint.
   */
  public function delete(string $endpoint): void {
    $this->database->delete(self::TABLE)
      ->condition('endpoint_hash', hash('sha256', $endpoint))
      ->execute();
  }

  /**
   * Returns every stored subscription.
   *
   * @return array<int, array{uid: int, endpoint: string, p256dh: string, auth: string}>
   *   All subscriptions.
   */
  public function loadAll(): array {
    $rows = $this->database->select(self::TABLE, 's')
      ->fields('s', ['uid', 'endpoint', 'p256dh', 'auth'])
      ->execute()
      ->fetchAll();

    return array_map(static fn (object $row): array => [
      'uid' => (int) $row->uid,
      'endpoint' => (string) $row->endpoint,
      'p256dh' => (string) $row->p256dh,
      'auth' => (string) $row->auth,
    ], $rows ?: []);
  }

  /**
   * Whether a given user has at least one subscribed browser.
   */
  public function hasSubscription(int $uid): bool {
    return (bool) $this->database->select(self::TABLE, 's')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
