<?php

namespace Drupal\membership_provider_netbilling\Plugin\MembershipProvider;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\membership\MembershipInterface;
use Drupal\membership_provider\Plugin\MembershipProviderBase;
use Drupal\membership_provider\Plugin\MembershipProviderInterface;
use Drupal\state_machine\Plugin\Workflow\Workflow;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @MembershipProvider (
 *   id = "netbilling",
 *   label = @Translation("NETBilling")
 * )
 */
class NETBilling extends MembershipProviderBase implements MembershipProviderInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The self-service API URL.
   */
  const ENDPOINT_SELF_SERVICE = 'https://secure.netbilling.com/gw/native/selfservice1.2';

  /**
   * The reporting API URL.
   */
  const ENDPOINT_REPORTING = 'https://secure.netbilling.com/gw/reports/member1.5';

  /**
   * The member update API URL.
   */
  const MEMBER_UPDATE = 'https://secure.netbilling.com/gw/native/mupdate1.1';

  /**
   * The callback script version we are emulating.
   */
  const EMULATION_VERSION = 2.3;

  /**
   * The User-Agent header to send to NETBilling, based on their spec.
   */
  const NETBILLING_UA = 'Drupal/Version:2016.Jun.23';

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  private $dateFormatter;

  /**
   * Logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  private $loggerChannelFactory;

  /**
   * @inheritDoc
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DateFormatter $dateFormatter, LoggerChannelFactory $loggerChannelFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dateFormatter = $dateFormatter;
    $this->loggerChannelFactory = $loggerChannelFactory;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get('logger.factory')
    );
  }

  /**
   * @inheritDoc
   */
  public static function getState(MembershipInterface $membership, Workflow $workflow) {
    // TODO: Implement getState() method.
  }

  /**
   * Helper function to construct a NETbilling recurring period string.
   *
   * @param $interval array Interval array as returned by interval.module
   * @param $strategy string 'code' or 'days', the type of value that should be returned.
   *
   * @throws \LogicException
   *
   * @returns string Contents for the recurring_period parameter.
   */
  private function interval_code($interval, $strategy = 'code') {
    switch ($interval['period']) {
      case 'day':
        $duration = $interval['interval'];
        break;
      case 'month':
        // For initial terms, Netbilling only takes the term in days.
        // For recurring periods, it will accept a "button maker expression"
        if ($strategy == 'days') {
          $end = new DateObject('now + ' . $interval['interval'] . ' months');
          $now = new DateObject();
          $duration = $end->difference($now, 'days');
        }
        else {
          // As determined by Netbilling button maker.
          // This uses the "same day every month" approach, not month == 30 days.
          $duration = 'add_months((trunc(sysdate)+0.5),' . $interval['interval'] . ')';
        }
        break;
      case 'week':
        $duration = $interval['interval'] * 7;
        break;
      default:
        throw new \LogicException('Invalid interval period specified.');
    }
    return $duration;
  }

  /**
   * Make a request to the membership reporting endpoint.
   *
   * @see http://secure.netbilling.com/public/docs/merchant/public/directmode/repinterface1.5.html
   *
   * @param $config NETBilling account config, keyed by:
   *   account_id, site_tag and auth
   * @param $from int Unix timestamp for from date; defaults to 1 month ago
   * @param $to int Unix timestamp for to date; optional
   * @returns mixed Array of array containing rows, and column headers (as keys => index), or FALSE on failure
   */
  private function reporting_request($config, $from = NULL, $to = NULL) {
    $params = array(
      'account_id' => $config['account_id'],
      'site_tag' => $config['site_tag'],
      'authorization' => $config['auth'],
    );

    // We must at the very least specify a "from" date
    $from = $from ? $from : strtotime('now -1 month');
    $params['changed_after'] = $this->dateFormatter->format($from, 'custom', 'Y-m-d H:i:s', 'UTC');
    if ($to) {
      $params['transactions_before'] = $this->dateFormatter->format($to, 'custom', 'Y-m-d H:i:s', 'UTC');
    }

    $options = array(
      'headers' => array('User-Agent' => self::NETBILLING_UA),
    );

    $client = new Client();
    $request = $client->post(self::ENDPOINT_REPORTING, $options);
    foreach ($params as $field => $value) {
      if (is_array($value)) {
        foreach ($value as $v) {
          $request->setPostField($field, $v);
        }
      }
      else {
        $request->setPostField($field, $value);
      }
    }
    try {
      $result = $request->send();
      // Errors could also manifest in different response codes/headers.
      if (($result->getHeader('Content-Type') == 'text/plain') || ($retry = $result->getHeader('Retry-After'))) {
        $msg = isset($retry)
          ? $result->getBody() . ' / ' . $this->stringTranslation->translate('Retry after :s seconds', [':s' => $retry])
          : $result->getBody();
        // @todo - Implement caching the response and enforcing Retry-After interval.
        throw new \Exception($msg, $result->getStatusCode());
      }
    }
    catch (\Exception $e) {
      $this->loggerChannelFactory->get('membership_provider_netbilling')->error($e->getMessage());
    }

    /* @var \Psr\Http\Message\ResponseInterface $result */
    $payload = array_map('str_getcsv', explode("\n", trim($result->getBody())));
    // Column names are in the first row.
    $columns = array_flip(array_shift($payload));

    return array($payload, $columns);
  }
}
