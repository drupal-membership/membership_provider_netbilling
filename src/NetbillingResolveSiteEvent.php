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
  protected $siteConfig;

  /**
   * The site entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $siteEntity;

  /**
   * The remote ID
   *
   * @var string
   */
  protected $remoteId;

  /**
   * @inheritDoc
   */
  public function __construct($site_tag = NULL) {
    if ($site_tag) {
      $this->siteConfig = ['site_tag' => $site_tag];
    }
  }

  /**
   * Get the remote ID
   *
   * @return string
   */
  public function getRemoteId() {
    return $this->remoteId;
  }

  /**
   * Set the remote ID
   *
   * @param string $remoteId
   */
  public function setRemoteId($remoteId) {
    $this->remoteId = $remoteId;
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
