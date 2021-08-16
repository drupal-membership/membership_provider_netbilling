<?php declare(strict_types=1);

namespace Drupal\membership_provider_netbilling;

use Drupal\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;

/**
 * Event for NETbilling processing.
 *
 * @todo - Refactor this out to different types of events.
 */
class NetbillingEvent extends Event {

  /**
   * Status indicating the original request has not yet been fulfilled.
   */
  const STATUS_NOT_FULFILLED = 0;

  /**
   * Status indicating the original request has been fulfilled.
   */
  const STATUS_FULFILLED = 1;

  /**
   * The users for whom to act upon.
   *
   * @var array
   */
  protected $users = [];

  /**
   * The site config.
   *
   * @var array
   */
  protected $siteConfig;

  /**
   * Arbitrary data, e.g., hash info.
   *
   * @var array
   */
  protected $data = [];

  /**
   * Processing status.
   *
   * @var int
   */
  protected $status = self::STATUS_NOT_FULFILLED;

  /**
   * Status message.
   *
   * @var string
   */
  protected $message = '';

  /**
   * Request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected $request;

  public function __construct($config, $users = [], $data = [], ?Request $request = NULL) {
    $this->users = $users;
    $this->data = $data;
    $this->siteConfig = $config;
    $this->request = $request;
  }

  /**
   * Report whether the request has been yet fulfilled.
   *
   * @return bool
   */
  public function isFulfilled() {
    return $this->status == self::STATUS_FULFILLED;
  }

  /**
   * Set the status message.
   *
   * @param string $message
   */
  public function setMessage($message) {
    $this->message = $message;
  }

  /**
   * Set the fulfillment status.
   *
   * @param $status
   */
  public function setStatus($status) {
    $this->status = $status;
  }

  /**
   * Get the site config.
   *
   * @return array
   */
  public function getSiteConfig() {
    return $this->siteConfig;
  }

  /**
   * Get the arbitrary data.
   *
   * @return array
   */
  public function getData() {
    return $this->data;
  }

  /**
   * Get the message.
   *
   * @return string
   */
  public function getMessage() {
    return $this->message;
  }

  /**
   * Get users to act on.
   *
   * @return array
   */
  public function getUsers() {
    return $this->users;
  }

  /**
   * @return \Symfony\Component\HttpFoundation\Request|null
   */
  public function getRequest(): ?Request {
    return $this->request;
  }

}
