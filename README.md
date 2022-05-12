# Drupal Azure FS

![CI](https://github.com/City-of-Helsinki/drupal-module-helfi-azure-fs/workflows/CI/badge.svg)

Azure's NFS file mount doesn't support certain file operations (such as chmod), causing any request that performs them to give an 5xx error, like when trying to generate an image style.

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

## Contact

Slack: #helfi-drupal (http://helsinkicity.slack.com/)

Mail: helfi-drupal-aaaactuootjhcono73gc34rj2u@druid.slack.com
