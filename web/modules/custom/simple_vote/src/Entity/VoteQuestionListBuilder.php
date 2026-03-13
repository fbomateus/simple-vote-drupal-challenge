<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Entity;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Monta a listagem administrativa de perguntas de votação.
 *
 * Exibe tabela com ID, título, identificador, status e data de criação,
 * permitindo acesso rápido às ações de edição.
 */
final class VoteQuestionListBuilder extends EntityListBuilder {

  /**
   * Construtor.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   Definição do tipo de entidade.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Storage responsável pela persistência.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Serviço de formatação de datas.
   */
  public function __construct(
    EntityTypeInterface $entityType,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entityType, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entityType): static {
    return new static(
      $entityType,
      $container->get('entity_type.manager')->getStorage($entityType->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'id' => $this->t('ID'),
      'title' => $this->t('Título'),
      'identifier' => $this->t('Identificador'),
      'status' => $this->t('Status'),
      'created' => $this->t('Criado em'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\simple_vote\Entity\VoteQuestion $entity */
    $createdTimestamp = (int) $entity->get('created')->value;

    $row['id'] = $entity->id();

    $row['title'] = Link::createFromRoute(
      $entity->label() ?: $this->t('(sem título)'),
      'entity.vote_question.edit_form',
      ['vote_question' => $entity->id()]
    );

    $row['identifier'] = $entity->getIdentifier() ?: '-';

    $row['status'] = $entity->isPublished()
      ? $this->t('Publicado')
      : $this->t('Despublicado');

    $row['created'] = $createdTimestamp > 0
      ? $this->dateFormatter->format($createdTimestamp, 'short')
      : '-';

    return $row + parent::buildRow($entity);
  }

}
