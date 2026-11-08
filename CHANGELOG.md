# Changelog

The version is the one of the monorepo `mono-build-knowledge`.

## 1.0.2

- `check`: the hooks of other modules a module listens to, declared in `ModuleDefinition::OTHER_MODULE_HOOKS` as hook =>
  module (`'registerGDPRConsent' => 'psgdpr'`; keys as string literals or `self::NAME` string constants), are no
  PrestaShop hooks either; other unknown hooks stay an error (found on `heavenpricedropalert`). `PROVIDED_HOOKS` is now
  read per array entry: a nested array or a keyed entry in it is ignored.

## 1.0.1

- `check`: the hooks a module declares in `ModuleDefinition::PROVIDED_HOOKS` (string literals and `self::NAME` string
  constants of the same class) are its own, like the hooks it executes itself; other unknown hooks stay an error (found
  on `heavencartjourney`, whose hook `actionHeavenCartTrack` other modules call).

## 1.0.0

- First version: the generator per PrestaShop release (classes, methods, constants, deprecations, hooks with aliases and run-time patterns, services, `PS_*` keys, tables, vendor packages), the merged symbol file, `find` and the module `check`; data for 8.1.0, 8.2.8 and 9.2.0.
- `check`: a class the same file tests with `class_exists()` (or `interface_exists`, `trait_exists`, `enum_exists`) is a
  warning where it is missing (version-aware code), and a hook only the newer releases of the range have is a warning
  (HK-09); a hook a newer release removed stays an error (found on the pilot `heavenupdater`).
