<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\peanut_gallery\Invite\InviteStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates an invitation link for one recording.
 *
 * The link is shown once, on screen, for the issuer to send however they
 * like — no address is collected here, because the point is to hand a
 * friend a link over whatever channel you already talk on.
 */
final class InviteCreateForm extends FormBase {

  public function __construct(
    protected InviteStore $inviteStore,
    protected AccountInterface $account,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('peanut_gallery.invite_store'),
      $container->get('current_user'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'peanut_gallery_invite_create';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('nid', (int) $node->id());

    $form['explanation'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Create a link that lets one person make an account and listen to "@title". The link works once and expires after two weeks.', [
        '@title' => $node->label(),
      ]),
    ];

    // A freshly minted code is passed through form state so it survives the
    // rebuild and can be shown exactly once.
    if ($code = $form_state->get('new_code')) {
      $form['result'] = [
        '#type' => 'item',
        '#title' => $this->t('Send this link to your friend'),
        '#markup' => '<code>' . Url::fromRoute('peanut_gallery.invite_accept', ['code' => $code], ['absolute' => TRUE])->toString() . '</code>',
        '#description' => $this->t('Copy it now — it is not shown again, because only its fingerprint is stored.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create an invitation link'),
    ];

    $form['existing'] = $this->buildExistingTable((int) $node->id());

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $code = $this->inviteStore->issue((int) $form_state->get('nid'), (int) $this->account->id());
    $form_state->set('new_code', $code);
    $form_state->setRebuild();
  }

  /**
   * Lists invites already issued for this recording.
   *
   * @return array<string, mixed>
   *   A table render array.
   */
  private function buildExistingTable(int $nid): array {
    $rows = [];
    foreach ($this->inviteStore->listForRecording($nid) as $invite) {
      $rows[] = [
        $this->dateFormatter->format((int) $invite->created, 'short'),
        (int) $invite->accepted_uid !== 0
          ? $this->t('Used @when', ['@when' => $this->dateFormatter->format((int) $invite->accepted, 'short')])
          : ((int) $invite->expires < time()
            ? $this->t('Expired')
            : $this->t('Waiting, expires @when', ['@when' => $this->dateFormatter->format((int) $invite->expires, 'short')])),
      ];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Invitations for this recording'),
      '#header' => [$this->t('Created'), $this->t('Status')],
      '#rows' => $rows,
      '#empty' => $this->t('None yet.'),
    ];
  }

}
