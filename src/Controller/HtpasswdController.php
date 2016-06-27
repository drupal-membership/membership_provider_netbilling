<?php

namespace Drupal\membership_provider_netbilling\Controller;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\membership_provider_netbilling\NetbillingQueueAddItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;

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
   * @inheritDoc
   */
  public function __construct(RequestStack $request, PluginManagerInterface $provider_manager) {
    $this->currentRequest = $request->getCurrentRequest();
    $this->providerManager = $provider_manager;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('plugin.manager.membership_provider.processor')
    );
  }

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

  private function errorResponse($msg) {
    return new Response($msg, 400, ['Content-Type' => 'text/plain']);
  }

  private function blankResponse() {
    return new Response('', 200, ['Content-Type' => 'text/plain']);
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
    $post = $this->parse_str_multiple($this->currentRequest->getContent()) + $default_param;
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
    list($cmd_base) = explode('_', $this->cmd);
    switch ($cmd_base) {
      case 'append':
        return $this->post_add($users, $hash);
      case 'delete':
        return $this->post_delete($usernames);
      case 'update':
        return $this->post_add($users, $hash, 'update');
    }
  }

  private function setSiteConfig($site_tag) {
    $instances = membership_provider_netbilling_field_instances();
    foreach ($instances as $entity_type => $def) {
      foreach ($def as $field_name => $field_config) {
        if ($ids = \Drupal::entityQuery($entity_type)->condition($field_name . '.site_tag', $site_tag, '=')->execute()) {
          $this->siteConfig = \Drupal::entityTypeManager()
            ->getStorage($entity_type)
            ->load(reset($ids))
            ->get($field_name)
            ->getValue()[0];
          break 2;
        }
      }
    }
    if ($this->getSiteConfig()['access_keyword'] != $this->currentRequest->get('keyword')) {
      throw new AccessException('ERROR: Invalid Access Keyword.', 403);
    }
    return $this->getSiteConfig();
  }

  private function getSiteConfig() {
    return $this->siteConfig;
  }

  /**
   * Add users.
   *
   * @param array $users Users to add - associative array of user => pw
   * @param boolean $hash Whether to hash the passwords
   * @param string $method Method to use: 'append', 'smart' or 'purge'
   *
   * @returns Response
   */
  private function post_add($users, $hash, $method = 'append') {
    /** @var QueueFactory $queue_factory */
    $queue_factory = \Drupal::service('queue');
    /** @var QueueInterface $queue */
    $queue = $queue_factory->get('membership_provider_netbilling');
    $item = new NetbillingQueueAddItem($this->getSiteConfig(), $users, $hash, $method);
    $queue->createItem($item);
    $response = $this->blankResponse();
    $response->setContent(t('OK: Updated @count user@plural and password@plural', array('@count' => $count, '@plural' => format_plural($count, '', 's'))));
    return $response;

    switch ($method) {
      // Add a user, OR:
      // Sometimes append actually means, change. A refresh after password change
      // in the Netbilling admin system just prompts an append_user command.
      case 'append':
        break;
      case 'smart':
        // We are avoiding re-hashing, deleting, or otherwise editing users who aren't changing.
        // @todo - Handle situation where pw changes at Netbilling but not locally?
        netbilling_membership_cgi_delete($removed);
        $users = array_intersect_key($users, array_flip($new));
        netbilling_membership_cgi_log($new, NETBILLING_MEMBERSHIP_ACTIVE);
        break;
      case 'purge':
        // The Netbilling Perl script uses a prefix to identify passwords to delete;
        // in our case, this table isn't used for other users so we can just truncate.
        db_truncate(NETBILLING_MEMBRSHIP_HTPASSWD_TABLE)->execute();
        netbilling_membership_drupal_delete($removed);
        netbilling_membership_cgi_log($removed, NETBILLING_MEMBERSHIP_INACTIVE);
        netbilling_membership_cgi_log($new, NETBILLING_MEMBERSHIP_ACTIVE);
        break;
    }

    // There may be no new users, if we are smartly updating.
    if ($users) {
      $insert = db_insert(NETBILLING_MEMBRSHIP_HTPASSWD_TABLE)
        ->fields(array('username', 'password'));
      foreach ($users as $u => $p) {
        if ($hash) {
          // Hash the password. Speed it up by 1/3.
          $p = user_hash_password($p, DRUPAL_HASH_COUNT - 5);
        }
        $insert->values(array(
          'username' => $u,
          'password' => $p,
        ));
      }
      $insert->execute();
    }
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

    switch ($this->cmd) {
      case 'test':
        $response->setContent($this->get_test());
        break;
      case 'check':
        if (empty($usernames)) {
          netbilling_membership_cgi_error('No users specified when attempting ' . check_plain($cmd));
        }
        $output = netbilling_membership_cgi_list($usernames);
        break;
      case 'test':
        $output = netbilling_membership_cgi_test();
        break;
      case 'list_all_users':
        // In original script,
        // list_all_users is coded, but commented out as a supported parameter.
        netbilling_membership_cgi_error('list_all_users is commented out as a supported cgi parameter in the nbmember.cgi v' . self::EMULATION_VERSION . ' script and not supported here.');
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
