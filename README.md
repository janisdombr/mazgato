# Custom code for EspoCRM (car-wash CRM)

This repository is the **Corresponding Source** of the code that runs inside EspoCRM in a car-wash management installation
(GNU Affero General Public License v3 or later, see `LICENSE`). It is offered to everyone who uses such an installation over a
network, as AGPL section 13 requires.

It is not a standalone program and not a full installation: it adds entities, hooks, controllers, tools, views and translations
to an existing [EspoCRM](https://www.espocrm.com) (tested on 10.0.3) and contains no data, no credentials and no deployment
tooling.

    custom/          -> EspoCRM's `custom/` directory   (PHP, metadata, layouts, translations)
    client/custom/   -> EspoCRM's `client/custom/`      (views, dashlets, libraries, themes)

Built from the private source tree at revision `08986b7e8e2b` on 2026-10-14. The EspoCRM core itself is not included; get it, and its
licence notices, from the EspoCRM project. EspoCRM is a registered trademark of EspoCRM, Inc.; this code is not made, endorsed
or supported by EspoCRM, Inc.

Issues and patches are welcome as pull requests. Contributions are accepted under the same licence.
