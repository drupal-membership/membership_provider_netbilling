<?php

namespace Drupal\membership_provider_netbilling\Plugin\Validation\Constraint;

use Drupal\Core\Entity\EntityFieldManager;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class NetbillingUniqueSiteConstraintValidator extends ConstraintValidator {

  /**
   * @inheritDoc
   */
  public function validate($value, Constraint $constraint) {
    $instances = membership_provider_netbilling_field_instances();
    foreach ($instances as $entity_type => $def) {
      foreach ($def as $field_name => $field_config) {
        if (\Drupal::entityQuery($entity_type)->condition($field_name . '.site_tag', $value, '=')->execute()) {
          $this->context->addViolation($constraint->message, ['%bundle' => $entity_type . ':' . $field_name]);
          return;
        }
      }
    }
  }

}
