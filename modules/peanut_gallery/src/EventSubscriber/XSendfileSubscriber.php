<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\EventSubscriber;

use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hands private file delivery to the web server via X-Sendfile.
 *
 * Drupal still performs every access check — this only changes who pushes
 * the bytes once access has been granted. Without it PHP reads and echoes
 * the whole file, which measured 975 kB/s on this project's host: a
 * five-megabyte seek buffer in a long recording took 5.4 seconds, so
 * clicking to a new position in an eight-hour mix stalled noticeably.
 * Letting Apache serve the file directly measured 4.3 MB/s for the same
 * range, about 4.4x faster.
 *
 * OFF BY DEFAULT, and deliberately so. If the header is emitted on a
 * server whose mod_xsendfile is not active, nothing intercepts it and the
 * visitor receives an empty body — a silent, total breakage of every
 * download. Opting in per-environment means a host that cannot do this
 * keeps working exactly as before:
 *
 * @code
 * $settings['peanut_gallery_xsendfile'] = TRUE;
 * @endcode
 */
final class XSendfileSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Late, so anything that wants to inspect or alter the real response
    // has already run.
    return [KernelEvents::RESPONSE => ['onResponse', -512]];
  }

  /**
   * Swaps a file-streaming response for an X-Sendfile instruction.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!Settings::get('peanut_gallery_xsendfile', FALSE)) {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof BinaryFileResponse) {
      return;
    }

    $path = $response->getFile()->getRealPath();
    if ($path === FALSE || !is_readable($path)) {
      return;
    }

    // Only files this module is responsible for. Other modules may have
    // their own reasons for streaming through PHP, and silently changing
    // how their downloads are delivered is not this subscriber's business.
    $private = Settings::get('file_private_path');
    if (!$private) {
      return;
    }
    $private_real = realpath($private);
    if ($private_real === FALSE || !str_starts_with($path, $private_real . DIRECTORY_SEPARATOR . 'recordings')) {
      return;
    }

    // Keep the headers the access layer already decided on (content type,
    // disposition, cache directives) but drop the body: the web server
    // supplies it, and it sets its own Content-Length and handles Range.
    $replacement = new Response('', $response->getStatusCode(), $response->headers->all());
    $replacement->headers->remove('Content-Length');
    $replacement->headers->set('X-Sendfile', $path);

    $event->setResponse($replacement);
  }

}
