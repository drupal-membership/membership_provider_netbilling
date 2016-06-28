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
use GuzzleHttp\Psr7\StreamWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

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
   * @param $from int Unix timestamp for from date; defaults to 1 month ago
   * @param $to int Unix timestamp for to date; optional
   * @param $sites array Override array of sites => keywords to retrieve on this account.
   *
   * @returns mixed Array of array containing rows, and column headers (as keys => index), or FALSE on failure
   */
  public function reporting_request($from = NULL, $to = NULL, $sites = []) {
    $config = $this->configuration;
    $params = array(
      'account_id' => $config['account_id'],
      'site_tag' => $config['site_tag'],
      'authorization' => $config['reporting_keyword'],
    );
    if ($sites) {
      $params['site_tag'] = array_keys($sites);
      $params['authorization'] = array_values($sites);
    }
    $params = array_filter($params);

    // We must at the very least specify a "from" date
    $params['expire_after'] = $from ?? $this->dateFormatter->format(time(), 'custom', 'Y-m-d H:i:s', 'UTC');
    if ($to) {
      $params['expire_before'] = $this->dateFormatter->format($to, 'custom', 'Y-m-d H:i:s', 'UTC');
    }

    $options = array(
      'headers' => array('User-Agent' => self::NETBILLING_UA),
      'body' => '',
    );

    foreach ($params as $field => $value) {
      if (is_array($value)) {
        foreach ($value as $v) {
          $options['body'] .= http_build_query([$field => $v]) . '&';
        }
      }
      else {
        $options['body'] .= http_build_query([$field => $value]) . '&';
      }
    }
    $options['body'] = trim($options['body'], ' \t\n\r\0\x0B\&');
    try {
      $client = new Client();
      $result = $client->request('POST', self::ENDPOINT_REPORTING, $options);
      // Errors could also manifest in different response codes/headers.
      if ((reset($result->getHeader('Content-Type')) == 'text/plain') || ($retry = $result->getHeader('Retry-After'))) {
        if (isset($retry)) {
          $msg = $result->getBody() . ' / ' . $this->stringTranslation->translate('Retry after :s seconds', [':s' => reset($retry)]);
          $code = 429; // Too Many Requests
          // @todo - Implement caching the response and enforcing Retry-After interval.
        }
        else {
          list($code, $msg) = explode(' ', $result->getBody(), 2);
        }
        // Errors are indicated by the content-type of the response - code is first part of the body.
        throw new \Exception($msg, $code);
      }
    }
    catch (\Exception $e) {
      $this->loggerChannelFactory->get('membership_provider_netbilling')->error($e->getMessage());
    }

    return $this->reporting_parse($result);
  }

  private function reporting_parse(ResponseInterface $result) {
    $members = [];
    $keys = [];
    $content = $result->getBody()->getContents();
    $csv = array_map('str_getcsv', explode("\n", trim($content)));
    foreach ($csv as $row => $line) {
      if ($row === 0) {
        $keys = $line;
      }
      else {
        $member = array_combine($keys, $line);
        // This mostly reflects wishful thinking. Additional site tags for a user
        // are considered "secondary," yet the reporting interface only reports primary.
        // This code is an effort to not clobber the additional entries if/when they are
        // provided by the NETBilling API.
        if (isset($members[$member['MEMBER_ID']])) {
          $members[$member['MEMBER_ID']]['SITE_TAG'][] = $member['SITE_TAG'];
          continue;
        }
        else {
          $member['SITE_TAG'] = [$member['SITE_TAG']];
        }
        $members[$member['MEMBER_ID']] = $member;
      }
    }
    return $members;
  }

}
