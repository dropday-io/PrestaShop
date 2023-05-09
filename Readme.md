PrestaShop Module for Dropday
===============

## How to install the module?

* Download the .zip file from GitHub repository;
* Go to the backoffice of your PrestaShop webshop;
* In your backoffice, go to 'Modules' and then 'Module Manager' and choose 'Upload a module';
* Click on 'select file' and upload the .zip file.

## Configuration

Modules &rarr; Module Manager &rarr; Dropday

* After the module has been installed, click on 'Configure';
* Enter your API-key and Account ID from your Dropday Dashboard.

## Docker

For development or demo purposes you can run Docker to test this integration.

For the latest PrestaShop:
```bash
gh repo clone dropday-io/PrestaShop .
docker compose up
```

For PrestaShop 1.6, run:

```bash
gh repo clone dropday-io/PrestaShop .
docker compose -f docker-compose-16.yml up
```
