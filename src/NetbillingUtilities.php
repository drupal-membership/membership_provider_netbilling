<?php

namespace Drupal\membership_provider_netbilling;

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

}
