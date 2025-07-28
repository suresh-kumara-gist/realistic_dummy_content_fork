<?php

namespace Drupal\realistic_dummy_content_ai\Includes;

use Drupal\Component\Utility\Random;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\realistic_dummy_content_api\Framework\Framework;
use Drupal\realistic_dummy_content_api\traits\RealisticDummyContentDrupalTrait;
use Drupal\realistic_dummy_content_api\includes\RealisticDummyContentImageField;

/**
 * AI-powered field modifier for image fields.
 *
 * Generates realistic dummy images using AI based on field configuration.
 */
class RealisticDummyContentAIImageField extends RealisticDummyContentImageField {

  /**
   * Gets the field configuration for the specified field.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\field\Entity\FieldConfig|null
   *   The field configuration object or NULL if not found.
   */
  protected function getFieldConfig(string $entity_type_id, string $bundle, string $field_name): ?object {
    return FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
  }

  /**
   * Gets the help text from field configuration.
   *
   * @param \Drupal\field\Entity\FieldConfig|null $field_config
   *   The field configuration object.
   *
   * @return string
   *   The field help text or empty string if not available.
   */
  protected function getFieldHelpText(?object $field_config): string {
    return $field_config ? $field_config->getDescription() : '';
  }

  /**
   * Gets widget settings for the field.
   *
   * @param \Drupal\field\Entity\FieldConfig|null $field_config
   *   The field configuration object.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   * @param string $field_name
   *   The field name.
   *
   * @return array
   *   An array of widget settings.
   */
  protected function getFieldWidgetSettings(?object $field_config, string $entity_type_id, string $bundle, string $field_name): array {
    if (!$field_config) {
      return [];
    }

    $form_display = EntityFormDisplay::load($entity_type_id . '.' . $bundle . '.default');
    if (!$form_display) {
      return [];
    }

    $component = $form_display->getComponent($field_name);
    return ($component && isset($component['settings']['file_extensions'])) ? $component['settings'] : [];
  }

  /**
   * Gets a random image size from widget settings.
   *
   * @param array $widget_settings
   *   The widget settings array.
   *
   * @return string
   *   The selected image size in WxH format.
   */
  protected function getImageSize(array $widget_settings): string {
    $min_resolution = $widget_settings['min_resolution'] ?? '1024x1024';
    $max_resolution = $widget_settings['max_resolution'] ?? '1024x1024';

    return $min_resolution === $max_resolution 
      ? $min_resolution 
      : $min_resolution . ',' . $max_resolution;
  }

  /**
   * Gets the cardinality for the field.
   *
   * @param \Drupal\field\Entity\FieldConfig|null $field_config
   *   The field configuration object.
   *
   * @return int
   *   The field cardinality.
   */
  protected function getCardinality(?object $field_config): int {
    if (!$field_config) {
      return 1;
    }

    $cardinality = $field_config->getFieldStorageDefinition()->getCardinality();
    return ($cardinality == -1) ? random_int(1, 5) : $cardinality;
  }

  /**
   * Gets the file directory path for the field.
   *
   * @param \Drupal\field\Entity\FieldConfig|null $field_config
   *   The field configuration object.
   *
   * @return string
   *   The resolved file directory path.
   */
  protected function getFileDirectory(?object $field_config): string {
    $raw_directory = $field_config ? $field_config->getSetting('file_directory') : '';
    $directory = $raw_directory ? \Drupal::token()->replace($raw_directory) : 'ai-images';

    return 'public://' . $directory;
  }

  /**
   * Generates the user prompt for AI image generation.
   *
   * @param string $help_text
   *   The field help text.
   * @param string $content_type
   *   The content type machine name.
   *
   * @return string
   *   The generated prompt.
   */
  protected function getUserPrompt(string $help_text, string $content_type): string {
    return $help_text ?: 'Randomly Generate image of ' . $content_type;
  }

  /**
   * {@inheritdoc}
   */
  public function change(): void {
    $entity = $this->getEntity()->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $field_name = $this->getName();

    $field_config = $this->getFieldConfig($entity_type_id, $bundle, $field_name);
    $widget_settings = $this->getFieldWidgetSettings($field_config, $entity_type_id, $bundle, $field_name);

    $prompt = $this->getUserPrompt(
      $this->getFieldHelpText($field_config),
      $bundle
    );

    try {
      $sets = \Drupal::service('ai.provider')->getDefaultProviderForOperationType('text_to_image');
      $provider = \Drupal::service('ai.provider')->createInstance($sets['provider_id']);

      $provider->setConfiguration([
        "n" => $this->getCardinality($field_config),
        "response_format" => "url",
        "accept" => "image/png",
        "image_size" => $this->getImageSize($widget_settings),
        "quality" => "standard",
        "style" => "vivid",
        "cfg_scale" => random_int(7, 14),
        "sampler" => "K_DPMPP_2M",
        "steps" => 30,
        "safety_check" => TRUE,
        "prompt_cache_max_len" => 0  # Disable prompt caching
      ]);

      $input = new TextToImageInput($prompt);
      $images = $provider->textToImage($input, $sets['model_id'])->getNormalized();

      $destination = $this->getFileDirectory($field_config);
      \Drupal::service('file_system')->prepareDirectory(
        $destination,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
      );

      $images_items = [];
      foreach ($images as $image) {
        $filename = md5(microtime()) . '.png';
        $file_uri = $destination . '/' . $filename;

        \Drupal::service('file_system')->saveData($image->getBinary(), $file_uri, FileSystemInterface::EXISTS_REPLACE);

        $file = File::create(['uri' => $file_uri, 'status' => FileInterface::STATUS_PERMANENT]);
        $file->save();

        $images_items[] = [
          'target_id' => $file->id(),
          'alt' => 'Generated image',
          'title' => 'Generated image',
        ];

      }

      Framework::instance()->setEntityProperty($entity, $field_name, $images_items);
      $this->getEntity()->setEntity($entity);
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_image')->error('AI image generation failed: @message', ['@message' => $e->getMessage()]);
      \Drupal::messenger()->addError('Failed to generate AI image.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function implementValueFromFile($file): array {
    return [];
  }

}