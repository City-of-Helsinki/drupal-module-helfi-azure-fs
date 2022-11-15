# Drupal Azure FS

![CI](https://github.com/City-of-Helsinki/drupal-module-helfi-azure-fs/workflows/CI/badge.svg)

Azure's NFS file mount does not support certain file operations (such as chmod), causing any request that performs them to give a 5xx error, like when trying to generate an image style.

This module decorates core's `file_system` service to skip unsupported file operations when the site is operating on Azure environment.

## Requirements

- PHP 8.0 or higher

## Usage

Enable the module.

### Using Azure Blob storage to host all files (optional)

- Enable `flysystem_azure` module: `drush en flysystem_azure`
- Populate required environment variables:
```
AZURE_BLOB_STORAGE_CONTAINER: The container name
AZURE_BLOB_STORAGE_KEY: The blob storage secret
AZURE_BLOB_STORAGE_NAME: The blob storage name
```

or if you're using SAS token authentication:

```
AZURE_BLOB_STORAGE_SAS_TOKEN: The SAS token
AZURE_BLOB_STORAGE_NAME: The blob storage name
```

### Testing on local

Add something like this to your `local.settings.php` file:

```php
$schemes = [
  'azure' => [
    'driver' => 'helfi_azure',
    'config' => [
      'name' => '[ insert account name here ]',
      'token' => '[ insert sas token here ]',
      'endpointSuffix' => 'core.windows.net',
      'protocol' => 'https',
    ],
    'cache' => TRUE,
  ],
];
$config['helfi_azure_fs.settings']['use_blob_storage'] = TRUE;
$settings['flysystem'] = $schemes;
```

The correct values can be found by running `printenv | grep AZURE_BLOB_STORAGE` inside a OpenShift Drupal pod.

## Contact

Slack: #helfi-drupal (http://helsinkicity.slack.com/)

Mail: helfi-drupal-aaaactuootjhcono73gc34rj2u@druid.slack.com
