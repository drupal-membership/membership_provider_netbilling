<?php

namespace Drupal\membership_provider_netbilling\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Enforces unique Site IDs.
 *
 * @Constraint(
 *   id = "NetbillingUniqueSite",
 *   label = @Translation("Unique Site ID", context = "Validation")
 * )
 */
class NetbillingUniqueSiteConstraint extends Constraint {

  public $message = 'This site tag already exists on %bundle';

}
