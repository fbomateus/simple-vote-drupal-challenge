<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Entidade que representa uma pergunta de votação.
 *
 * Cada pergunta possui título, texto descritivo, opções de resposta
 * e configurações de exibição de resultados. As opções são armazenadas
 * em formato JSON para flexibilidade.
 *
 * @ContentEntityType(
 *   id = "vote_question",
 *   label = @Translation("Pergunta de votação"),
 *   label_collection = @Translation("Perguntas de votação"),
 *   label_singular = @Translation("pergunta de votação"),
 *   label_plural = @Translation("perguntas de votação"),
 *   handlers = {
 *     "list_builder" = "Drupal\simple_vote\Entity\VoteQuestionListBuilder",
 *     "form" = {
 *       "add" = "Drupal\simple_vote\Form\VoteQuestionForm",
 *       "edit" = "Drupal\simple_vote\Form\VoteQuestionForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "access" = "Drupal\simple_vote\Access\VoteQuestionAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "vote_question",
 *   admin_permission = "administer simple vote questions",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "uid" = "uid",
 *     "owner" = "uid",
 *     "published" = "status"
 *   },
 *   links = {
 *     "canonical" = "/admin/content/simple-vote/{vote_question}",
 *     "add-form" = "/admin/content/simple-vote/add",
 *     "edit-form" = "/admin/content/simple-vote/{vote_question}/edit",
 *     "delete-form" = "/admin/content/simple-vote/{vote_question}/delete",
 *     "collection" = "/admin/content/simple-vote"
 *   }
 * )
 */
final class VoteQuestion extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Garante que a entidade tenha um proprietário válido.
    if ($this->getOwnerId() === NULL) {
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('Identificador único da pergunta.'))
      ->setReadOnly(TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('Identificador universal único.'))
      ->setReadOnly(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Autor'))
      ->setDescription(t('Usuário que criou a pergunta.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Título'))
      ->setDescription(t('Título exibido na listagem e no cabeçalho da votação.'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['identifier'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Identificador'))
      ->setDescription(t('Slug único usado nas URLs e na API.'))
      ->setRequired(TRUE)
      ->addConstraint('UniqueField')
      ->setSettings(['max_length' => 128]);

    $fields['question_text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Texto da pergunta'))
      ->setDescription(t('Texto completo apresentado ao usuário durante a votação.'))
      ->setRequired(TRUE);

    $fields['show_results'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Exibir resultados'))
      ->setDescription(t('Define se os resultados ficam visíveis após o usuário votar.'))
      ->setDefaultValue(TRUE);

    $fields['options_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Opções (JSON)'))
      ->setDescription(t('Opções de resposta serializadas em formato JSON.'))
      ->setRequired(TRUE)
      ->setDefaultValue('[]');

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Publicada'))
      ->setDescription(t('Apenas perguntas publicadas aparecem para votação.'))
      ->setDefaultValue(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Criado em'))
      ->setDescription(t('Data de criação da pergunta.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Atualizado em'))
      ->setDescription(t('Data da última modificação.'));

    return $fields;
  }

  /**
   * Retorna as opções de resposta como array.
   *
   * @return array<int, array{id: string, title: string, description: string, image_fid: int|null, weight: int}>
   *   Lista de opções com seus atributos.
   */
  public function getOptions(): array {
    $raw = (string) $this->get('options_json')->value;
    $decoded = json_decode($raw, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Define as opções de resposta.
   *
   * @param array $options
   *   Array de opções a ser serializado.
   *
   * @return $this
   */
  public function setOptions(array $options): self {
    $encoded = json_encode(
      array_values($options),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $this->set('options_json', $encoded);

    return $this;
  }

  /**
   * Verifica se a pergunta está publicada.
   */
  public function isPublished(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * Verifica se os resultados devem ser exibidos.
   */
  public function shouldShowResults(): bool {
    return (bool) $this->get('show_results')->value;
  }

  /**
   * Retorna o identificador único (slug) da pergunta.
   */
  public function getIdentifier(): string {
    return (string) $this->get('identifier')->value;
  }

  /**
   * Retorna o texto descritivo da pergunta.
   */
  public function getQuestionText(): string {
    return (string) $this->get('question_text')->value;
  }

}
