# LibEuFin Connector for [Dolibarr ERP & CRM](https://www.dolibarr.org)

## Features

LibEuFin Connector is a Dolibarr module for importing, matching, and staging LibEuFin/Nexus bank transactions.

It covers incoming customer payments, incoming supplier refunds, outgoing supplier payments, and outgoing customer
refunds, while keeping deterministic transaction records for reconciliation and deduplication.

Project repository: [bohdanpotuzhnyi/dolibarr-libeufinconnector](https://github.com/bohdanpotuzhnyi/dolibarr-libeufinconnector).

<!--
![Screenshot libeufinconnector](img/screenshot_libeufinconnector.png?raw=true "LibEuFinConnector"){imgmd}
-->

Other external modules are available on [Dolistore.com](https://www.dolistore.com).

## Translations

Translations can be completed manually by editing files in the module directories under `langs`.

<!--
This module contains also a sample configuration for Transifex, under the hidden directory [.tx](.tx), so it is possible to manage translation using this service.

For more information, see the [translator's documentation](https://wiki.dolibarr.org/index.php/Translator_documentation).

There is a [Transifex project](https://transifex.com/projects/p/dolibarr-module-template) for this module.
-->


## Installation

Prerequisites: You must have Dolibarr ERP & CRM software installed. You can download it from [Dolistore.org](https://www.dolibarr.org).
You can also get a ready-to-use instance in the cloud from https://saas.dolibarr.org

For full functionality, make sure `libeufin-nexus` is installed.


### From the ZIP file and GUI interface

If the module is a ready-to-deploy zip file, so with a name `module_xxx-version.zip` (e.g., when downloading it from a
marketplace like [Dolistore](https://www.dolistore.com)), go to menu `Home> Setup> Modules> Deploy external module` and upload the zip file.

### Final steps

Using your browser:

  - Log into Dolibarr as a super-administrator
  - Go to "Setup"> "Modules"
  - You should now be able to find and enable the module
  - Go to module setup to finish configuration

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readme's are licensed under [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).
