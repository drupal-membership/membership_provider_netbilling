<?php

namespace Drupal\membership_provider_netbilling\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'netbilling_site_config' field type.
 *
 * @FieldType(
 *   id = "netbilling_site_config",
 *   label = @Translation("NETBilling site config"),
 *   description = @Translation("NETBilling Site Config Field"),
 *   default_widget = "netbilling_site_config",
 *   default_formatter = "netbilling_site_config"
 * )
 */
class NetbillingSiteConfig extends FieldItemBase {
  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return array(
      'max_length' => 255,
      'is_ascii' => TRUE,
      'case_sensitive' => TRUE,
    ) + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $max_length = $field_definition->getSetting('max_length');
    // Prevent early t() calls by using the TranslatableMarkup.
    $properties['account_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Account ID'))
      ->setSetting('case_sensitive', $field_definition->getSetting('case_sensitive'))
      ->addConstraint('Length', array('max' => $max_length))
      ->setRequired(TRUE);
    $properties['site_tag'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Site Tag'))
      ->setSetting('case_sensitive', $field_definition->getSetting('case_sensitive'))
      ->addConstraint('Length', array('max' => $max_length))
      ->addConstraint('NetbillingUniqueSite')
      ->setRequired(TRUE);
    $properties['access_keyword'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Access Keyword'))
      ->setSetting('case_sensitive', $field_definition->getSetting('case_sensitive'))
      ->addConstraint('Length', array('max' => $max_length))
      ->setRequired(TRUE);
    $properties['retrieval_keyword'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Data Retrieval Keyword'))
      ->setSetting('case_sensitive', $field_definition->getSetting('case_sensitive'))
      ->addConstraint('Length', array('max' => $max_length))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $schema = array(
      'columns' => array(
        'account_id' => array(
          'type' => $field_definition->getSetting('is_ascii') === TRUE ? 'varchar_ascii' : 'varchar',
          'length' => (int) $field_definition->getSetting('max_length'),
          'binary' => $field_definition->getSetting('case_sensitive'),
        ),
        'site_tag' => array(
          'type' => $field_definition->getSetting('is_ascii') === TRUE ? 'varchar_ascii' : 'varchar',
          'length' => (int) $field_definition->getSetting('max_length'),
          'binary' => $field_definition->getSetting('case_sensitive'),
        ),
        'access_keyword' => array(
          'type' => $field_definition->getSetting('is_ascii') === TRUE ? 'varchar_ascii' : 'varchar',
          'length' => (int) $field_definition->getSetting('max_length'),
          'binary' => $field_definition->getSetting('case_sensitive'),
        ),
        'retrieval_keyword' => array(
          'type' => $field_definition->getSetting('is_ascii') === TRUE ? 'varchar_ascii' : 'varchar',
          'length' => (int) $field_definition->getSetting('max_length'),
          'binary' => $field_definition->getSetting('case_sensitive'),
        ),
      ),
    );

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $values['site_tag'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['account_id'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['access_keyword'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    $values['retrieval_keyword'] = $random->word(mt_rand(1, $field_definition->getSetting('max_length')));
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $account_id = $this->get('account_id')->getValue();
    $site_id = $this->get('site_tag')->getValue();
    $access_keyword = $this->get('access_keyword')->getValue();
    $retrieval_keyword = $this->get('retrieval_keyword')->getValue();
    return empty($account_id) && empty($site_id) && empty($access_keyword) && empty($retrieval_keyword);
  }

}
