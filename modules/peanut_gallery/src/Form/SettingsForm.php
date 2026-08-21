<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\peanut_gallery\Push\VapidKeys;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures site-wide defaults for the Peanut Gallery module.
 *
 * Final so new static() in create() is unambiguous, and the property is not
 * readonly: ConfigFormBase brings in DependencySerializationTrait, which
 * cannot restore readonly properties on PHP below 8.4.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EmailValidatorInterface $emailValidator,
    protected VapidKeys $vapidKeys,
    protected CsrfTokenGenerator $csrfToken,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('email.validator'),
      $container->get('peanut_gallery.vapid_keys'),
      $container->get('csrf_token'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['peanut_gallery.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'peanut_gallery_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('peanut_gallery.settings');

    $form['default_allow_anonymous_comments'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow anonymous comments by default on new recordings'),
      '#description' => $this->t('Applies only when a new recording is created; each recording can still be changed individually on its edit form.'),
      '#default_value' => $config->get('default_allow_anonymous_comments'),
    ];

    $form['default_allow_downloads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer a download link by default on new recordings'),
      '#description' => $this->t('Applies only when a new recording is created; each recording can still be changed individually on its edit form. A download link does not widen who may reach the audio — a listener can already save the stream their browser is playing — it only offers the original file explicitly.'),
      '#default_value' => $config->get('default_allow_downloads'),
    ];

    $form['comment_preroll_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Comment pre-roll (seconds)'),
      '#description' => $this->t('Jumping to a comment starts playback this many seconds before its moment, so the listener hears the context leading into it. 0 lands exactly on the moment.'),
      '#min' => 0,
      '#max' => 10,
      '#step' => 1,
      '#default_value' => $config->get('comment_preroll_seconds') ?? 3,
    ];

    $form['notification_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notify these addresses about comments'),
      '#description' => $this->t('One address per line. Each gets an email whenever a comment on a recording is posted or edited. Leave empty to send nothing.'),
      '#default_value' => implode("\n", $config->get('notification_emails') ?: []),
      '#rows' => 4,
    ];

    $form['push'] = [
      '#type' => 'details',
      '#title' => $this->t('Push notifications'),
      '#open' => TRUE,
      '#description' => $this->t('Push notifications are granted per device, so this button has to be pressed on each phone or computer you want notified. On iPhone and iPad, add this site to your Home Screen and open it from there first — Safari only allows notifications for an installed site.'),
    ];

    $form['push']['toggle'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Notify me on this device'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['button'],
        'data-pg-push-toggle' => 'true',
      ],
      '#attached' => [
        'library' => ['peanut_gallery/push_subscribe'],
        'drupalSettings' => [
          'peanutGallery' => [
            'vapidPublicKey' => $this->vapidKeys->publicKey(),
            'serviceWorkerUrl' => Url::fromRoute('peanut_gallery.service_worker')->toString(),
            'subscribeUrl' => Url::fromRoute('peanut_gallery.push_subscribe')->toString(),
            'unsubscribeUrl' => Url::fromRoute('peanut_gallery.push_unsubscribe')->toString(),
            'csrfToken' => $this->csrfToken->get('rest'),
          ],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $invalid = array_filter(
      self::splitAddresses($form_state->getValue('notification_emails')),
      fn (string $address): bool => !$this->emailValidator->isValid($address),
    );

    if ($invalid) {
      $form_state->setErrorByName('notification_emails', $this->t('These are not valid email addresses: @list', [
        '@list' => implode(', ', $invalid),
      ]));
    }
  }

  /**
   * Splits a textarea value into a clean list of addresses.
   *
   * @return string[]
   *   Trimmed, non-empty addresses, in the order given.
   */
  private static function splitAddresses(?string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: [])));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('peanut_gallery.settings')
      ->set('default_allow_anonymous_comments', (bool) $form_state->getValue('default_allow_anonymous_comments'))
      ->set('default_allow_downloads', (bool) $form_state->getValue('default_allow_downloads'))
      ->set('comment_preroll_seconds', (int) $form_state->getValue('comment_preroll_seconds'))
      ->set('notification_emails', self::splitAddresses($form_state->getValue('notification_emails')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
