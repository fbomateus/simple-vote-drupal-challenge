<?php

declare(strict_types=1);

namespace Drupal\simple_vote\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulário de criação e edição de perguntas de votação.
 *
 * Permite configurar título, identificador, texto da pergunta,
 * opções de resposta com imagens, e configurações de publicação.
 */
final class VoteQuestionForm extends ContentEntityForm {

  private const MIN_OPTIONS = 2;
  private const MAX_IMAGE_SIZE = 5 * 1024 * 1024;
  private const ALLOWED_EXTENSIONS = 'png jpg jpeg webp';

  public function __construct(
    EntityRepositoryInterface $entityRepository,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    TimeInterface $time,
    private readonly UuidInterface $uuid,
  ) {
    parent::__construct($entityRepository, $entityTypeBundleInfo, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('uuid'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\simple_vote\Entity\VoteQuestion $entity */
    $entity = $this->entity;
    $form = parent::buildForm($form, $form_state);

    // Campos básicos.
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#description' => $this->t('Título exibido na listagem e no cabeçalho da votação.'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['identifier'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Identificador'),
      '#description' => $this->t('Slug único usado nas URLs e na API. Não pode ser alterado após a criação.'),
      '#default_value' => $entity->get('identifier')->value,
      '#required' => TRUE,
      '#machine_name' => [
        'exists' => [static::class, 'identifierExists'],
        'source' => ['title'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['question_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texto da pergunta'),
      '#description' => $this->t('Texto completo apresentado ao usuário durante a votação.'),
      '#default_value' => $entity->get('question_text')->value,
      '#required' => TRUE,
      '#rows' => 4,
    ];

    // Seção de opções de resposta.
    $form['options_wrapper'] = $this->buildOptionsSection($form_state, $entity);

    // Configurações de publicação.
    $form['publishing'] = [
      '#type' => 'details',
      '#title' => $this->t('Publicação'),
      '#open' => TRUE,
    ];

    $form['publishing']['show_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exibir resultados após o voto'),
      '#description' => $this->t('Permite que usuários vejam os resultados após votar.'),
      '#default_value' => (bool) $entity->get('show_results')->value,
    ];

    $form['publishing']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publicada'),
      '#description' => $this->t('Apenas perguntas publicadas aparecem para votação.'),
      '#default_value' => (bool) $entity->get('status')->value,
    ];

    return $form;
  }

  /**
   * Verifica se um identificador já existe.
   */
  public static function identifierExists(string $value): bool {
    $storage = \Drupal::entityTypeManager()->getStorage('vote_question');
    $results = $storage->loadByProperties(['identifier' => $value]);

    return !empty($results);
  }

  /**
   * Callback AJAX para atualizar a seção de opções.
   */
  public function ajaxRefreshOptions(array &$form, FormStateInterface $form_state): array {
    return $form['options_wrapper'];
  }

  /**
   * Submit handler para adicionar nova opção.
   */
  public function addOptionSubmit(array &$form, FormStateInterface $form_state): void {
    $options = $this->getOptionsFromFormState($form_state);

    $options[] = [
      'id' => 'option-' . $this->uuid->generate(),
      'title' => '',
      'description' => '',
      'image_fid' => [],
      'weight' => count($options),
    ];

    $form_state->set('vote_options', $options);
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $submittedOptions = $form_state->getValue(['options_wrapper', 'options']) ?? [];
    $validCount = 0;

    foreach ($submittedOptions as $delta => $option) {
      $title = trim((string) ($option['title'] ?? ''));

      if ($title === '') {
        $form_state->setError(
          $form['options_wrapper']['options'][$delta]['title'],
          $this->t('O título da opção é obrigatório.')
        );
        continue;
      }

      $validCount++;
    }

    if ($validCount < self::MIN_OPTIONS) {
      $form_state->setErrorByName(
        'options_wrapper',
        $this->t('A pergunta deve ter no mínimo @count opções.', ['@count' => self::MIN_OPTIONS])
      );
    }

    $form_state->set('vote_options', $submittedOptions);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\simple_vote\Entity\VoteQuestion $entity */
    $entity = $this->entity;

    // Atualiza campos básicos.
    $entity->set('title', trim((string) $form_state->getValue('title')));
    $entity->set('identifier', trim((string) $form_state->getValue('identifier')));
    $entity->set('question_text', trim((string) $form_state->getValue('question_text')));
    $entity->set('show_results', (bool) $form_state->getValue('show_results'));
    $entity->set('status', (bool) $form_state->getValue('status'));

    // Processa e normaliza as opções.
    $options = $this->normalizeOptions($form_state);
    $entity->setOptions($options);

    $status = $entity->save();

    $message = $status === SAVED_NEW
      ? $this->t('Pergunta criada com sucesso.')
      : $this->t('Pergunta atualizada com sucesso.');

    $this->messenger()->addStatus($message);
    $form_state->setRedirect('entity.vote_question.collection');

    return $status;
  }

  /**
   * Monta a seção de opções de resposta do formulário.
   */
  private function buildOptionsSection(FormStateInterface $form_state, $entity): array {
    $options = $this->getOptionsFromFormState($form_state);

    // Se não há opções no form_state, carrega da entidade ou cria padrão.
    if (empty($options)) {
      $options = $entity->isNew()
        ? $this->getDefaultOptions()
        : $entity->getOptions();

      $form_state->set('vote_options', $options);
    }

    $section = [
      '#type' => 'fieldset',
      '#title' => $this->t('Opções de resposta'),
      '#prefix' => '<div id="vote-options-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    foreach ($options as $delta => $option) {
      $section['options'][$delta] = $this->buildOptionFields($delta, $option);
    }

    $section['add_option'] = [
      '#type' => 'submit',
      '#value' => $this->t('Adicionar opção'),
      '#submit' => ['::addOptionSubmit'],
      '#ajax' => [
        'callback' => '::ajaxRefreshOptions',
        'wrapper' => 'vote-options-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    return $section;
  }

  /**
   * Monta os campos de uma opção individual.
   */
  private function buildOptionFields(int $delta, array $option): array {
    $fields = [
      '#type' => 'details',
      '#title' => $this->t('Opção @number', ['@number' => $delta + 1]),
      '#open' => TRUE,
    ];

    $fields['id'] = [
      '#type' => 'hidden',
      '#value' => $option['id'] ?? ('option-' . $this->uuid->generate()),
    ];

    $fields['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#default_value' => $option['title'] ?? '',
      '#required' => TRUE,
    ];

    $fields['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Descrição'),
      '#description' => $this->t('Texto adicional para contextualizar a opção (opcional).'),
      '#default_value' => $option['description'] ?? '',
      '#rows' => 2,
    ];

    $defaultFid = !empty($option['image_fid']) ? [(int) $option['image_fid']] : [];
    $maxSizeLabel = (self::MAX_IMAGE_SIZE / (1024 * 1024)) . ' MB';

    $fields['image_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagem'),
      '#description' => $this->t('Formatos: PNG, JPG, JPEG, WEBP. Tamanho máximo: @size.', ['@size' => $maxSizeLabel]),
      '#upload_location' => 'public://simple-vote/',
      '#default_value' => $defaultFid,
      '#upload_validators' => [
        'FileExtension' => ['extensions' => self::ALLOWED_EXTENSIONS],
        'FileSizeLimit' => ['fileLimit' => self::MAX_IMAGE_SIZE],
        'FileIsImage' => [],
      ],
    ];

    $fields['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Ordem'),
      '#default_value' => (int) ($option['weight'] ?? $delta),
    ];

    return $fields;
  }

  /**
   * Retorna opções padrão para nova pergunta.
   */
  private function getDefaultOptions(): array {
    return [
      [
        'id' => 'option-' . $this->uuid->generate(),
        'title' => '',
        'description' => '',
        'image_fid' => [],
        'weight' => 0,
      ],
      [
        'id' => 'option-' . $this->uuid->generate(),
        'title' => '',
        'description' => '',
        'image_fid' => [],
        'weight' => 1,
      ],
    ];
  }

  /**
   * Recupera opções do form_state.
   */
  private function getOptionsFromFormState(FormStateInterface $form_state): array {
    return $form_state->get('vote_options') ?? [];
  }

  /**
   * Normaliza e ordena as opções para persistência.
   */
  private function normalizeOptions(FormStateInterface $form_state): array {
    $submitted = $form_state->getValue(['options_wrapper', 'options']) ?? [];

    // Ordena por peso.
    usort($submitted, fn(array $a, array $b): int =>
      (int) ($a['weight'] ?? 0) <=> (int) ($b['weight'] ?? 0)
    );

    $normalized = [];

    foreach ($submitted as $option) {
      $fid = $this->extractFileId($option['image_fid'] ?? []);

      // Marca arquivo como permanente.
      if ($fid !== NULL) {
        $this->makeFilePermanent($fid);
      }

      $normalized[] = [
        'id' => (string) ($option['id'] ?? ('option-' . $this->uuid->generate())),
        'title' => trim((string) ($option['title'] ?? '')),
        'description' => trim((string) ($option['description'] ?? '')),
        'image_fid' => $fid,
        'weight' => (int) ($option['weight'] ?? 0),
      ];
    }

    return $normalized;
  }

  /**
   * Extrai o ID do arquivo do valor do campo managed_file.
   */
  private function extractFileId(mixed $value): ?int {
    if (is_array($value) && !empty($value[0])) {
      return (int) $value[0];
    }

    if (is_numeric($value) && $value > 0) {
      return (int) $value;
    }

    return NULL;
  }

  /**
   * Marca um arquivo como permanente.
   */
  private function makeFilePermanent(int $fid): void {
    $file = File::load($fid);

    if ($file !== NULL && $file->isTemporary()) {
      $file->setPermanent();
      $file->save();
    }
  }

}
