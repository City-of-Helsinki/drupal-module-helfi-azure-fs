# Drupal Azure FS

![CI](https://github.com/City-of-Helsinki/drupal-module-helfi-azure-fs/workflows/CI/badge.svg) [![codecov](https://codecov.io/gh/City-of-Helsinki/drupal-module-helfi-azure-fs/branch/main/graph/badge.svg?token=46YWS8J8NN)](https://codecov.io/gh/City-of-Helsinki/drupal-module-helfi-azure-fs)

Provides various fixes to deal with Azure's NFS mount and an integration to Azure's Blob storage service using [flysystem](https://www.drupal.org/project/flysystem).

Azure's NFS file mount does not support certain file operations (such as chmod), causing any request that performs them to give a 5xx error, like when trying to generate an image style.

This module decorates core's `file_system` service to skip unsupported file operations when the site is operating on Azure environment.

## Requirements

- PHP 8.1 or higher

## Usage

Enable the module.

### Using Azure Blob storage to host all files (optional)

- Populate required environment variables:
```
AZURE_BLOB_STORAGE_CONTAINER: The container name
AZURE_BLOB_STORAGE_NAME: The blob storage name
BLOBSTORAGE_ACCOUNT_KEY: The blob storage secret
```

or if you're using SAS token authentication:

```
BLOBSTORAGE_SAS_TOKEN: The SAS token
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
      'container' => '[ insert container name here ]',
      'endpointSuffix' => 'core.windows.net',
      'protocol' => 'https',
    ],
    'cache' => TRUE,
  ],
];
$config['helfi_azure_fs.settings']['use_blob_storage'] = TRUE;
$settings['flysystem'] = $schemes;
$settings['is_azure'] = TRUE;
```

The correct values can be found by running `printenv | grep BLOB` inside a OpenShift Drupal pod.

## Contact

Slack: #helfi-drupal (http://helsinkicity.slack.com/)
