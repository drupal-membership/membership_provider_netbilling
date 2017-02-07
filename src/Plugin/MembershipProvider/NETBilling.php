<?php

namespace Drupal\membership_provider_netbilling\Plugin\MembershipProvider;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\membership\Plugin\ConfigurableMembershipProviderBase;
use Drupal\membership_provider_netbilling\NetbillingUtilities;
use Drupal\membership_provider_netbilling\SiteResolver;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Drupal\membership\Annotation\MembershipProvider;
use Drupal\Core\Annotation\Translation;

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
  const ENDPOINT_MEMBER_UPDATE = 'https://secure.netbilling.com/gw/native/mupdate1.1';

  /**
   * The transaction reporting endpoint.
   */
  const ENDPOINT_TRANSACTIONS = 'https://secure.netbilling.com/gw/reports/transaction1.5';

  /**
   * Member reporting endpoint identifier.
   */
  const REPORTING_TYPE_MEMBERS = 'member';

  /**
   * Transactions endpoint identifier.
   */
  const REPORTING_TYPE_TRANSACTIONS = 'transactions';

  /**
   * The timestamp acceptable to the member/transaction reporting endpoint.
   */
  const REPORTING_TIME_FORMAT = 'Y-m-d H:i:s';

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
  protected $dateFormatter;

  /**
   * Logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * The NETbilling site resolver service.
   *
   * @var \Drupal\membership_provider_netbilling\SiteResolver
   */
  protected $resolver;

  /**
   * @inheritDoc
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DateFormatter $dateFormatter, LoggerChannelInterface $loggerChannel, SiteResolver $resolver) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dateFormatter = $dateFormatter;
    $this->loggerChannel = $loggerChannel;
    $this->resolver = $resolver;
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
      $container->get('logger.channel.membership_provider_netbilling'),
      $container->get('membership_provider_netbilling.site_resolver')
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
  protected function default_request_options() {
    return array(
      'headers' => [
        'User-Agent' => self::NETBILLING_UA,
      ],
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
   * @return array A structured array with keys as described at the URL above.
   *
   * @todo Implement sending $data
   */
  public function update_request($identifier, $cmd = 'GET', $data = []) {
    $config = $this->configuration;
    $params = array(
      'C_ACCOUNT' => $config['account_id'],
      'C_CONTROL_KEYWORD' => $config['access_keyword'],
      'C_COMMAND' => $cmd,
    );
    if (isset($config['site_tag'])) {
      $params['C_ACCOUNT'] .= ':' . $config['site_tag'];
    }
    if (isset($identifier['id'])) {
      $params['C_MEMBER_ID'] = $identifier['id'];
    }
    else if (isset($identifier['name'])) {
      $params['C_MEMBER_LOGIN'] = $identifier['name'];
    }
    $options = ['body' => http_build_query($params + $data)] + $this->default_request_options();
    try {
      $client = new Client();
      $response = $client->request('POST', self::ENDPOINT_MEMBER_UPDATE, $options)->getBody()->getContents();
      return NetbillingUtilities::parse_str_multiple($response);
    }
    catch (\Throwable $e) {
      $this->loggerChannel->error($e->getMessage());
    }
  }

  /**
   * Format a reporting-endpoint acceptable date.
   *
   * @param $timestamp string UNIX timestamp, converted in UTC
   * @return string The formatted date.
   */
  protected function formatReportingDate($timestamp) {
    return $this->dateFormatter->format($timestamp, 'custom', self::REPORTING_TIME_FORMAT, 'UTC');
  }

  /**
   * Make a request to the membership or transactions reporting endpoint.
   *
   * @param $service string Service to query - members|transactions
   * @param $from int Unix timestamp for from date; defaults to now.
   * @param $to int Unix timestamp for to date; optional
   * @param $sites array Override array of site configurations retrieve on this account.
   * @throws \Exception
   * @returns mixed Array of results.
   */
  public function reportingRequest(string $service = self::REPORTING_TYPE_MEMBERS, $from = NULL, $to = NULL, $sites = []) {
    $keyword = $service == self::REPORTING_TYPE_MEMBERS ? 'expire' : 'transactions';
    // We must at the very least specify a "from" date
    $params[$keyword . '_after'] = $this->formatReportingDate($from ?: time());
    if ($to) {
      $params[$keyword . '_before'] = $this->formatReportingDate($to);
    }

    return $this->sendReportingRequest(
      $params,
      $sites,
      $service == 'members' ? self::ENDPOINT_REPORTING : self::ENDPOINT_TRANSACTIONS
    );
  }

  /**
   * Make a request to the membership or transaction reporting endpoint.
   *
   * @see http://secure.netbilling.com/public/docs/merchant/public/directmode/repinterface1.5.html
   *
   * @param $params array Array of parameters to build into the request.
   * @param $sites array Override array of site configurations retrieve on this account.
   * @param $endpoint string Endpoint to query. Member Reporting and Transaction
   *   share similar request parameters and response shapes.
   * @throws \Exception
   * @returns mixed Array of array containing rows, and column headers (as keys => index), or FALSE on failure
   */
  protected function sendReportingRequest($params, $sites = [], $endpoint) {
    $config = $this->getConfiguration();
    $localParams = [];
    foreach ($sites as $site) {
      $localParams['site_tag'][] = $site['site_tag'];
      $localParams['authorization'][] = $site['retrieval_keyword'];
    }
    $localParams += array(
      'account_id' => $config['account_id'],
      'site_tag' => $config['site_tag'],
      'authorization' => $config['retrieval_keyword'],
    );
    $params = array_filter($localParams) + $params;
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
    $options['body'] = trim($options['body'], " \t\n\r\0\x0B\\&");

    try {
      $client = new Client();
      $result = $client->request('POST', $endpoint, $options);
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
      throw $e;
    }

    return $this->parseReport($result);
  }

  /**
   * Parser for CSV-style responses.
   *
   * @param \Psr\Http\Message\ResponseInterface $result
   * @return array Array of members, with keys/values in associative arrays.
   */
  protected function parseReport(ResponseInterface $result) {
    $results = [];
    $keys = [];
    $content = $result->getBody()->getContents();
    $csv = array_map('str_getcsv', explode("\n", trim($content)));
    foreach ($csv as $row => $line) {
      if ($row === 0) {
        $keys = $line;
      }
      elseif (in_array('MEMBER_USER_NAME', $keys)) {
        // This is a member reporting report
        $member = array_combine($keys, $line);

        if (isset($results[$member['MEMBER_ID']])) {
          // This mostly reflects wishful thinking. Additional site tags for a user
          // are considered "secondary," yet the reporting interface only reports primary.
          // This code is an effort to not clobber the additional entries if/when they are
          // provided by the NETBilling API.
          $results[$member['MEMBER_ID']]['SITE_TAG'][] = $member['SITE_TAG'];
          continue;
        }
        else {
          $member['SITE_TAG'] = [$member['SITE_TAG']];
        }
        $results[$member['MEMBER_ID']] = $member;
      }
      else {
        // Transaction reporting request
        $trans = array_combine($keys, $line);
        $results[$trans['TRANS_ID']] = $trans;
      }
    }
    return $results;
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
      'integrity_key' => $this->t('Integrity Key'),
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
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement validateConfigurationForm() method.
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
      'integrity_key' => '',
    ];
  }

  /**
   * @inheritDoc
   */
  public function configureFromId($id) {
    if ($config = $this->resolver->getSiteConfigById($id)) {
      $this->setConfiguration($config);
      return $this;
    }
    return FALSE;
  }

}
