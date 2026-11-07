# prestashopheaven/build-prestashop-index

The PrestaShop index per release: classes, methods, constants, deprecations, hooks, services, configuration keys,
tables and shipped vendor packages, with a search (`ps-module ps find`) and a check of a module against every release
in its range (`ps-module ps check`).

## Use when

Before using or writing PrestaShop code: does PrestaShop already have it and in which releases
(`ps-module ps find "<term>"`); and to check that every PrestaShop class, method, constant and hook a module uses exists
in every release of its range (`ps-module ps check`).

## Install

```
composer require --dev prestashopheaven/build-prestashop-index
```

## Usage

```
php bin/prestashop-index.php find "order status"            # classes, methods, hooks, services, PS_* keys, tables
php bin/prestashop-index.php find "Order::getBy"             # members of a class
php bin/prestashop-index.php check <module-dir>              # range from module.spec.json (ps_min, ps_max)
php bin/prestashop-index.php check <module-dir> --ps-min=8.1.0 --ps-max=8.2.8 --json
php bin/prestashop-index.php generate <prestashop-dir> 9.2.0 # index one release and merge the symbol file
php bin/prestashop-index.php merge | versions
```

`check` exits with 1 when a symbol is missing in a release of the range (error); deprecations, signatures that differ
between releases and hooks only known by an alias are warnings.

What `check` sees: classes (use, new, extends, implements, instanceof, catch, fully qualified names), static calls and
constants (`X::m()`, `X::C`), calls to a parent (`parent::`, `self::`, `static::`, `$this->` in a class or trait of the
module when the module does not declare the method), PrestaShop's global functions (`pSQL`), and the hooks of
`ModuleDefinition::HOOKS`, `registerHook()` and `hook<Name>()` methods. Calls on objects (`$order->getProducts()`) need
type inference and are not checked; PHPStan covers them against two releases.

## Data

`data/ps-<version>.json.gz` per release and the merged `data/symbols.json.gz` (symbol => the releases it exists in,
deprecated in, and its signature per release when it changes). `--data=DIR` or `PS_INDEX_DIR` reads another folder (for
data downloaded from a GitHub release). The releases are fetched with
`.claude/skills/ps-module/scripts/fetch-prestashop.php <version>`; a scheduled CI job adds each new release.

---

Read-only split of [`prestashopheaven/mono-build-knowledge`](https://github.com/prestashopheaven/mono-build-knowledge): changes,
issues and pull requests go there. MIT License.
