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

For other version:
```bash
gh repo clone dropday-io/PrestaShop .
docker compose down --volumes && export TAG=1.7-apache && docker compose up
```

```bash
docker compose down --volumes && export TAG=8.1.7-8.1-apache && docker compose up
```

## Configuration via terminal

This is only for PrestaShop 8.x.

### 2. Install the Dropday module

```bash
docker compose exec apache php bin/console prestashop:module install dropday
```

### 3. Configure the API key and Account ID

```bash
docker compose exec apache php bin/console prestashop:config set DROPDAY_ACCOUNT_APIKEY "your_api_key_here"
docker compose exec apache php bin/console prestashop:config set DROPDAY_ACCOUNT_ID "your_account_id_here"
docker compose exec apache php bin/console prestashop:config set DROPDAY_LIVE_MODE 1
```

## Testing with PHPUnit

The module includes PHPUnit tests to verify functionality. To run the tests:

1. Install Composer in the Docker container:
```bash
docker compose exec apache bash -c "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"
```

2. Install composer dependencies:
```bash
docker compose exec apache bash -c "cd /var/www/html/modules/dropday && composer install"
```

3. Run all tests:
```bash
docker compose exec apache bash -c "cd /var/www/html/modules/dropday && vendor/bin/phpunit"
```

4. Run a specific test:
```bash
docker compose exec apache bash -c "cd /var/www/html/modules/dropday && vendor/bin/phpunit tests/unit/OrderCreationTest.php"
```
