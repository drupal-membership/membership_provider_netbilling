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
