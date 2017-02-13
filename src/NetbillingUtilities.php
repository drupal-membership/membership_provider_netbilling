<?php

namespace Drupal\membership_provider_netbilling;

use Drupal\Core\Datetime\DrupalDateTime;

final class NetbillingUtilities {

  /**
   * Helper function to parse a query string in a Perl-like fashion.
   * The model Netbilling CGI script uses Perl's param() method for this.
   *
   * @see http://www.php.net/manual/en/function.parse-str.php#76792
   * @see http://perldoc.perl.org/CGI.html#FETCHING-THE-VALUE-OR-VALUES-OF-A-SINGLE-NAMED-PARAMETER:
   *
   * @param string $str The string to parse
   *
   * @return array The parsed string.
   */
  public static function parse_str_multiple($str) {
    $arr = array();

    $pairs = explode('&', $str);

    // Loop through each pair
    foreach ($pairs as $i) {
      // Split into name and value
      list($name,$value) = explode('=', $i, 2);
      $value = urldecode($value);

      // If name already exists
      if (isset($arr[$name])) {
        // Stick multiple values into an array
        if (is_array($arr[$name])) {
          $arr[$name][] = $value;
        }
        else {
          $arr[$name] = array($arr[$name], $value);
        }
      }
      // Otherwise, simply stick it in a scalar
      else {
        $arr[$name] = $value;
      }
    }

    return $arr;
  }

  /**
   * Helper function to construct a NETBilling recurring period string.
   *
   * @param $interval array Interval array as returned by interval.module
   * @param $strategy string 'code' or 'days', the type of value that should be returned.
   *
   * @throws \LogicException
   *
   * @returns string Contents for the recurring_period parameter.
   */
  public static function interval_code($interval, $strategy = 'code') {
    switch ($interval['period']) {
      case 'day':
        $duration = $interval['interval'];
        break;
      case 'month':
        // For initial terms, Netbilling only takes the term in days.
        // For recurring periods, it will accept a "button maker expression"
        if ($strategy == 'days') {
          $end = new DrupalDateTime('now + ' . $interval['interval'] . ' months');
          $now = new DrupalDateTime();
          $duration = $end->diff($now)->days;
        }
        else {
          // As determined by Netbilling button maker.
          // This uses the "same day every month" approach, not month == 30 days.
          $duration = 'add_months((trunc(sysdate)+0.5),' . $interval['interval'] . ')';
        }
        break;
      case 'week':
        $duration = $interval['interval'] * 7;
        break;
      default:
        throw new \LogicException('Invalid interval period specified.');
    }
    return $duration;
  }

}
