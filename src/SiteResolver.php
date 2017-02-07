<?php

namespace Drupal\membership_provider_netbilling;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class SiteResolver.
 *
 * @package Drupal\membership_provider_netbilling
 */
class SiteResolver {

  /**
   * Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher definition.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $event_dispatcher;

  /**
   * The cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructor.
   */
  public function __construct(ContainerAwareEventDispatcher $event_dispatcher, CacheBackendInterface $cache) {
    $this->event_dispatcher = $event_dispatcher;
    $this->cache = $cache;
  }

  /**
   * Get a site config.
   *
   * @param $site_tag
   * @return array|null
   */
  public function getSiteConfig($site_tag) {
    $key = 'membership_provider_netbilling.site.' . $site_tag;
    if ($cached = $this->cache->get($key)) {
      $siteConfig = $cached->data;
    }
    else {
      $event = new NetbillingResolveSiteEvent($site_tag);
      $this->event_dispatcher->dispatch(NetbillingEvents::RESOLVE_SITE_CONFIG, $event);
      if (!$siteConfig = $event->getSiteConfig()) {
        return NULL;
      }
      $this->cache->set($key,
        $event->getSiteConfig(),
        Cache::PERMANENT,
        [$event->getSiteEntity()->getEntityType()->id() . ':' . $event->getSiteEntity()->id()]);
    }
    return $siteConfig;
  }

  /**
   * Resolve a site config by entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return array|null
   */
  public function getSiteConfigByEntity(EntityInterface $entity) {
    $key = 'membership_provider_netbilling.entity.' . $entity->id();
    if ($cached = $this->cache->get($key)) {
      $siteConfig = $cached->data;
    }
    else {
      $event = new NetbillingResolveSiteEvent();
      $event->setSiteEntity($entity);
      $this->event_dispatcher->dispatch(NetbillingEvents::RESOLVE_SITE_CONFIG_ENTITY, $event);
      if (!$siteConfig = $event->getSiteConfig()) {
        return NULL;
      }
      $this->cache->set($key,
        $event->getSiteConfig(),
        Cache::PERMANENT,
        [$event->getSiteEntity()->getEntityType()->id() . ':' . $event->getSiteEntity()->id()]);
    }
    return $siteConfig;
  }

  public function validateSiteKeyword($site_tag, $keyword) {
    if ($this->getSiteConfig($site_tag)['access_keyword'] != $keyword) {
      throw new AccessException('ERROR: Invalid Access Keyword.', 403);
    }
    return TRUE;
  }

  /**
   * Resolve a site config by member id.
   *
   * @param string $id The remote ID stored on the membership.
   *
   * @return array|null
   */
  public function getSiteConfigById($id) {
    $key = 'membership_provider_netbilling.id.' . $id;
    if ($cached = $this->cache->get($key)) {
      $siteConfig = $cached->data;
    }
    else {
      $event = new NetbillingResolveSiteEvent();
      $event->setRemoteId($id);
      $this->event_dispatcher->dispatch(NetbillingEvents::RESOLVE_SITE_CONFIG_ID, $event);
      if (!$siteConfig = $event->getSiteConfig()) {
        return NULL;
      }
      $this->cache->set($key,
        $event->getSiteConfig(),
        Cache::PERMANENT,
        ['membership:provider:remote_id:' . $id]);
    }
    return $siteConfig;
  }

}
