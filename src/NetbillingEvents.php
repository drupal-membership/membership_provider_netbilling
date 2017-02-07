<?php

namespace Drupal\membership_provider_netbilling;

/**
 * Class NetbillingEvents
 *
 * Contains events called by the NETBilling membership provider.
 *
 * @package Drupal\membership_provider_netbilling
 */
/**
 * Class NetbillingEvents
 * @package Drupal\membership_provider_netbilling
 */
/**
 * Class NetbillingEvents
 * @package Drupal\membership_provider_netbilling
 */
final class NetbillingEvents {

  /**
   * Name of the member update event.
   */
  const UPDATE = 'membership_provider_netbilling.update';

  /**
   * Name of the append event.
   */
  const APPEND = 'membership_provider_netbilling.append';

  /**
   * Name of the append event.
   */
  const DELETE = 'membership_provider_netbilling.delete';

  /**
   * Name of the user check event.
   */
  const CHECK = 'membership_provider_netbilling.delete';

  /**
   * Return from the hosted payment page.
   */
  const HOSTED_RETURN = 'membership_provider_netbilling.return';

  /**
   * Config resolution
   */
  const RESOLVE_SITE_CONFIG = 'membership_provider_netbilling.resolver';

  /**
   * Resolve config by entity.
   */
  const RESOLVE_SITE_CONFIG_ENTITY = 'membership_provider_netbilling.resolver_entity';

  /**
   * Resolve config by remote ID.
   */
  const RESOLVE_SITE_CONFIG_ID = 'membership_provider_netbilling.resolver_id';

}
