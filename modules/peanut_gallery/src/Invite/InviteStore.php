<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Invite;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Issues and redeems single-use invitation codes.
 *
 * Codes are stored only as a SHA-256 hash. The plaintext exists exactly
 * once, in the link handed to the issuer, and is never written down — so a
 * dump of this table cannot be replayed into free accounts on the site.
 * Lookup is by hash rather than by comparing secrets, which sidesteps
 * timing-comparison concerns entirely.
 */
final class InviteStore {

  private const TABLE = 'peanut_gallery_invite';

  /**
   * How long a freshly issued code stays valid.
   */
  public const LIFETIME = 14 * 24 * 60 * 60;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Issues a code for a recording and returns the plaintext.
   *
   * The return value is the only time the caller can see it.
   *
   * @param int $nid
   *   Recording the invitee will be granted access to.
   * @param int $issuer_uid
   *   Who is issuing it.
   *
   * @return string
   *   The plaintext code, for building the invite URL.
   */
  public function issue(int $nid, int $issuer_uid): string {
    // 32 bytes: far beyond guessable, and base64url keeps it URL-safe
    // without escaping.
    $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $now = $this->time->getRequestTime();

    $this->database->insert(self::TABLE)
      ->fields([
        'code_hash' => hash('sha256', $code),
        'nid' => $nid,
        'created_by' => $issuer_uid,
        'created' => $now,
        'expires' => $now + self::LIFETIME,
      ])
      ->execute();

    return $code;
  }

  /**
   * Returns a usable invite for this code, or NULL.
   *
   * NULL covers every failure — unknown, expired and already redeemed —
   * deliberately, so the accept page cannot be used to probe which codes
   * once existed.
   *
   * @return object|null
   *   The invite row, or NULL when it cannot be redeemed.
   */
  public function loadUsable(string $code): ?object {
    $invite = $this->database->select(self::TABLE, 'i')
      ->fields('i')
      ->condition('code_hash', hash('sha256', $code))
      ->execute()
      ->fetchObject();

    if (!$invite) {
      return NULL;
    }
    if ((int) $invite->accepted_uid !== 0) {
      return NULL;
    }
    if ((int) $invite->expires < $this->time->getRequestTime()) {
      return NULL;
    }

    return $invite;
  }

  /**
   * Marks an invite spent, refusing to double-spend.
   *
   * The accepted_uid condition makes this the atomic point of the whole
   * flow: two simultaneous submissions of the same link both reach here,
   * and only the one that actually updates a row may proceed.
   *
   * @return bool
   *   TRUE if this caller claimed the invite.
   */
  public function claim(int $id, int $uid): bool {
    $updated = $this->database->update(self::TABLE)
      ->fields([
        'accepted_uid' => $uid,
        'accepted' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('accepted_uid', 0)
      ->execute();

    return (int) $updated === 1;
  }

  /**
   * Lists invites issued for a recording, newest first.
   *
   * @return object[]
   *   Invite rows.
   */
  public function listForRecording(int $nid): array {
    return $this->database->select(self::TABLE, 'i')
      ->fields('i')
      ->condition('nid', $nid)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Deletes an invite outright, used to revoke an unspent one.
   */
  public function delete(int $id): void {
    $this->database->delete(self::TABLE)->condition('id', $id)->execute();
  }

}
