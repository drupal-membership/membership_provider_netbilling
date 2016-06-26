<?php

namespace Drupal\membership_provider_netbilling\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
   * @inheritDoc
   */
  public function __construct(RequestStack $request) {
    $this->currentRequest = $request->getCurrentRequest();
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')
    );
  }

  private function setCommand($cmd) {
    $allowed = [
      'POST' => [
        'append_users',
        'delete_users',
        'update_all_users',
      ],
      'GET' => [
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
   * Emulator for nbmember.cgi
   *
   * @see https://secure.netbilling.com/public/nbmember.cgi
   *
   * @return mixed
   */
  public function post() {
    try {
      $this->setCommand($this->currentRequest->get('cmd'));
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
        break;
      }
    }
    // Should we hash the password before saving?
    // Allow for delete/check commands to omit passwords.
    $hash = ($source == 'n') && variable_get('netbilling_membership_hash_plaintext');
    $not_user_only = !in_array($cmd, array('delete_user', 'delete_users', 'check_user'));
    if ($not_user_only && (count($post['u']) == count($pws))) {
      $users = !is_array($post['u']) ? array($post['u'] => $pws) : array_combine($post['u'], $pws);
    }
    else if ($not_user_only) {
      $mismatch = TRUE;
    }
    else {
      // This is a check username or delete action, so $users is actually just usernames.
      // The passwords may or may not have been provided, but we don't need them.
      $usernames = !is_array($post['u']) ? array($post['u']) : $post['u'];
    }

    // We delayed acting on this error so we could log, first.
    if (!empty($mismatch)) {
      netbilling_membership_cgi_error('Username/password count mismatch');
    }

    // Script must accept plural or singular form of user actions.
    $output = array();
    switch ($cmd) {
      case 'append_users':
      case 'append_user':
        $output = netbilling_membership_cgi_add($users, $hash);
        break;
      case 'delete_users':
      case 'delete_user':
        $output = netbilling_membership_cgi_delete($usernames);
        if (empty($usernames)) {
          netbilling_membership_cgi_error('No users specified when attempting ' . check_plain($cmd));
        }
        break;
      case 'check_users':
      case 'check_user':
        if (empty($usernames)) {
          netbilling_membership_cgi_error('No users specified when attempting ' . check_plain($cmd));
        }
        $output = netbilling_membership_cgi_list($usernames);
        break;
      case 'update_all_users':
        $output = netbilling_membership_cgi_add($users, $hash, variable_get('netbilling_membership_update_all_strategy', 'purge'));
        break;
      case 'test':
        $output = netbilling_membership_cgi_test();
        break;
      case 'list_all_users':
        // In original script,
        // list_all_users is coded, but commented out as a supported parameter.
        netbilling_membership_cgi_error('list_all_users is commented out as a supported cgi parameter in the nbmember.cgi v' . self::EMULATION_VERSION . ' script and not supported here.');
        break;
      default:
        netbilling_membership_cgi_error('Invalid command');
        break;
    }

    $headers = array(
      'Content-type' => 'text/plain',
      'Pragma' => 'no-cache',
      'Expires' => 0,
    );
    drupal_send_headers($headers);
    foreach ($output as $line) {
      print $line . "\n";
    }
    drupal_exit();
  }

  public function get() {
    try {
      $this->setCommand($this->currentRequest->get('cmd'));
    }
    catch (\Throwable $e) {
      return new Response($e->getMessage(), 400);
    }

    $response = new Response('', 200, ['Content-Type' => 'text/plain']);
    switch ($this->cmd) {
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
   */
  public function access() {
    // @todo - Implement keyword access control.
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
