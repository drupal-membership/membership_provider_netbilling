<?php

namespace Drupal\membership_provider_netbilling\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'netbilling_site_config_widget' widget.
 *
 * @FieldWidget(
 *   id = "netbilling_site_config",
 *   label = @Translation("NETBilling site config widget"),
 *   field_types = {
 *     "netbilling_site_config"
 *   }
 * )
 */
class NetbillingSiteConfigWidget extends WidgetBase {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'size' => 60,
      'placeholder' => '',
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = [];

    $elements['size'] = array(
      '#type' => 'number',
      '#title' => t('Size of textfield'),
      '#default_value' => $this->getSetting('size'),
      '#required' => TRUE,
      '#min' => 1,
    );
    $elements['placeholder'] = array(
      '#type' => 'textfield',
      '#title' => t('Placeholder'),
      '#default_value' => $this->getSetting('placeholder'),
      '#description' => t('Text that will be shown inside the field until a value is entered. This hint is usually a sample value or a brief description of the expected format.'),
    );

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $summary[] = t('Textfield size: !size', array('!size' => $this->getSetting('size')));
    if (!empty($this->getSetting('placeholder'))) {
      $summary[] = t('Placeholder: @placeholder', array('@placeholder' => $this->getSetting('placeholder')));
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['#type'] = 'fieldset';
    $properties = $this->fieldDefinition->getFieldStorageDefinition()->getPropertyDefinitions();
    // This loop is only appropriate since we know the fields all have the same type/config.
    foreach ($properties as $key => $item) {
      $element[$key] = [
        '#type' => 'textfield',
        '#title' => $item->getLabel(),
        '#default_value' => isset($items[$delta]->{$key}) ? $items[$delta]->{$key} : NULL,
        '#size' => $this->getSetting('size'),
      ];
    }
    return $element;
  }

}
