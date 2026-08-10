<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Tells the page a recording comment was posted.
 *
 * A custom command rather than InvokeCommand('body', 'trigger', ...):
 * that would fire the event through jQuery's own event system, which
 * native addEventListener() handlers never see. The module's JS is
 * jQuery-free by design, so the event has to be dispatched as a real
 * CustomEvent — which is what the matching Drupal.AjaxCommands handler in
 * comment-form.js does.
 */
final class CommentPostedCommand implements CommandInterface {

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return ['command' => 'peanutGalleryCommentPosted'];
  }

}
