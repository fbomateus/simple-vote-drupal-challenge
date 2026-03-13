<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_vote\Entity\VoteQuestion;

/**
 * Controle de acesso para a entidade VoteQuestion.
 *
 * Regras aplicadas:
 * - Visualização: permitida se a pergunta estiver publicada e o usuário
 *   tiver permissão 'access simple vote', ou se for administrador.
 * - Edição/Exclusão: restritas a administradores.
 * - Criação: restrita a administradores.
 */
final class VoteQuestionAccessControlHandler extends EntityAccessControlHandler {

  private const PERMISSION_ADMIN = 'administer simple vote questions';
  private const PERMISSION_ACCESS = 'access simple vote';

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    /** @var \Drupal\simple_vote\Entity\VoteQuestion $entity */
    return match ($operation) {
      'view' => $this->checkViewAccess($entity, $account),
      'update', 'delete' => AccessResult::allowedIfHasPermission($account, self::PERMISSION_ADMIN),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, self::PERMISSION_ADMIN);
  }

  /**
   * Verifica permissão de visualização considerando status da pergunta.
   */
  private function checkViewAccess(VoteQuestion $question, AccountInterface $account): AccessResultInterface {
    // Administradores sempre podem visualizar.
    if ($account->hasPermission(self::PERMISSION_ADMIN)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Usuários comuns só visualizam perguntas publicadas.
    $isPublished = $question->isPublished();
    $hasAccess = $account->hasPermission(self::PERMISSION_ACCESS);

    return AccessResult::allowedIf($isPublished && $hasAccess)
      ->addCacheableDependency($question)
      ->cachePerPermissions();
  }

}
