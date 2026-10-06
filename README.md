# Drupal Azure FS

![CI](https://github.com/City-of-Helsinki/drupal-module-helfi-azure-fs/workflows/CI/badge.svg) [![Coverage](https://sonarcloud.io/api/project_badges/measure?project=City-of-Helsinki_drupal-module-helfi-azure-fs&metric=coverage)](https://sonarcloud.io/summary/new_code?id=City-of-Helsinki_drupal-module-helfi-azure-fs)

Provides various fixes to deal with Azure's NFS mount and an `azure://` stream wrapper that stores the files in Azure's Blob storage service.

Azure's NFS file mount does not support certain file operations (such as chmod), causing any request that performs them to give a 5xx error, like when trying to generate an image style.

This module decorates core's `file_system` service to skip unsupported file operations when the site is operating on Azure environment.

## Requirements

- PHP 8.3 or higher

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

### Configuration

The blob storage is configured in `settings.php`:

```php
$settings['helfi_azure_fs'] = [
  'name' => '[ insert account name here ]',
  'container' => '[ insert container name here ]',
  // Either a SAS token, an account key or a connection string.
  'token' => '[ insert sas token here ]',
  // The URL the files are served from.
  'public_url_base' => 'https://[ insert account name here ].blob.core.windows.net/[ insert container name here ]',
];
$config['helfi_azure_fs.settings']['use_blob_storage'] = TRUE;
// Serve the image style derivatives without the file access checks, like the
// public:// ones.
$settings['file_additional_public_schemes'] = ['azure'];
$settings['is_azure'] = TRUE;
```

The `azure://` stream wrapper is only registered when the settings are defined. Rebuild the caches after changing them.

The correct values can be found by running `printenv | grep BLOB` inside a OpenShift Drupal pod.

### How it works

- `BlobStorage` talks to the container with [azure-oss/storage-blob](https://packagist.org/packages/azure-oss/storage-blob). It checks files and directories with a single request and caches the results for the duration of the request.
- `AzureStreamWrapper` provides the `azure://` scheme, so the core file system works with it as is.
- Existing image style derivatives are served directly from the blob storage. The missing ones are routed through Drupal, which generates them and redirects to the blob storage.
- Directories exist implicitly as the prefixes of the blob names, so empty directories don't exist.
- Files are buffered into a temporary stream: reading downloads the whole blob and writing uploads it when the stream is closed.

### Running tests on local

`tests/src/Kernel/AzureBlobStorageTest.php` runs against a real storage account and requires a `/app/.secrets.json` file:

```json
{
  "flysystem_azure_connection_string": "BlobEndpoint=https://stplattadevtest.blob.core.windows.net;SharedAccessSignature=[ insert sas token here ];",
  "flysystem_azure_container_name": "[ container name ]"
}
```

You can find these values from your `local.settings.php` file. The test files are written under `test/` in the container and deleted afterwards.

## Upgrading to 3.x

Earlier versions used the [Flysystem](https://www.drupal.org/project/flysystem) module.

- Run the database updates: `helfi_azure_fs_update_90401()` uninstalls the Flysystem module. Export the configuration afterwards, so the deployment doesn't reinstall it.
- Replace `$settings['flysystem']['azure']` with `$settings['helfi_azure_fs']` in `settings.php`: move the `config` values and `public_url_base` to the top level. The Flysystem settings are no longer used, so the blob storage isn't enabled until this is done. Keep `$settings['file_additional_public_schemes'] = ['azure'];`.
- The file URIs (`azure://...`) and the blob names don't change, so no files need to be migrated.

## Contact

Slack: #helfi-drupal (http://helsinkicity.slack.com/)
