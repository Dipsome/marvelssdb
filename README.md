MarvelsDB
=======

# Very quick guide on how to install a local copy

This guide assumes you know how to use the command-line and that your machine has php and mysql installed.

- install composer: https://getcomposer.org/download/
  - NOTE: use version 1.10.26 (the last release of version 1)
- Git clone the repo and `cd` to it
- run `composer install` (at the end it will ask for the database configuration parameters)
- run `php bin/console doctrine:database:create`
- run `php bin/console doctrine:schema:create`
- Git clone the card data from https://github.com/zzorba/marvelsdb-json-data
- run `php bin/console app:import:std path-to-marvelsdb-json-data/` pointing to where you cloned the json data (can be a relative path)
- run `php bin/console server:run`
