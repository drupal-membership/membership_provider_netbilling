<?php

namespace Drupal\membership_provider_netbilling\Element;

use Drupal\Core\Render\Element\RenderElement;

/**
 * Class ReceiptElement
 * @package Drupal\membership_provider_netbilling
 *
 * @RenderElement("membership_provider_netbilling_receipt")
 */
class ReceiptElement extends RenderElement {

  /**
   * @inheritDoc
   */
  public function getInfo() {
    return [
      '#theme' => 'membership_provider_netbilling_receipt',
      '#label' => $this->t('Receipt'),
      '#description' => $this->t('Purchase receipt'),
      '#pre_render' => [
        [get_class($this), 'preRender'],
      ],
    ];
  }

  public static function preRender($element) {
    $element['id'] = [
      '#markup' => $element['#data']['Ecom_Ezic_Response_TransactionID'],
    ];
    return $element;
  }
}
