<?php

namespace Drupal\membership_provider_netbilling\Plugin\Field\FieldFormatter;

use Drupal\commerce_price\NumberFormatterFactoryInterface;
use Drupal\commerce_price\Plugin\Field\FieldFormatter\PriceDefaultFormatter;
use Drupal\commerce_price\Plugin\Field\FieldType\PriceItem;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
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
    // We don't know how to handle more than two values for price, just show them.
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
    $delta = 0;
    foreach ($items as $delta => $item) {
      /** @var PriceItem $item */
      $currency = $currencies[$item->currency_code];
      $price = $this->numberFormatter->formatCurrency($item->number, $currency);
      $label = '';
      $rawPrice = $this->rounder->round($item->toPrice())->getNumber();
      // Always add a price.
      $formState->addBuildInfo('price', $rawPrice);
      // Two values equate to recurring billing.
      if (count($items) > 1 && !$item->toPrice()->isZero()) {
        $label = $delta === 0 ? $this->t('Initial') : $this->t('Recurring');
        if ($delta === 1) {
          $formState->addBuildInfo('recurring', $rawPrice);
        }
        $label .= ': ';
      }
      if (!$item->toPrice()->isZero()) {
        $elements[$delta] = [
          '#prefix' => $label,
          '#markup' => $price,
          '#cache' => [
            'contexts' => [
              'languages:' . LanguageInterface::TYPE_INTERFACE,
              'country',
            ],
          ],
        ];
      }
    }
    if (count($elements) === 1) {
      unset($elements[0]['#prefix']);
    }
    // Return empty set if there was no price specified; nothing to sell.
    if (!$elements) {
      return $elements;
    }

    $button = $this->formBuilder->buildForm('Drupal\membership_provider_netbilling\Form\BuyButtonForm', $formState);
    if ($this->getSetting('only_buy_button')) {
      $elements = [
        ['button' => $button],
      ];
    }
    else {
      $elements[$delta + 1]['button'] = $button;
    }
    return $elements;
  }

  /**
   * @inheritDoc
   */
  public static function defaultSettings() {
    return [
      'only_buy_button' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * @inheritDoc
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $button = [
      'only_buy_button' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show only the buy button, not the price values.'),
        '#default_value' => $this->getSetting('only_buy_button'),
      ],
    ];
    $upstream = parent::settingsForm($form, $form_state);
    $upstream['strip_trailing_zeroes']['#states'] = $upstream['display_currency_code']['#states'] = [
      'disabled' => [
        ':input[name="fields[' . $form_state->getTriggeringElement()['#field_name'] . '][settings_edit_form][settings][only_buy_button]"]' => ['checked' => TRUE],
      ],
    ];
    return $button + $upstream;
  }

}
