<?php

namespace Drupal\membership_provider_netbilling\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Provides automated tests for the membership_provider_netbilling module.
 */
class HtpasswdControllerTest extends WebTestBase {
  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => "membership_provider_netbilling HtpasswdController's controller functionality",
      'description' => 'Test Unit for module membership_provider_netbilling and controller HtpasswdController.',
      'group' => 'Other',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * Tests membership_provider_netbilling functionality.
   */
  public function testHtpasswdController() {
    // Check that the basic functions of module membership_provider_netbilling.
    $this->assertEquals(TRUE, TRUE, 'Test Unit Generated via App Console.');
  }

}
