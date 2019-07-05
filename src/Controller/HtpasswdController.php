<?php

namespace Drupal\membership_provider_netbilling\Controller;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\membership_provider_netbilling\NetbillingEvent;
use Drupal\membership_provider_netbilling\NetbillingEvents;
use Drupal\membership_provider_netbilling\NetbillingUtilities;
use Drupal\membership_provider_netbilling\SiteResolver;
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
  protected $currentRequest;

  /**
   * The current command.
   *
   * @var string
   */
  protected $cmd = '';

  /**
   * The membership provider plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $providerManager;

  /**
   * The site config.
   *
   * @var array
   */
  protected $siteConfig;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The site resolver.
   *
   * @var \Drupal\membership_provider_netbilling\SiteResolver
   */
  protected $siteResolver;

  /**
   * @inheritDoc
   */
  public function __construct(RequestStack $request, PluginManagerInterface $provider_manager, EventDispatcherInterface $event_dispatcher, SiteResolver $siteResolver) {
    $this->currentRequest = $request->getCurrentRequest();
    $this->providerManager = $provider_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->siteResolver = $siteResolver;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('plugin.manager.membership_provider.processor'),
      $container->get('event_dispatcher'),
      $container->get('membership_provider_netbilling.site_resolver')
    );
  }

  /**
   * @param $cmd
   * @return $this
   * @throws \Exception
   */
  protected function setCommand($cmd) {
    $allowed = [
      'POST' => [
        'append_user',
        'append_users',
        'delete_user',
        'delete_users',
        'update_all_users',
        // These initially looked like GET, but are sent as POST.
        'check_user',
        'check_users',
      ],
      'GET' => [
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
  protected function errorResponse($msg) {
    return new Response($msg, 400, ['Content-Type' => 'text/plain']);
  }

  /**
   * @return \Symfony\Component\HttpFoundation\Response
   */
  protected function blankResponse() {
    return new Response('', 200, ['Content-Type' => 'text/plain']);
  }

  /**
   * @return mixed
   */
  protected function getCommandBase() {
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
      // Sanitize the underlying error.
      // @todo - Log.
      return new Response('Unable to process', 400);
    }

    $default_param = array(
      'u' => array(),
    );
    $hash = FALSE;
    $post = NetbillingUtilities::parse_str_multiple($this->currentRequest->getContent())
      + $default_param;
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
    $expect_pws = preg_match('/^(append|update)/', $this->cmd);
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
      $usernames = !is_array($post['u']) ? [$post['u']] : $post['u'];
    }

    // Script must accept plural or singular form of user actions.
    switch ($this->getCommandBase()) {
      case 'append':
        return $this->event_dispatch(NetbillingEvents::APPEND, $users, ['hash' => $hash]);
      case 'delete':
        return $this->event_dispatch(NetbillingEvents::DELETE, $usernames);
      case 'update':
        return $this->event_dispatch(NetbillingEvents::UPDATE, $users, ['hash' => $hash]);
      case 'check':
        if (!$usernames) {
          return $this->errorResponse('No users specified when attempting to check users.');
        }
        return $this->event_dispatch(
          NetbillingEvents::CHECK,
          array_flip($usernames)
        );
        break;
    }
  }

  /**
   * @param $site_tag
   * @return array
   */
  protected function setSiteConfig($site_tag) {
    $this->siteConfig = $this->siteResolver->getSiteConfig($site_tag);
    $this->siteResolver->validateSiteKeyword($site_tag, $this->currentRequest->get('keyword'));
    return $this->getSiteConfig();
  }

  /**
   * @return array
   */
  protected function getSiteConfig() {
    return $this->siteConfig;
  }

  /**
   * Event dispatcher.
   *
   * @param string $method Event to fire.
   * @param array $users Users to add or check - associative array of user => pw
   * @param array $data Arbitrary data to set in the event.
   *
   * @returns Response
   */
  protected function event_dispatch($method, $users, $data = []) {
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
    }
    return $response;
  }

  /**
   * Returns test page output similar to model CGI script.
   * We omit lots of the non-applicable bits and information disclosure risks.
   *
   * @returns string Content.
   */
  protected function get_test() {
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

}
