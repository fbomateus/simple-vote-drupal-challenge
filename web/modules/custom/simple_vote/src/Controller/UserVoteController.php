<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\simple_vote\Entity\VoteQuestion;
use Drupal\simple_vote\Form\UserVoteForm;
use Drupal\simple_vote\Service\SimpleVoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller para páginas públicas de votação.
 *
 * Renderiza a listagem de perguntas disponíveis e a interface
 * de votação para cada pergunta individual.
 */
final class UserVoteController extends ControllerBase {

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
   * Página de listagem das perguntas disponíveis.
   *
   * @return array
   *   Render array da página.
   */
  public function listQuestions(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['simple-vote-list']],
    ];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Selecione uma pergunta para participar da votação.') . '</p>',
    ];

    $questions = $this->voteManager->loadPublishedQuestions();

    if (empty($questions)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('Nenhuma pergunta disponível no momento.') . '</p>',
      ];

      return $build;
    }

    $items = [];

    foreach ($questions as $question) {
      $items[] = [
        '#type' => 'link',
        '#title' => $question->label(),
        '#url' => Url::fromRoute('simple_vote.user_question', [
          'vote_question' => $question->id(),
        ]),
      ];
    }

    $build['list'] = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['simple-vote-questions']],
    ];

    return $build;
  }

  /**
   * Callback de título dinâmico para página de votação.
   */
  public function questionTitle(VoteQuestion $vote_question): string|TranslatableMarkup {
    return $vote_question->label() ?: $this->t('Votação');
  }

  /**
   * Página de votação de uma pergunta específica.
   *
   * @return array
   *   Render array completo com pergunta, opções, formulário e resultados.
   */
  public function viewQuestion(VoteQuestion $vote_question): array {
    $build = [];

    // Cabeçalho da pergunta.
    $build['header'] = $this->buildQuestionHeader($vote_question);

    // Opções de resposta com imagens.
    $build['options'] = $this->buildOptionsDisplay($vote_question);

    // Formulário de votação.
    $build['form'] = $this->formBuilder()->getForm(UserVoteForm::class, $vote_question);

    // Resultados (se permitido).
    if ($this->shouldDisplayResults($vote_question)) {
      $build['results'] = $this->buildResultsTable($vote_question);
    }

    return $build;
  }

  /**
   * Monta o cabeçalho com título e texto da pergunta.
   */
  private function buildQuestionHeader(VoteQuestion $question): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['simple-vote-header']],
      'title' => [
        '#markup' => '<h2>' . $this->escapeHtml($question->label()) . '</h2>',
      ],
      'text' => [
        '#markup' => '<p>' . nl2br($this->escapeHtml($question->getQuestionText())) . '</p>',
      ],
    ];
  }

  /**
   * Monta a exibição visual das opções de resposta.
   */
  private function buildOptionsDisplay(VoteQuestion $question): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['simple-vote-options']],
    ];

    foreach ($question->getOptions() as $option) {
      $item = [
        '#type' => 'container',
        '#attributes' => ['class' => ['simple-vote-option']],
      ];

      // Título da opção.
      $title = $option['title'] ?? '';
      $item['title'] = [
        '#markup' => '<div class="option-title"><strong>' . $this->escapeHtml($title) . '</strong></div>',
      ];

      // Descrição opcional.
      if (!empty($option['description'])) {
        $item['description'] = [
          '#markup' => '<div class="option-description">' . $this->escapeHtml($option['description']) . '</div>',
        ];
      }

      // Imagem da opção (se existir).
      if (!empty($option['image_fid'])) {
        $image = $this->buildOptionImage($option);

        if ($image !== NULL) {
          $item['image'] = $image;
        }
      }

      $build[] = $item;
    }

    return $build;
  }

  /**
   * Monta elemento de imagem para uma opção.
   */
  private function buildOptionImage(array $option): ?array {
    $fid = (int) ($option['image_fid'] ?? 0);

    if ($fid <= 0) {
      return NULL;
    }

    $file = File::load($fid);

    if ($file === NULL) {
      return NULL;
    }

    return [
      '#theme' => 'image',
      '#uri' => $file->getFileUri(),
      '#alt' => $option['title'] ?? '',
      '#attributes' => [
        'class' => ['option-image'],
        'style' => 'max-width: 220px; height: auto; margin-bottom: 12px;',
      ],
    ];
  }

  /**
   * Monta tabela de resultados da votação.
   */
  private function buildResultsTable(VoteQuestion $question): array {
    $results = $this->voteManager->calculateResults((int) $question->id());
    $options = $results['options'] ?? [];

    $rows = [];

    foreach ($options as $option) {
      $rows[] = [
        $option['title'] ?: $this->t('Sem título'),
        $option['votes'] ?? 0,
        ($option['percentage'] ?? 0) . '%',
      ];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Resultados parciais'),
      '#header' => [
        $this->t('Opção'),
        $this->t('Votos'),
        $this->t('Percentual'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nenhum voto registrado.'),
      '#attributes' => ['class' => ['simple-vote-results']],
    ];
  }

  /**
   * Verifica se os resultados devem ser exibidos para o usuário atual.
   */
  private function shouldDisplayResults(VoteQuestion $question): bool {
    if (!$question->shouldShowResults()) {
      return FALSE;
    }

    return $this->currentUser()->hasPermission('view simple vote results');
  }

  /**
   * Escapa HTML para exibição segura.
   */
  private function escapeHtml(?string $text): string {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  }

}
