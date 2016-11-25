<?php

namespace Drupal\membership_provider_netbilling;

use Symfony\Component\EventDispatcher\Event;

/**
 * Event class to complete the site config.
 *
 * @package Drupal\membership_provider_netbilling
 */
class NetbillingResolveSiteEvent extends Event {

  /**
   * The site config.
   *
   * @var array
   */
  private $siteConfig;

  /**
   * The site entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private $siteEntity;

  /**
   * @inheritDoc
   */
  public function __construct($site_tag) {
    $this->siteConfig = ['site_tag' => $site_tag];
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
   * Set the site config.
   *
   * @param $config
   */
  public function setSiteConfig($config) {
    $this->siteConfig = $config;
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface
   */
  public function getSiteEntity() {
    return $this->siteEntity;
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $siteEntity
   */
  public function setSiteEntity($siteEntity) {
    $this->siteEntity = $siteEntity;
  }

}
