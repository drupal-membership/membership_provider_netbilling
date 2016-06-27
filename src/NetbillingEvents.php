<?php

namespace Drupal\membership_provider_netbilling;

/**
 * Class NetbillingEvents
 *
 * Contains events called by the NETBilling membership provider.
 *
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

}
