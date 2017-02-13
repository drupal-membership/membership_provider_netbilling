<?php

namespace Drupal\membership_provider_netbilling\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
    $sendHashFields = [
      'Ecom_Ezic_AccountAndSitetag',
      'Ecom_Cost_Total',
      'Ecom_Receipt_Description',
    ];
    $form['Ecom_Ezic_AccountAndSitetag'] = [
      '#type' => 'hidden',
      '#value' => $config['account_id'] . ':' . $config['site_tag'],
    ];
    $form['Ecom_Cost_Total'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getBuildInfo()['price'],
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
    if (isset($form_state->getBuildInfo()['recurring'])) {
      $form['Ecom_Ezic_Recurring_Period'] = [
        '#type' => 'hidden',
        // Default - same day every month.
        '#value' => 'add_months((trunc(sysdate)+0.5),1)',
      ];
      $form['Ecom_Ezic_Recurring_Count'] = [
        '#type' => 'hidden',
        '#value' => '',
      ];
      $form['Ecom_Ezic_Recurring_Amount'] = [
        '#type' => 'hidden',
        '#value' => $form_state->getBuildInfo()['recurring'],
      ];
      $form['Ecom_Ezic_Membership_Period'] = [
        '#type' => 'hidden',
        '#value' => '30.00000',
      ];
      $sendHashFields += [
        'Ecom_Ezic_Recurring_Period',
        'Ecom_Ezic_Recurring_Count',
        'Ecom_Ezic_Recurring_Amount',
        'Ecom_Ezic_Membership_Period',
      ];
    }
    $form['Ecom_Ezic_Fulfillment_Module'] = [
      '#type' => 'hidden',
      '#value' => 'interactive2.2',
    ];

    // Store the hashed field selections in build info so they could be easily altered.
    $form_state->addBuildInfo('send_hash', $sendHashFields);
    // At the moment we don't appear able to ask for specific fields hashed on return.
    // This is set optimistically, but not used yet.
    $form_state->addBuildInfo('return_hash', [
      'Ecom_Ezic_AccountAndSitetag',
      'Ecom_Ezic_Response_TransactionID',
      'Ecom_Ezic_Response_StatusCode',
      'Ecom_Ezic_Membership_UserName',
      'Ecom_Ezic_Membership_PassWord',
      'Ecom_BillTo_Online_Email',
      'Ecom_Receipt_Description',
      'Ecom_Ezic_Membership_ID',
    ]);
    $sendHashValue = '';
    foreach ($form_state->getBuildInfo()['send_hash'] as $key) {
      $sendHashValue .= $form[$key]['#value'];
    }
    $form['Ecom_Ezic_Security_HashValue_MD5'] = [
      '#type' => 'hidden',
      '#value' => md5($config['integrity_key'] . $sendHashValue),
    ];
    // @todo - Per above, add in return fields to be hashed as well.
    $form['Ecom_Ezic_Security_HashFields'] = [
      '#type' => 'hidden',
      '#value' => implode(' ', $form_state->getBuildInfo()['send_hash']),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Join Now'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
