<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Push;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers one push notification to one subscription.
 *
 * Kept deliberately small and synchronous: the queue worker calls this once
 * per subscription, so a single dead endpoint or slow push service can only
 * ever affect its own queue item rather than a whole batch.
 */
final class PushSender {

  public function __construct(
    private readonly VapidKeys $vapidKeys,
    private readonly PushSubscriptionStore $store,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Sends a payload, pruning the subscription if it is permanently gone.
   *
   * @param array{endpoint: string, p256dh: string, auth: string} $subscription
   *   The stored subscription.
   * @param array<string, mixed> $payload
   *   Data for the service worker: title, body, url.
   *
   * @return bool
   *   TRUE when the push service accepted it.
   */
  public function send(array $subscription, array $payload): bool {
    $keys = $this->vapidKeys->get();
    $site_mail = $this->configFactory->get('system.site')->get('mail') ?: 'admin@example.com';

    $web_push = new WebPush([
      'VAPID' => [
        // The subject identifies the sender to the push service so it has
        // someone to contact about abuse; it must be a mailto: or URL.
        'subject' => 'mailto:' . $site_mail,
        'publicKey' => $keys['publicKey'],
        'privateKey' => $keys['privateKey'],
      ],
    ]);

    $report = $web_push->sendOneNotification(
      Subscription::create([
        'endpoint' => $subscription['endpoint'],
        'publicKey' => $subscription['p256dh'],
        'authToken' => $subscription['auth'],
      ]),
      json_encode($payload, JSON_THROW_ON_ERROR),
    );

    if ($report->isSuccess()) {
      return TRUE;
    }

    // 404/410 mean the browser threw the subscription away — the user
    // cleared site data, uninstalled the PWA, or revoked permission. Those
    // never recover, so drop the row instead of retrying forever.
    if ($report->isSubscriptionExpired()) {
      $this->store->delete($subscription['endpoint']);
      $this->logger->info('Removed an expired push subscription.');
      return FALSE;
    }

    $this->logger->warning('Push delivery failed: @reason', ['@reason' => $report->getReason()]);

    return FALSE;
  }

}
