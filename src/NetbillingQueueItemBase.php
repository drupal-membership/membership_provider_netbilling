<?php

namespace Drupal\membership_provider_netbilling;

abstract class NetbillingQueueItemBase {

  private $siteConfig;

  /**
   * Sets up the item.
   */
  public function __construct($siteConfig) {
    $this->siteConfig = $siteConfig;
  }

}
