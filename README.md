# Drupal Azure FS

![CI](https://github.com/City-of-Helsinki/drupal-module-helfi-azure-fs/workflows/CI/badge.svg)

Provides file system fixes for Azure.

## Requirements

- PHP 7.4 or higher

## Usage

Enable the module.

### Blob storage

Configure Flysystem:

```
$schemes = [
  'azure' => [
    'driver' => 'helfi_azure',
    'config' => [
      'name' => 'your-storage-account-name',
      'key' => 'your-key',
      'container' => 'your-container-name',
      'endpointSuffix' => 'core.windows.net',
      'protocol' => 'https',
    ],
    'cache' => TRUE,
    // Enable these to serve js and css files from blob storage.
    'serve_js' => TRUE,
    'serve_css' => TRUE,
  ],
];
$settings['flysystem'] = $schemes;

// This overrides all field and image fields to use blob storage
// by default.
// @see helfi_azure_fs_entity_field_storage_info_alter().
$config['helfi_azure_fs.settings']['use_blob_storage'] = TRUE;
```

## Contact

Slack: #helfi-drupal (http://helsinkicity.slack.com/)

Mail: helfi-drupal-aaaactuootjhcono73gc34rj2u@druid.slack.com
