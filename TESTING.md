# Testing

This package comes with an extensive automated testing suite, which is automatically run for every
pull request in GitHub.

## CodeSniffer

This tool helps to find and fix code style issues in this package.
To run CodeSniffer tests first ensure that you installed the required
packages using `composer install`. Then run `composer test:style`.


To run the automated fixing of most of the styling issues, you can also execute `composer fix:style`.

## PHPStan

This tool helps to find obvious bugs in your PHP code.
To run PHPStan first ensure that you installed the required
packages using `composer install`. Then run `composer test:stan`.

## Unit Testing

To run unit tests first ensure that you installed the required
packages using `composer install`. Then run `composer test:unit`.

## Functional Testing

To run functional tests on your local machine, install this package in a Neos 9 installation
(any distribution works, e.g. the Neos.Demo base distribution).
Instructions on how to do that can be found here: https://docs.neos.io/guide/installation-development-setup

Requirements (Neos 9):

- **MariaDB/MySQL test database** — the event-sourced content graph does not support SQLite,
  which many distributions configure as the Testing default. Point the Testing context to a
  dedicated, empty MariaDB/MySQL database in your project's `Configuration/Testing/Settings.yaml`
  (the tests create their schema themselves; never reuse your Development database):

  ```yaml
  Neos:
    Flow:
      persistence:
        backendOptions:
          driver: pdo_mysql
          charset: utf8mb4
          host: db
          user: db
          password: db
          dbname: 'flow_functional_testing'
  ```

- **Content dimensions**: the suite adapts to your distribution's language dimension at
  runtime (see `Tests/Functional/FunctionalTestCase`). Tests that need language variants
  are skipped automatically when the distribution configures fewer than two top-level
  language values; distributions without dimensions run the dimension-independent tests.

Once you have done that, you can run the functional tests by executing the following command *in the folder of the Neos installation*:

```shell
FLOW_CONTEXT=Testing bin/phpunit --colors --stop-on-failure -c DistributionPackages/NEOSidekick.AiAssistant/Tests/FunctionalTests.xml --testsuite "NEOSidekick.AiAssistant" --verbose
```

