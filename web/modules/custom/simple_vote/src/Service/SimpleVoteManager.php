<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\Entity\File;
use Drupal\simple_vote\Entity\VoteQuestion;
use Psr\Log\LoggerInterface;

/**
 * Serviço central do módulo Simple Vote.
 *
 * Concentra a lógica de negócio relacionada a votações: carregamento
 * de perguntas, registro de votos, cálculo de resultados e serialização
 * para API.
 */
final class SimpleVoteManager {

  private const TABLE_RESPONSES = 'simple_vote_response';
  private const CONFIG_KEY = 'simple_vote.settings';

  /**
   * Canal de log do módulo.
   */
  private LoggerInterface $logger;

  /**
   * Construtor com injeção de dependências.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    $this->logger = $loggerFactory->get('simple_vote');
  }

  /**
   * Carrega todas as perguntas publicadas, ordenadas por data de criação.
   *
   * @return VoteQuestion[]
   *   Lista de perguntas ativas.
   */
  public function loadPublishedQuestions(): array {
    $storage = $this->entityTypeManager->getStorage('vote_question');

    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * Carrega uma pergunta pelo seu identificador único (slug).
   *
   * Retorna NULL se não existir ou não estiver publicada.
   */
  public function loadQuestionByIdentifier(string $identifier): ?VoteQuestion {
    $storage = $this->entityTypeManager->getStorage('vote_question');

    $results = $storage->loadByProperties([
      'identifier' => $identifier,
      'status' => 1,
    ]);

    $question = reset($results);

    return $question instanceof VoteQuestion ? $question : NULL;
  }

  /**
   * Verifica se a votação está habilitada para determinada pergunta.
   *
   * Considera tanto a configuração global quanto o status individual.
   */
  public function isVotingEnabled(VoteQuestion $question): bool {
    $globalEnabled = (bool) $this->configFactory
      ->get(self::CONFIG_KEY)
      ->get('global_voting_enabled');

    return $globalEnabled && $question->isPublished();
  }

  /**
   * Retorna o voto do usuário atual para uma pergunta específica.
   *
   * @return array{id: int, question_id: int, user_id: int, option_id: int, created: int}|null
   *   Dados do voto ou NULL se não existir.
   */
  public function getCurrentUserVote(int $questionId): ?array {
    $userId = (int) $this->currentUser->id();

    if ($userId <= 0) {
      return NULL;
    }

    $record = $this->database->select(self::TABLE_RESPONSES, 'r')
      ->fields('r')
      ->condition('question_id', $questionId)
      ->condition('user_id', $userId)
      ->execute()
      ->fetchAssoc();

    return $record ?: NULL;
  }

  /**
   * Registra um voto do usuário atual.
   *
   * @param int $questionId
   *   ID da pergunta.
   * @param string $optionId
   *   Índice da opção escolhida (0, 1, 2...).
   *
   * @return array{success: bool, status: int, message: string}
   *   Resultado da operação com código HTTP apropriado.
   */
  public function registerVote(int $questionId, string $optionId): array {
    // Validação de autenticação.
    if (!$this->currentUser->isAuthenticated()) {
      return $this->buildResult(FALSE, 403, 'Autenticação necessária para votar.');
    }

    // Carrega e valida a pergunta.
    $question = $this->entityTypeManager
      ->getStorage('vote_question')
      ->load($questionId);

    if (!$question instanceof VoteQuestion) {
      return $this->buildResult(FALSE, 404, 'Pergunta não encontrada.');
    }

    if (!$this->isVotingEnabled($question)) {
      return $this->buildResult(FALSE, 403, 'Votação desabilitada para esta pergunta.');
    }

    // Valida se a opção existe.
    $options = $question->getOptions();
    $validOptionIds = array_map('strval', array_keys($options));

    if (!in_array($optionId, $validOptionIds, TRUE)) {
      return $this->buildResult(FALSE, 400, 'Opção de voto inválida.');
    }

    // Verifica voto duplicado.
    if ($this->getCurrentUserVote($questionId) !== NULL) {
      return $this->buildResult(FALSE, 409, 'Você já votou nesta pergunta.');
    }

    // Persiste o voto.
    return $this->persistVote($questionId, (int) $optionId);
  }

  /**
   * Calcula os resultados agregados de uma pergunta.
   *
   * @return array{total_votes: int, options: array}
   *   Totais e percentuais por opção.
   */
  public function calculateResults(int $questionId): array {
    $question = $this->entityTypeManager
      ->getStorage('vote_question')
      ->load($questionId);

    if (!$question instanceof VoteQuestion) {
      return ['total_votes' => 0, 'options' => []];
    }

    // Inicializa estrutura com todas as opções.
    $options = $question->getOptions();
    $results = [];

    foreach ($options as $index => $option) {
      $imageFid = !empty($option['image_fid']) ? (int) $option['image_fid'] : NULL;

      $results[$index] = [
        'option_id' => $index,
        'id' => $option['id'] ?? NULL,
        'title' => $option['title'] ?? '',
        'description' => $option['description'] ?? '',
        'image_fid' => $imageFid,
        'image' => $this->buildImageData($imageFid),
        'votes' => 0,
        'percentage' => 0.0,
      ];
    }

    // Busca contagem de votos agrupada por opção.
    $query = $this->database->select(self::TABLE_RESPONSES, 'r')
      ->fields('r', ['option_id'])
      ->condition('question_id', $questionId)
      ->groupBy('option_id');

    $query->addExpression('COUNT(*)', 'vote_count');
    $rows = $query->execute()->fetchAll();

    $totalVotes = 0;

    foreach ($rows as $row) {
      $index = (int) $row->option_id;
      $count = (int) $row->vote_count;
      $totalVotes += $count;

      if (isset($results[$index])) {
        $results[$index]['votes'] = $count;
      }
    }

    // Calcula percentuais.
    if ($totalVotes > 0) {
      foreach ($results as $index => $data) {
        $results[$index]['percentage'] = round(($data['votes'] / $totalVotes) * 100, 2);
      }
    }

    return [
      'total_votes' => $totalVotes,
      'options' => array_values($results),
    ];
  }

  /**
   * Serializa uma pergunta para resposta da API.
   *
   * @param VoteQuestion $question
   *   Entidade a ser serializada.
   * @param bool $includeResults
   *   Se TRUE e a pergunta permitir, inclui resultados.
   *
   * @return array
   *   Dados estruturados para JSON.
   */
  public function serializeForApi(VoteQuestion $question, bool $includeResults = FALSE): array {
    $serializedOptions = [];

    foreach ($question->getOptions() as $index => $option) {
      $imageFid = !empty($option['image_fid']) ? (int) $option['image_fid'] : NULL;

      $serializedOptions[] = [
        'option_id' => $index,
        'id' => $option['id'] ?? NULL,
        'title' => $option['title'] ?? '',
        'description' => $option['description'] ?? '',
        'image_fid' => $imageFid,
        'image' => $this->buildImageData($imageFid),
      ];
    }

    $data = [
      'id' => (int) $question->id(),
      'identifier' => $question->getIdentifier(),
      'title' => (string) $question->label(),
      'question_text' => $question->getQuestionText(),
      'show_results' => $question->shouldShowResults(),
      'status' => $question->isPublished(),
      'options' => $serializedOptions,
      'voting_enabled' => $this->isVotingEnabled($question),
    ];

    if ($includeResults && $question->shouldShowResults()) {
      $data['results'] = $this->calculateResults((int) $question->id());
    }

    return $data;
  }

  /**
   * Persiste o voto no banco de dados.
   *
   * @return array{success: bool, status: int, message: string}
   */
  private function persistVote(int $questionId, int $optionId): array {
    try {
      $this->database->insert(self::TABLE_RESPONSES)
        ->fields([
          'question_id' => $questionId,
          'user_id' => (int) $this->currentUser->id(),
          'option_id' => $optionId,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();

      return $this->buildResult(TRUE, 201, 'Voto registrado com sucesso.');
    }
    catch (IntegrityConstraintViolationException) {
      // Constraint de unicidade violada - voto duplicado.
      $this->logger->warning('Tentativa de voto duplicado: uid=@uid, question=@qid', [
        '@uid' => $this->currentUser->id(),
        '@qid' => $questionId,
      ]);

      return $this->buildResult(FALSE, 409, 'Você já votou nesta pergunta.');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Erro ao registrar voto: @message', [
        '@message' => $exception->getMessage(),
      ]);

      return $this->buildResult(FALSE, 500, 'Erro interno ao processar o voto.');
    }
  }

  /**
   * Monta estrutura de dados de uma imagem a partir do FID.
   *
   * @return array{fid: int, url: string, filename: string}|null
   */
  private function buildImageData(?int $fid): ?array {
    if ($fid === NULL || $fid <= 0) {
      return NULL;
    }

    $file = File::load($fid);

    if ($file === NULL) {
      return NULL;
    }

    return [
      'fid' => $fid,
      'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'filename' => $file->getFilename(),
    ];
  }

  /**
   * Helper para construir arrays de resultado padronizados.
   *
   * @return array{success: bool, status: int, message: string}
   */
  private function buildResult(bool $success, int $status, string $message): array {
    return [
      'success' => $success,
      'status' => $status,
      'message' => $message,
    ];
  }

}
