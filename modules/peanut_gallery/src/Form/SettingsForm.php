<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures site-wide defaults for the Peanut Gallery module.
 */
class SettingsForm extends ConfigFormBase {

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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('peanut_gallery.settings')
      ->set('default_allow_anonymous_comments', (bool) $form_state->getValue('default_allow_anonymous_comments'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
