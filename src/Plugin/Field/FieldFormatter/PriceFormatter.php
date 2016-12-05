<?php

namespace Drupal\membership_provider_netbilling\Plugin\Field\FieldFormatter;

use Drupal\commerce_price\NumberFormatterFactoryInterface;
use Drupal\commerce_price\Plugin\Field\FieldFormatter\PriceDefaultFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'membership_provider_netbilling_price' formatter.
 *
 * @FieldFormatter(
 *   id = "membership_provider_netbilling_price",
 *   label = @Translation("Netbilling Buy Button"),
 *   field_types = {
 *     "commerce_price"
 *   }
 * )
 */
class PriceFormatter extends PriceDefaultFormatter {

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Rounder interface.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * @inheritDoc
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, NumberFormatterFactoryInterface $number_formatter_factory, FormBuilderInterface $formBuilder, RounderInterface $rounder) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $entity_type_manager, $number_formatter_factory);
    $this->formBuilder = $formBuilder;
    $this->rounder = $rounder;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('commerce_price.number_formatter_factory'),
      $container->get('form_builder'),
      $container->get('commerce_price.rounder')
    );
  }

  /**
   * @inheritDoc
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    if (count($items) > 2) {
      return parent::viewElements($items, $langcode);
    }
    $currency_codes = [];
    foreach ($items as $delta => $item) {
      $currency_codes[] = $item->currency_code;
    }
    $currencies = $this->currencyStorage->loadMultiple($currency_codes);

    $formState = (new FormState())
      ->addBuildInfo('entity', $items->getEntity());
    $elements = [];
    foreach ($items as $delta => $item) {
      $currency = $currencies[$item->currency_code];
      $price = $this->numberFormatter->formatCurrency($item->number, $currency);
      $label = '';
      if (count($items) > 1) {
        $label = $delta === 0 ? $this->t('Initial') : $this->t('Recurring');
        $rawPrice = $this->rounder->round($item->toPrice())->getNumber();
        if ($delta === 0) {
          $formState->addBuildInfo('price', $rawPrice);
        }
        else {
          $formState->addBuildInfo('recurring', $rawPrice);
        }
        $label .= ': ';
      }
      $elements[$delta] = [
        '#markup' => $label . $price,
        '#cache' => [
          'contexts' => [
            'languages:' . LanguageInterface::TYPE_INTERFACE,
            'country',
          ],
        ],
      ];
    }

    if ($items) {
      $elements[$delta]['button'] = $this->formBuilder->buildForm('Drupal\membership_provider_netbilling\Form\BuyButtonForm', $formState);
    }
    return $elements;
  }

}
