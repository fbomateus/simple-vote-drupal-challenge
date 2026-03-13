<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulário de configurações globais do módulo Simple Vote.
 *
 * Permite habilitar ou desabilitar a votação de forma global,
 * afetando tanto o CMS quanto a API.
 */
final class SimpleVoteSettingsForm extends ConfigFormBase {

  private const CONFIG_NAME = 'simple_vote.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_vote_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['voting'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Configurações de votação'),
    ];

    $form['voting']['global_voting_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Habilitar votação globalmente'),
      '#description' => $this->t(
        'Quando desabilitado, nenhuma votação pode ser realizada no site ou via API, independentemente do status individual das perguntas.'
      ),
      '#default_value' => $config->get('global_voting_enabled') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('global_voting_enabled', (bool) $form_state->getValue('global_voting_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
