<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\peanut_gallery\Invite\InviteStore;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Redeems an invite code into an account, without ever setting a password.
 *
 * The account is created with a random unknowable password and the person
 * is emailed a one-time login link. That is Drupal's own password-reset
 * mechanism used as the front door: there is no password to phish, forget,
 * or reuse from another site, and every later login goes through the same
 * "email me a link" flow.
 */
final class InviteAcceptForm extends FormBase {

  /**
   * Constructs the invite acceptance form.
   *
   * No request stack is injected: FormBase already declares an untyped
   * $requestStack property, and redeclaring it with a type is a fatal
   * error. Its getRequest() is used instead.
   */
  public function __construct(
    protected InviteStore $inviteStore,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EmailValidatorInterface $emailValidator,
    protected FloodInterface $flood,
    protected AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('peanut_gallery.invite_store'),
      $container->get('entity_type.manager'),
      $container->get('email.validator'),
      $container->get('flood'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'peanut_gallery_invite_accept';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $code = ''): array {
    $invite = $this->inviteStore->loadUsable($code);

    if (!$invite) {
      // One message for unknown, expired and spent alike — telling them
      // apart would turn this page into an oracle for guessing codes.
      $form['dead'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('This invitation link is no longer valid. Ask whoever sent it for a fresh one.'),
      ];
      return $form;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($invite->nid);

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $node
        ? $this->t('You have been invited to listen to "@title" and leave comments on it.', ['@title' => $node->label()])
        : $this->t('You have been invited to this site.'),
    ];

    $form['code'] = ['#type' => 'value', '#value' => $code];

    $form['display_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What should we call you?'),
      '#description' => $this->t('Shown next to your comments.'),
      '#required' => TRUE,
      '#maxlength' => 60,
    ];

    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email address'),
      '#description' => $this->t('We send you a link to sign in. There is no password to choose or remember.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send me my sign-in link'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->getRequest()->getClientIp() ?? '';

    // Codes are unguessable, but the form still submits arbitrary email
    // addresses into an outbound mailer, so rate-limit the endpoint.
    if (!$this->flood->isAllowed('peanut_gallery.invite_accept', 5, 3600, $ip)) {
      $form_state->setErrorByName('', $this->t('Too many attempts. Try again later.'));
      return;
    }

    $mail = trim((string) $form_state->getValue('mail'));
    if ($mail !== '' && !$this->emailValidator->isValid($mail)) {
      $form_state->setErrorByName('mail', $this->t('That does not look like a valid email address.'));
    }

    $name = trim((string) $form_state->getValue('display_name'));
    if ($name !== '' && $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name])) {
      $form_state->setErrorByName('display_name', $this->t('That name is taken. Please pick another.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ip = $this->getRequest()->getClientIp() ?? '';
    $this->flood->register('peanut_gallery.invite_accept', 3600, $ip);

    // Re-check rather than trusting the copy loaded during buildForm: the
    // link may have been redeemed in the meantime.
    $invite = $this->inviteStore->loadUsable((string) $form_state->getValue('code'));
    if (!$invite) {
      $this->messenger()->addError($this->t('This invitation link is no longer valid.'));
      $form_state->setRedirect('<front>');
      return;
    }

    $mail = trim((string) $form_state->getValue('mail'));
    $user_storage = $this->entityTypeManager->getStorage('user');

    $existing = $user_storage->loadByProperties(['mail' => $mail]);
    $account = $existing ? reset($existing) : NULL;

    if (!$account) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $user_storage->create([
        'name' => trim((string) $form_state->getValue('display_name')),
        'mail' => $mail,
        'status' => 1,
        // Never a known password: sign-in is by emailed link only. A random
        // one keeps the account from having an empty-password hash.
        'pass' => bin2hex(random_bytes(24)),
      ]);
      $account->save();
    }

    if (!$this->inviteStore->claim((int) $invite->id, (int) $account->id())) {
      // Someone else redeemed it between the check above and here.
      $this->messenger()->addError($this->t('This invitation link is no longer valid.'));
      $form_state->setRedirect('<front>');
      return;
    }

    $this->grantAccess($account, (int) $invite->nid);

    _peanut_gallery_send_invite_login_link($account, (int) $invite->nid);

    $this->messenger()->addStatus($this->t('Check your email — we sent a link that signs you straight in.'));
    $form_state->setRedirect('<front>');
  }

  /**
   * Adds the account to the invited recording's shared-with list.
   */
  private function grantAccess(UserInterface $account, int $nid): void {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node || !$node->hasField('field_shared_with')) {
      return;
    }

    $uids = array_column($node->get('field_shared_with')->getValue(), 'target_id');
    if (in_array((string) $account->id(), array_map('strval', $uids), TRUE)) {
      return;
    }

    $uids[] = $account->id();
    $node->set('field_shared_with', $uids);
    $node->save();
  }

}
