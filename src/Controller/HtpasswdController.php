<?php

namespace Drupal\membership_provider_netbilling\Controller;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\membership_provider_netbilling\NetbillingEvent;
use Drupal\membership_provider_netbilling\NetbillingEvents;
use Drupal\membership_provider_netbilling\NetbillingQueueAddItem;
use Drupal\membership_provider_netbilling\NetbillingResolveSiteEvent;
use Drupal\membership_provider_netbilling\NetbillingUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class HtpasswdController.
 *
 * @package Drupal\membership_provider_netbilling\Controller
 */
class HtpasswdController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The callback script version we are emulating.
   */
  const EMULATION_VERSION = 2.3;

  /**
   * @var \Symfony\Component\HttpFoundation\Request
   */
  private $currentRequest;

  /**
   * The current command.
   *
   * @var string
   */
  private $cmd = '';

  /**
   * The membership provider plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  private $providerManager;

  /**
   * The site config.
   *
   * @var array
   */
  private $siteConfig;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  private $eventDispatcher;

  /**
   * The cache interface.
   *
   * @var \Drush\Cache\CacheInterface
   */
  private $cache;

  /**
   * @inheritDoc
   */
  public function __construct(RequestStack $request, PluginManagerInterface $provider_manager, EventDispatcherInterface $event_dispatcher, CacheBackendInterface $cache) {
    $this->currentRequest = $request->getCurrentRequest();
    $this->providerManager = $provider_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->cache = $cache;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('plugin.manager.membership_provider.processor'),
      $container->get('event_dispatcher'),
      $container->get('cache.default')
    );
  }

  /**
   * @param $cmd
   * @return $this
   * @throws \Exception
   */
  private function setCommand($cmd) {
    $allowed = [
      'POST' => [
        'append_user',
        'append_users',
        'delete_user',
        'delete_users',
        'update_all_users',
      ],
      'GET' => [
        'check_user',
        'check_users',
        // 'list_all_users', // Commented-out in source file.
        'test',
      ]
    ];
    if (in_array($cmd, $allowed[$this->currentRequest->getMethod()])) {
      $this->cmd = $cmd;
      return $this;
    }
    throw new \Exception('Invalid command.');
  }

  /**
   * @param $msg
   * @return \Symfony\Component\HttpFoundation\Response
   */
  private function errorResponse($msg) {
    return new Response($msg, 400, ['Content-Type' => 'text/plain']);
  }

  /**
   * @return \Symfony\Component\HttpFoundation\Response
   */
  private function blankResponse() {
    return new Response('', 200, ['Content-Type' => 'text/plain']);
  }

  /**
   * @return mixed
   */
  private function getCommandBase() {
    list($cmd_base) = explode('_', $this->cmd);
    return $cmd_base;
  }

  /**
   * POST Emulator for nbmember.cgi
   *
   * @see https://secure.netbilling.com/public/nbmember.cgi
   *
   * @param string $site_tag Site tag from URI.
   *
   * @return Response
   */
  public function post($site_tag) {
    try {
      $this->setCommand($this->currentRequest->get('cmd'));
      $this->setSiteConfig($site_tag);
    }
    catch (\Throwable $e) {
      return new Response($e->getMessage(), 400);
    }

    $default_param = array(
      'u' => array(),
    );
    $hash = FALSE;
    $post = NetbillingUtilities::parse_str_multiple($this->currentRequest->getContent()) + $default_param;
    // Follow the preferred source of passwords.
    $pw_sources = array(
      'p', // UNIX crypt
      'w', // MD5 crypt Apache/Win32
      'm', // MD5 crypt
      'n', // Plaintext
    );
    foreach ($pw_sources as $source) {
      if (isset($post[$source])) {
        $pws = $post[$source];
        $hash = $source == 'n';
        break;
      }
    }
    // Should we hash the password before saving?
    // Allow for delete/check commands to omit passwords.
    $expect_pws = preg_match($this->cmd, '/^(append|update)/');
    if ($expect_pws) {
      if (count($post['u']) == count($pws)) {
        $users = !is_array($post['u']) ? array($post['u'] => $pws) : array_combine($post['u'], $pws);
      }
      else {
        return $this->errorResponse('ERROR: Username/password count mismatch');
      }
    }
    else {
      // This is a check username or delete action, so $users is actually just usernames.
      // The passwords may or may not have been provided, but we don't need them.
      $usernames = !is_array($post['u']) ? array($post['u']) : $post['u'];
    }

    // Script must accept plural or singular form of user actions.
    switch ($this->getCommandBase()) {
      case 'append':
        return $this->event_dispatch(NetbillingEvents::APPEND, $users, ['hash' => $hash]);
      case 'delete':
        return $this->event_dispatch(NetbillingEvents::DELETE, $usernames);
      case 'update':
        return $this->event_dispatch(NetbillingEvents::UPDATE, $users, ['hash' => $hash]);
    }
  }

  /**
   * @param $site_tag
   * @return array
   */
  private function setSiteConfig($site_tag) {
    if ($cached = $this->cache->get('membership_provider_netbilling.site.' . $site_tag)) {
      return $cached->data;
    }
    $event = new NetbillingResolveSiteEvent($site_tag);
    $this->eventDispatcher->dispatch(NetbillingEvents::RESOLVE_SITE_CONFIG, $event);
    if ($event->getSiteConfig()['access_keyword'] != $this->currentRequest->get('keyword')) {
      throw new AccessException('ERROR: Invalid Access Keyword.', 403);
    }
    $this->siteConfig = $event->getSiteConfig();
    $this->cache->set('membership_provider_netbilling.site.' . $site_tag, $event->getSiteConfig());
    return $this->getSiteConfig();
  }

  /**
   * @return array
   */
  private function getSiteConfig() {
    return $this->siteConfig;
  }

  /**
   * Event dispatcher.
   *
   * @param array $users Users to add - associative array of user => pw
   * @param string $method Event to fire.
   * @param array $data Arbitrary data to set in the event.
   *
   * @returns Response
   */
  private function event_dispatch($method, $users, $data = []) {
    $event = new NetbillingEvent($this->getSiteConfig(), $users, $data);
    $this->eventDispatcher->dispatch($method, $event);
    if ($event->isFulfilled()) {
      $response = $this->blankResponse();
      $response->setContent($event->getMessage());
    }
    else {
      $response = $this->errorResponse('ERROR: Action not handled. ' . $event->getMessage());
      $response->setStatusCode(500);
    }
    return $response;
  }

  /**
   * GET callback.
   *
   * @param string $site_tag
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function get(string $site_tag) {
    try {
      $this->setCommand($this->currentRequest->get('cmd'));
      $this->setSiteConfig($site_tag);
    }
    catch (\Throwable $e) {
      return new Response($e->getMessage(), 400);
    }

    $response = $this->blankResponse();
    switch ($this->getCommandBase()) {
      case 'test':
        $response->setContent($this->get_test());
        break;
      case 'check':
        if (empty($usernames)) {
          return $this->errorResponse('No users specified when attempting to check users.');
        }
        $response = $this->event_dispatch($usernames, NetbillingEvents::CHECK);
        break;
    }
    return $response;
  }

  /**
   * Returns test page output similar to model CGI script.
   * We omit lots of the non-applicable bits and information disclosure risks.
   *
   * @returns string Content.
   */
  private function get_test() {
    $label_length = 30;
    $content = array(
      '  OK: Control interface is live',
      '',
      str_pad('  Version', $label_length) . ': ' . self::EMULATION_VERSION,
      str_pad('  Local date and time', $label_length) . ': ' . \Drupal::service('date.formatter')->format(time(), 'custom', 'r'),
    );
    return implode("\n", $content) . "\n";
  }

  /**
   * Access control callback.
   *
   * @param string $site_tag Site tag from URI.
   *
   * @return AccessResultInterface
   */
  public function access($site_tag) {
    try {
      $this->setSiteConfig($site_tag);
    }
    catch (\Throwable $e) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowed();
  }

  /**
   * Helper function to parse a query string in a Perl-like fashion.
   * The model Netbilling CGI script uses Perl's param() method for this.
   *
   * @see http://www.php.net/manual/en/function.parse-str.php#76792
   * @see http://perldoc.perl.org/CGI.html#FETCHING-THE-VALUE-OR-VALUES-OF-A-SINGLE-NAMED-PARAMETER:
   *
   * @param string $str The string to parse
   *
   * @return array The parsed string.
   */
  private function parse_str_multiple($str) {
    $arr = array();

    $pairs = explode('&', $str);

    // Loop through each pair
    foreach ($pairs as $i) {
      // Split into name and value
      list($name,$value) = explode('=', $i, 2);
      $value = urldecode($value);

      // If name already exists
      if (isset($arr[$name])) {
        // Stick multiple values into an array
        if (is_array($arr[$name])) {
          $arr[$name][] = $value;
        }
        else {
          $arr[$name] = array($arr[$name], $value);
        }
      }
      // Otherwise, simply stick it in a scalar
      else {
        $arr[$name] = $value;
      }
    }

    return $arr;
  }

}
