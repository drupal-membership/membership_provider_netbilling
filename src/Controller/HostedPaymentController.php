<?php

namespace Drupal\membership_provider_netbilling\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Controller\ControllerBase;
use Drupal\membership_provider_netbilling\SiteResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Class HostedPaymentController.
 *
 * @package Drupal\membership_provider_netbilling\Controller
 */
class HostedPaymentController extends ControllerBase {

  /**
   * The request.
   *
   * @var null|\Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * The site resolver.
   *
   * @var \Drupal\membership_provider_netbilling\SiteResolver
   */
  protected $siteResolver;

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('membership_provider_netbilling.site_resolver')
    );
  }

  /**
   * @inheritDoc
   */
  public function __construct(RequestStack $requestStack, SiteResolver $siteResolver) {
    $this->currentRequest = $requestStack->getCurrentRequest();
    $this->siteResolver = $siteResolver;
  }

  /**
   * Process the return value from the hosted payment page.
   *
   * In order to have the customer directly sent back here, disable the receipt page
   * on the payment page config inside the Netbilling admin panel.
   *
   * @throws AccessDeniedHttpException
   */
  public function process() {
    $query = $this->currentRequest->query->all();
    $hashfields = explode(' ', $query['Ecom_Ezic_Security_HashFields']);
    list(,$site_tag) = explode(':', $query['Ecom_Ezic_AccountAndSitetag']);
    $siteConfig = $this->siteResolver->getSiteConfig($site_tag);
    // @see https://secure.netbilling.com/public/docs/merchant/public/nativespecs/ezicPaymentForm22.html
    // The integrity key is found on the Fraud Defense settings page.
    $toHash = $siteConfig['integrity_key'] . $query['Ecom_Ezic_Response_TransactionID'] . $query['Ecom_Ezic_Response_StatusCode'];
    foreach ($hashfields as $field) {
      $toHash .= $query[$field];
    }
    $target = strtoupper(md5($toHash));
    if ($query['Ecom_Ezic_ProofOfPurchase_MD5'] != $target) {
      throw new AccessDeniedHttpException();
    }
    // @todo - Response.
  }

}
