<?php

namespace Drupal\membership_provider_netbilling\Plugin\Field\FieldFormatter;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
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
use Drupal\membership_provider_netbilling\Form\BuyButtonForm;
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
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, CurrencyFormatterInterface $currencyFormatter, FormBuilderInterface $formBuilder, RounderInterface $rounder) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $currencyFormatter);
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
      $container->get('commerce_price.currency_formatter'),
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
    $formState = (new FormState())
      ->addBuildInfo('entity', $items->getEntity());
    $priceElements = [];
    foreach ($items as $delta => $item) {
      /** @var \Drupal\commerce_price\Plugin\Field\FieldType\PriceItem $item */
      $roundedPrice = $this->rounder->round($item->toPrice());
      $label = '';
      // Always add a price.
      if ($delta == 0) {
        $formState->addBuildInfo('price', $roundedPrice->getNumber());
      }
      // Two values equate to recurring billing.
      if (count($items) > 1 && !$item->toPrice()->isZero()) {
        $label = $delta === 0 ? $this->t('Initial') : $this->t('Recurring');
        if ($delta === 1) {
          $formState->addBuildInfo('recurring', $roundedPrice->getNumber());
        }
        $label .= ': ';
      }
      if (!$item->toPrice()->isZero()) {
        $priceElements[$delta] = [
          '#prefix' => '<span class="price-element">',
          '#suffix' => '</span>',
          '#markup' => $label . $this->currencyFormatter
            ->format($roundedPrice->getNumber(), $roundedPrice->getCurrencyCode()),
          '#cache' => [
            'contexts' => [
              'languages:' . LanguageInterface::TYPE_INTERFACE,
              'country',
            ],
          ],
        ];
      }
    }
    if (count($priceElements) === 1) {
      unset($priceElements[0]['#prefix']);
    }
    // Return empty set if there was no price specified; nothing to sell.
    if (!$priceElements) {
      return $priceElements;
    }

    $button = $this->formBuilder->buildForm(BuyButtonForm::class, $formState);
    if ($this->getSetting('only_buy_button')) {
      return [
        ['button' => $button],
      ];
    }
    else {
      $priceElements[] = $button;
    }
    return [0 => $priceElements];
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
