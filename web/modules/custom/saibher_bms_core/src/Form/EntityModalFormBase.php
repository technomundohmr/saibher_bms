<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Small, design-system-friendly form for creating an operational node.
 */
abstract class EntityModalFormBase extends FormBase {

  /**
   * The node bundle created by this form.
   */
  protected const BUNDLE = '';

  /**
   * Form field definitions.
   *
   * Each definition contains a Drupal field name, label, type and options.
   */
  protected const FIELDS = [];

  public function getFormId(): string {
    return strtolower(str_replace('\\', '_', static::class));
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'saibher-modal-form';

    foreach (static::FIELDS as $name => $definition) {
      $element = [
        '#type' => $definition['type'] ?? 'textfield',
        '#title' => $definition['label'],
        '#required' => $definition['required'] ?? false,
        '#attributes' => [
          'class' => ['saibher-modal-form__control'],
        ],
      ];
      if (isset($definition['placeholder'])) {
        $element['#attributes']['placeholder'] = $definition['placeholder'];
      }
      if (isset($definition['description'])) {
        $element['#description'] = $definition['description'];
      }
      if (isset($definition['options'])) {
        $element['#options'] = $definition['options'];
      }
      $form[$name] = $element;
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['saibher-modal-form__actions']],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancelar'),
      '#attributes' => [
        'class' => ['saibher-btn', 'saibher-btn--secondary'],
        'data-dialog-close' => '1',
      ],
      '#limit_validation_errors' => [],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->submitLabel(),
      '#attributes' => ['class' => ['saibher-btn', 'saibher-btn--primary']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (static::FIELDS as $name => $definition) {
      if (!empty($definition['required']) && trim((string) $form_state->getValue($name)) === '') {
        $form_state->setErrorByName($name, $this->t('Este campo es obligatorio.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = Node::create([
      'type' => static::BUNDLE,
      'title' => $form_state->getValue('title'),
      'uid' => $this->currentUser()->id(),
      'status' => 1,
    ]);

    foreach (static::FIELDS as $name => $definition) {
      if ($name === 'title') {
        continue;
      }
      $value = $form_state->getValue($name);
      if ($value === NULL || $value === '') {
        continue;
      }
      $node->set($name, [['value' => $value]]);
    }

    $node->save();
    $this->messenger()->addStatus($this->t('@label creado correctamente.', ['@label' => $this->entityLabel()]));
    $form_state->setRedirect('<current>');
  }

  protected function submitLabel(): string {
    return (string) $this->t('Guardar');
  }

  protected function entityLabel(): string {
    return (string) $this->t('Registro');
  }

}

