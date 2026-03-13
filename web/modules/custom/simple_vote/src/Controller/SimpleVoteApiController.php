<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_vote\Service\SimpleVoteManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller para endpoints REST do módulo Simple Vote.
 *
 * Expõe API para listagem de perguntas, detalhes, registro de votos
 * e consulta de resultados. Autenticação via Basic Auth.
 */
final class SimpleVoteApiController extends ControllerBase {

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
   * GET /api/simple-vote/questions
   *
   * Lista todas as perguntas publicadas.
   */
  public function listQuestions(): JsonResponse {
    $questions = $this->voteManager->loadPublishedQuestions();

    $payload = array_map(
      fn($question) => $this->voteManager->serializeForApi($question, FALSE),
      $questions
    );

    return $this->jsonSuccess($payload);
  }

  /**
   * GET /api/simple-vote/questions/{identifier}
   *
   * Retorna detalhes de uma pergunta específica.
   */
  public function getQuestion(string $identifier): JsonResponse {
    $question = $this->voteManager->loadQuestionByIdentifier($identifier);

    if ($question === NULL) {
      return $this->jsonError('Pergunta não encontrada.', Response::HTTP_NOT_FOUND);
    }

    $data = $this->voteManager->serializeForApi($question, TRUE);

    return $this->jsonSuccess($data);
  }

  /**
   * POST /api/simple-vote/questions/{identifier}/vote
   *
   * Registra um voto na pergunta especificada.
   */
  public function submitVote(Request $request, string $identifier): JsonResponse {
    $question = $this->voteManager->loadQuestionByIdentifier($identifier);

    if ($question === NULL) {
      return $this->jsonError('Pergunta não encontrada.', Response::HTTP_NOT_FOUND);
    }

    $payload = $this->parseJsonBody($request);
    $optionId = trim((string) ($payload['option_id'] ?? ''));

    if ($optionId === '') {
      return $this->jsonError('Campo option_id é obrigatório.', Response::HTTP_BAD_REQUEST);
    }

    $result = $this->voteManager->registerVote((int) $question->id(), $optionId);

    $statusCode = $result['success'] ? Response::HTTP_CREATED : $result['status'];

    return new JsonResponse(['message' => $result['message']], $statusCode);
  }

  /**
   * GET /api/simple-vote/questions/{identifier}/results
   *
   * Retorna os resultados de votação de uma pergunta.
   */
  public function getResults(string $identifier): JsonResponse {
    $question = $this->voteManager->loadQuestionByIdentifier($identifier);

    if ($question === NULL) {
      return $this->jsonError('Pergunta não encontrada.', Response::HTTP_NOT_FOUND);
    }

    // Verifica permissão para visualizar resultados.
    $canViewResults = $question->shouldShowResults()
      || $this->currentUser()->hasPermission('view simple vote results');

    if (!$canViewResults) {
      return $this->jsonError(
        'Resultados não disponíveis para esta pergunta.',
        Response::HTTP_FORBIDDEN
      );
    }

    $results = $this->voteManager->calculateResults((int) $question->id());

    return $this->jsonSuccess($results);
  }

  /**
   * Monta resposta JSON de sucesso.
   */
  private function jsonSuccess(mixed $data): JsonResponse {
    return new JsonResponse(['data' => $data]);
  }

  /**
   * Monta resposta JSON de erro.
   */
  private function jsonError(string $message, int $statusCode): JsonResponse {
    return new JsonResponse(['message' => $message], $statusCode);
  }

  /**
   * Extrai e decodifica o corpo JSON da requisição.
   */
  private function parseJsonBody(Request $request): array {
    $content = (string) $request->getContent();
    $decoded = json_decode($content, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

}
