<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountInterface;
use Drupal\peanut_gallery\Push\PushSubscriptionStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the PWA shell and handles push subscription registration.
 */
final class PushController extends ControllerBase {

  /**
   * Constructs the push controller.
   *
   * Properties are protected and non-readonly because ControllerBase brings
   * in DependencySerializationTrait, which supports neither.
   */
  public function __construct(
    protected PushSubscriptionStore $store,
    protected AccountInterface $account,
    protected ModuleExtensionList $moduleList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('peanut_gallery.push_subscription_store'),
      $container->get('current_user'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * Serves the web app manifest.
   *
   * Required for iOS: Safari only delivers Web Push to a site that has been
   * added to the Home Screen, and it will only offer that for a page with a
   * valid manifest declaring display: standalone.
   */
  public function manifest(): JsonResponse {
    $site_name = $this->config('system.site')->get('name') ?: 'Peanut Gallery';

    $response = new JsonResponse([
      'name' => $site_name,
      'short_name' => $site_name,
      'description' => 'Listen to recordings and comment on the moment.',
      'start_url' => '/',
      'scope' => '/',
      'display' => 'standalone',
      'orientation' => 'portrait',
      'background_color' => '#fbfaf8',
      'theme_color' => '#c2410c',
      'icons' => [
        [
          'src' => '/' . $this->modulePath() . '/images/icon-192.png',
          'sizes' => '192x192',
          'type' => 'image/png',
          'purpose' => 'any maskable',
        ],
        [
          'src' => '/' . $this->modulePath() . '/images/icon-512.png',
          'sizes' => '512x512',
          'type' => 'image/png',
          'purpose' => 'any maskable',
        ],
      ],
    ]);

    $response->headers->set('Cache-Control', 'public, max-age=3600');

    return $response;
  }

  /**
   * Serves the service worker JavaScript.
   *
   * Served through a controller rather than as a plain file because a
   * service worker's scope is capped by its own URL path: a worker loaded
   * from /modules/custom/... could only ever control that directory. The
   * Service-Worker-Allowed header lifts that cap so the worker can claim
   * the whole site, which is what a push handler needs.
   */
  public function serviceWorker(): Response {
    $path = $this->moduleDirectory() . '/js/service-worker.js';
    $javascript = is_readable($path) ? (string) file_get_contents($path) : '';

    $response = new Response($javascript);
    $response->headers->set('Content-Type', 'application/javascript; charset=utf-8');
    $response->headers->set('Service-Worker-Allowed', '/');
    $response->headers->set('Cache-Control', 'no-cache');

    return $response;
  }

  /**
   * Registers a browser's push subscription against the current user.
   */
  public function subscribe(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);

    $endpoint = $data['endpoint'] ?? NULL;
    $p256dh = $data['keys']['p256dh'] ?? NULL;
    $auth = $data['keys']['auth'] ?? NULL;

    if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Malformed subscription.'], 400);
    }

    $this->store->save((int) $this->account->id(), $endpoint, $p256dh, $auth);

    return new JsonResponse(['status' => 'subscribed']);
  }

  /**
   * Forgets a browser's push subscription.
   */
  public function unsubscribe(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    $endpoint = $data['endpoint'] ?? NULL;

    if (!is_string($endpoint)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Malformed request.'], 400);
    }

    $this->store->delete($endpoint);

    return new JsonResponse(['status' => 'unsubscribed']);
  }

  /**
   * Returns this module's path relative to the docroot.
   */
  private function modulePath(): string {
    return $this->moduleList->getPath('peanut_gallery');
  }

  /**
   * Returns this module's absolute directory.
   */
  private function moduleDirectory(): string {
    return DRUPAL_ROOT . '/' . $this->modulePath();
  }

}
