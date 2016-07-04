<?php

namespace Drupal\membership_provider_netbilling\Plugin\MembershipProvider;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\membership_provider\Plugin\ConfigurableMembershipProviderBase;
use Drupal\membership_provider_netbilling\NetbillingUtilities;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @MembershipProvider (
 *   id = "netbilling",
 *   label = @Translation("NETBilling")
 * )
 */
class NETBilling extends ConfigurableMembershipProviderBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use DependencySerializationTrait;

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
   * Active membership state.
   */
  const STATUS_ACTIVE = 'active';

  /**
   * Inactive membership state.
   */
  const STATUS_INACTIVE = 'expired';

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  private $dateFormatter;

  /**
   * Logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private $loggerChannel;

  /**
   * @inheritDoc
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DateFormatter $dateFormatter, LoggerChannelInterface $loggerChannel) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dateFormatter = $dateFormatter;
    $this->loggerChannel = $loggerChannel;
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
      $container->get('logger.channel.membership_provider_netbilling')
    );
  }

  /**
   * Resolves whether a particular NETBilling status is active.
   *
   * @param $status
   * @return string
   */
  public static function flattenStatus($status) {
    $active = [
      'ACTIVE',
      'ACTIVE/R',
    ];
    return in_array($status, $active, TRUE) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
  }

  /**
   * @return array
   */
  private function default_request_options() {
    return array(
      'headers' => array('User-Agent' => self::NETBILLING_UA),
      'body' => '',
    );
  }

  /**
   * Query the "member update" API, which can also be a non-update query.
   *
   * @see https://secure.netbilling.com/public/docs/merchant/public/directmode/mupdate1.1.html
   *
   * @param $identifier array An array with either an id or name key.
   * @param string $cmd The command to issue, defaults to GET. (Others not yet implemented.)
   * @param array $data Data to send via an update command.
   *
   * @return array A structured array with keys as described at the URL above.
   */
  public function update_request($identifier, $cmd = 'GET', $data = []) {
    $config = $this->configuration;
    $params = array(
      'C_ACCOUNT' => $config['account_id'] . ':' . $config['site_tag'],
      'C_CONTROL_KEYWORD' => $config['access_keyword'],
      'C_COMMAND' => $cmd,
    );
    if (isset($identifier['id'])) {
      $params['C_MEMBER_ID'] = $identifier['id'];
    }
    else if (isset($identifier['name'])) {
      $params['C_MEMBER_LOGIN'] = $identifier['name'];
    }
    $options = ['body' => http_build_query($params)] + $this->default_request_options();
    try {
      $client = new Client();
      $response = $client->request('POST', self::MEMBER_UPDATE, $options)->getBody()->getContents();
      return NetbillingUtilities::parse_str_multiple($response);
    }
    catch (\Throwable $e) {
      $this->loggerChannel->error($e->getMessage());
    }
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
    $params = [];
    foreach ($sites as $site) {
      $params['site_tag'][] = $site['site_tag'];
      $params['authorization'][] = $site['retrieval_keyword'];
    }
    $params += array(
      'account_id' => $config['account_id'],
      'site_tag' => $config['site_tag'],
      'authorization' => $config['retrieval_keyword'],
    );
    $params = array_filter($params);

    // We must at the very least specify a "from" date
    $params['expire_after'] = $from ?? $this->dateFormatter->format(time(), 'custom', 'Y-m-d H:i:s', 'UTC');
    if ($to) {
      $params['expire_before'] = $this->dateFormatter->format($to, 'custom', 'Y-m-d H:i:s', 'UTC');
    }

    $options = $this->default_request_options();

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
      $this->loggerChannel->error($e->getMessage());
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

  /**
   * @inheritDoc
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = [
      'site_tag' => $this->t('Site Tag'),
      'account_id' => $this->t('Account ID'),
      'access_keyword' => $this->t('Access Keyword'),
      'retrieval_keyword' => $this->t('Data Retrieval Keyword'),
    ];
    $values = $this->getConfiguration() + $this->defaultConfiguration();
    foreach ($config as $key => $label) {
      $form[$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => $values[$key],
        '#size' => 60,
      ];
    }
    return $form;
  }

  /**
   * @inheritDoc
   */
  public function defaultConfiguration() {
    return [
      'site_tag' => '',
      'account_id' => '',
      'access_keyword' => '',
      'retrieval_keyword' => '',
    ];
  }

}
