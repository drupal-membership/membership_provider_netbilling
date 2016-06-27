<?php

namespace Drupal\membership_provider_netbilling;

/**
 * Class NetbillingQueueAddItem
 * @package Drupal\membership_provider_netbilling
 */
class NetbillingQueueAddItem extends NetbillingQueueItemBase {

  /**
   * @var array
   */
  private $users;

  /**
   * @var bool
   */
  private $hash;

  /**
   * @var string
   */
  private $method;

  /**
   * NetbillingQueueAddItem constructor.
   * 
   * @param array $siteConfig
   * @param array $users
   * @param bool $hash
   * @param string $method
   */
  public function __construct(array $siteConfig, array $users, boolean $hash, string $method) {
    $this->hash = $hash;
    $this->users = $users;
    $this->method = $method;
    parent::__construct($siteConfig);
  }

}
