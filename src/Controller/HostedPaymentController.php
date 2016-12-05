<?php

namespace Drupal\membership_provider_netbilling\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\QueryArgsCacheContext;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\HtmlResponse;
use Drupal\membership_provider_netbilling\NetbillingEvent;
use Drupal\membership_provider_netbilling\NetbillingEvents;
use Drupal\membership_provider_netbilling\SiteResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
   * The site config.
   *
   * @var array
   */
  protected $siteConfig;

  /**
   * The site resolver.
   *
   * @var \Drupal\membership_provider_netbilling\SiteResolver
   */
  protected $siteResolver;

  /**
   * The query array.
   *
   * @var array
   */
  protected $query = [];

  /**
   * Query args cache context service.
   *
   * @var \Drupal\Core\Cache\Context\QueryArgsCacheContext
   */
  protected $queryArgs;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('membership_provider_netbilling.site_resolver'),
      $container->get('cache_context.url.query_args'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * @inheritDoc
   */
  public function __construct(RequestStack $requestStack, SiteResolver $siteResolver, QueryArgsCacheContext $queryArgs, EventDispatcherInterface $eventDispatcher) {
    $this->currentRequest = $requestStack->getCurrentRequest();
    $this->query = $this->currentRequest->query->all();
    $this->siteResolver = $siteResolver;
    $this->queryArgs = $queryArgs;
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * Validate the current request.
   *
   * In part this assumes we'll find the various required array keys; it will
   * fail if they're not found, regardless. Consider using a getter.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  protected function validateQuery() {
    $query = $this->query;
    $hashfields = explode(' ', $query['Ecom_Ezic_Security_HashFields']);
    list(,$site_tag) = explode(':', $query['Ecom_Ezic_AccountAndSitetag']);
    $siteConfig = $this->siteResolver->getSiteConfig($site_tag);
    $this->siteConfig = $siteConfig;
    // @see https://secure.netbilling.com/public/docs/merchant/public/nativespecs/ezicPaymentForm22.html
    // The integrity key is found on the Fraud Defense settings page.
    $toHash = $siteConfig['integrity_key'] . $query['Ecom_Ezic_Response_TransactionID'] . $query['Ecom_Ezic_Response_StatusCode'];
    foreach ($hashfields as $field) {
      if (!isset($query[$field])) {
        continue;
      }
      $toHash .= $query[$field];
    }
    $target = strtoupper(md5($toHash));
    if ($query['Ecom_Ezic_ProofOfPurchase_MD5'] != $target) {
      throw new AccessDeniedHttpException('Invalid request.');
    }
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
    try {
      $this->validateQuery();
    }
    catch (\Exception $e) {
      $metadata = (new CacheableMetadata())->setCacheContexts(['url.query_args:Ecom_Ezic_ProofOfPurchase_MD5']);
      return (new HtmlResponse($e->getMessage(), $e->getCode()))
        ->addCacheableDependency($metadata);
    }
    $event = new NetbillingEvent($this->siteConfig, [], $this->query);
    $this->eventDispatcher->dispatch(NetbillingEvents::HOSTED_RETURN, $event);
    $return = [
      'receipt' => [
        '#type' => 'membership_provider_netbilling_receipt',
        '#data' => $this->query,
        '#cache' => [
          'contexts' => ['url.query_args:Ecom_Ezic_Response_TransactionID'],
        ],
      ],
    ];
    return $return;
  }

}
