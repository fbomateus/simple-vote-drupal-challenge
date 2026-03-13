<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_vote\Entity\VoteQuestion;
use Drupal\simple_vote\Service\SimpleVoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulário de votação para usuários finais.
 *
 * Exibe as opções de uma pergunta como radio buttons e processa
 * o envio do voto. Bloqueia novos votos se o usuário já votou
 * ou se a votação está desabilitada.
 */
final class UserVoteForm extends FormBase {

  public function __construct(
    private readonly SimpleVoteManager $voteManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('simple_vote.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_vote_user_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?VoteQuestion $vote_question = NULL): array {
    if ($vote_question === NULL) {
      return $form;
    }

    $questionId = (int) $vote_question->id();
    $existingVote = $this->voteManager->getCurrentUserVote($questionId);
    $votingEnabled = $this->voteManager->isVotingEnabled($vote_question);
    $hasVoted = $existingVote !== NULL;

    // Monta opções para o radio button.
    $radioOptions = $this->buildRadioOptions($vote_question);

    $form['question_id'] = [
      '#type' => 'hidden',
      '#value' => $questionId,
    ];

    $form['option_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Escolha sua opção'),
      '#options' => $radioOptions,
      '#required' => TRUE,
      '#default_value' => $existingVote['option_id'] ?? NULL,
      '#disabled' => $hasVoted || !$votingEnabled,
    ];

    // Mensagens informativas.
    if ($hasVoted) {
      $form['voted_notice'] = [
        '#markup' => '<p class="messages messages--status">' . $this->t('Seu voto já foi registrado.') . '</p>',
      ];
    }

    if (!$votingEnabled) {
      $form['disabled_notice'] = [
        '#markup' => '<p class="messages messages--warning">' . $this->t('A votação está temporariamente desabilitada.') . '</p>',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirmar voto'),
      '#disabled' => $hasVoted || !$votingEnabled,
      '#access' => !$hasVoted,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $questionId = (int) $form_state->getValue('question_id');
    $optionId = (string) $form_state->getValue('option_id');

    $result = $this->voteManager->registerVote($questionId, $optionId);

    if ($result['success']) {
      $this->messenger()->addStatus($this->t('Voto registrado com sucesso.'));
    }
    else {
      $this->messenger()->addError($result['message']);
    }
  }

  /**
   * Converte opções da pergunta para formato de radio buttons.
   *
   * @return array<string, string>
   *   Mapa de índice => título.
   */
  private function buildRadioOptions(VoteQuestion $question): array {
    $options = [];

    foreach ($question->getOptions() as $index => $option) {
      $title = $option['title'] ?? '';
      $options[(string) $index] = $title ?: $this->t('Opção @num', ['@num' => $index + 1]);
    }

    return $options;
  }

}
