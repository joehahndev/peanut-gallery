<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Push;

use Drupal\Core\State\StateInterface;
use Minishlink\WebPush\VAPID;

/**
 * Supplies the site's VAPID keypair, generating it once on first use.
 *
 * The pair lives in State rather than config on purpose. The private key is
 * a credential: config is exportable and routinely committed to a repo,
 * State is not. It also means the keys are per-environment, which is what
 * you want — dev and production should never share a push identity.
 *
 * Regenerating invalidates every existing subscription (browsers bind their
 * subscription to the public key they saw), so the pair is generated once
 * and then left alone.
 */
final class VapidKeys {

  private const STATE_KEY = 'peanut_gallery.vapid';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Returns the keypair, creating it on first call.
   *
   * @return array{publicKey: string, privateKey: string}
   *   The VAPID keypair.
   */
  public function get(): array {
    $keys = $this->state->get(self::STATE_KEY);
    if (is_array($keys) && !empty($keys['publicKey']) && !empty($keys['privateKey'])) {
      return $keys;
    }

    $keys = VAPID::createVapidKeys();
    $this->state->set(self::STATE_KEY, $keys);

    return $keys;
  }

  /**
   * Returns just the public key, which is safe to hand to the browser.
   */
  public function publicKey(): string {
    return $this->get()['publicKey'];
  }

}
