<?php

namespace Drupal\membership_provider_netbilling\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\membership_provider_netbilling\SiteResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class BuyButtonForm.
 *
 * @package Drupal\membership_provider_netbilling\Form
 */
class BuyButtonForm extends FormBase {

  /**
   * The action URL.
   */
  const ACTION = 'https://secure.netbilling.com/gw/native/join2.2b';

  /**
   * Site Resolver service.
   *
   * @var \Drupal\membership_provider_netbilling\SiteResolver
   */
  protected $siteResolver;

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('membership_provider_netbilling.site_resolver')
    );
  }

  /**
   * @inheritDoc
   */
  public function __construct(SiteResolver $siteResolver) {
    $this->siteResolver = $siteResolver;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'membership_provider_netbilling_buy_button';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var EntityInterface $entity */
    $entity = $form_state->getBuildInfo()['entity'];
    $config = $this->siteResolver->getSiteConfigByEntity($entity);
    $form['#action'] = self::ACTION;
    $form['#method'] = 'GET';
    $form['Ecom_Ezic_AccountAndSitetag'] = [
      '#type' => 'hidden',
      '#value' => $config['account_id'] . ':' . $config['site_tag'],
    ];
    $form['Ecom_Cost_Total'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getBuildInfo()['price'],
    ];
    $form['Ecom_Ezic_AccountAndSitetag'] = [
      '#type' => 'hidden',
      '#value' => $config['account_id'] . ':' . $config['site_tag'],
    ];
    $form['Ecom_Receipt_Description'] = [
      '#type' => 'hidden',
      '#value' => $entity->label(),
    ];
    $form['Ecom_Ezic_Fulfillment_ReturnMethod'] = [
      '#type' => 'hidden',
      '#value' => 'GET',
    ];
    $form['Ecom_Ezic_Fulfillment_ReturnURL'] = [
      '#type' => 'hidden',
      '#value' => Url::fromRoute('membership_provider_netbilling.hosted_payment_controller_process')->setAbsolute()->toString(),
    ];
    $form['Ecom_Ezic_Membership_Period'] = [
      '#type' => 'hidden',
      '#value' => 30.0,
    ];
    $hashFields = implode(' ', Element::children($form));
    // @todo - See if we can set additional return fields to hash.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
