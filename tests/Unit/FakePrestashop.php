<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShopHeaven\Build\PrestashopIndex\Tests\Unit;

/**
 * Throw-away PrestaShop release folders (8.1.0 and 9.0.0, a small but complete layout) and a module that uses them, in
 * the temp folder. Between the releases: Order::getByReference() changes its signature, Order::oldWay() (deprecated in
 * 8.1.0) is removed, Order::newWay() and the hook actionNewInNine are added, and the vendor list comes from
 * vendor/composer/installed.json instead of composer.lock.
 */
final class FakePrestashop
{
    /** The temp folder with everything. */
    public readonly string $dir;

    /**
     * Creates the folder.
     */
    public function __construct()
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/psindex-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);
    }

    /**
     * Writes a release folder; returns it.
     */
    public function release(string $version): string
    {
        $nine = version_compare($version, '9.0.0', '>=');
        $root = "{$this->dir}/ps-$version";
        $this->file("$root/classes/ObjectModel.php", <<<'PHP'
<?php
abstract class ObjectModelCore
{
    public const TABLE = '';

    private const SECRET = 1;

    public function __construct($id = null)
    {
    }

    public function save()
    {
        Hook::exec('actionObject' . get_class($this) . 'AddAfter', ['object' => $this]);
        Hook::exec('action' . get_class($this));
        Hook::exec('bad name' . $this->id . 'Suffix');
        Hook::exec('action' . $this->id . 'X');
        Hook::exec($this->hookName);
        Hook::exec(sprintf('x%s', 'y'));
        Hook::exec('actionA' /* joined */ . 'B');
        Hook::exec ('actionSpaced');

        return true;
    }

    public static function existsInDatabase($id, $table)
    {
        return true;
    }

    protected function hidden()
    {
    }

    private function secret()
    {
    }
}
PHP);
        $signature = $nine ? 'string $reference): ?self' : '$reference)';
        $oldWay = $nine ? "    public function newWay()\n    {\n        Hook::exec('actionNewInNine');\n    }\n" : "    /**\n     * @deprecated since 8.0, use newWay()\n     */\n    public function oldWay()\n    {\n    }\n";
        $this->file("$root/classes/order/Order.php", <<<PHP
<?php
/**
 * An order.
 */
class OrderCore extends ObjectModel
{
    const STATE = 1;

    public static function getByReference($signature
    {
        Hook::exec('actionOrderRead');

        return null;
    }

$oldWay}
PHP);
        if ($nine) {
            $this->file("$root/classes/NineOnly.php", '<?php
class NineOnlyCore
{
    public function run()
    {
    }
}
');
        }
        $this->file("$root/classes/Hook.php", "<?php\nclass HookCore\n{\n    public static function exec(\$hook_name, \$hook_args = [])\n    {\n    }\n}\nclass Core\n{\n}\n");
        $this->file("$root/classes/Tools.php", "<?php\nclass ToolsCore\n{\n    public static function __callStatic(\$name, \$arguments)\n    {\n    }\n}\nclass Tools extends ToolsCore\n{\n}\nclass ToolsCore\n{\n}\n");
        $this->file("$root/classes/controller/FrontController.php", <<<'PHP'
<?php
class FrontControllerCore extends Controller
{
    public function init()
    {
        $this->hookDispatcher?->dispatchWithParameters('actionFrontInit', []);
        Hook::execWithoutCache("display{$this->name}Cart");
        $this->hookDispatcher->dispatchWithParameters;
    }
}
PHP);
        $this->file("$root/controllers/front/CartController.php", "<?php\nclass CartControllerCore extends FrontController\n{\n}\n");
        $this->file("$root/src/Core/Hook/HookDispatcherInterface.php", <<<'PHP'
<?php
namespace PrestaShop\PrestaShop\Core\Hook;

/**
 * Dispatches hooks.
 */
interface HookDispatcherInterface
{
    public function dispatchHook(HookInterface $hook);
}
PHP);
        $this->file("$root/src/Adapter/Hook/HookDispatcher.php", <<<'PHP'
<?php
namespace PrestaShop\PrestaShop\Adapter\Hook;

use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;

/**
 * @deprecated since 9.0
 */
class HookDispatcher extends BaseDispatcher implements HookDispatcherInterface
{
    use DispatchTrait;

    public function dispatchHook($hook)
    {
        $this->inner->dispatchHook(new Hook('actionCoreHook', []));
        $this->inner->dispatchRendering(new RenderingHook($this->name));
    }
}

abstract class BaseDispatcher implements HookDispatcherInterface
{
}

trait DispatchTrait
{
    public function helper(): void
    {
    }
}
PHP);
        $this->file("$root/src/PrestaShopBundle/Controller/Admin/PlainController.php", "<?php\nnamespace PrestaShopBundle\\Controller\\Admin;\n\nclass PlainController\n{\n    public function run(): string\n    {\n        return 'PS_SHOP_NAME' . 'PS_FROM_CODE' . 'not_a_key';\n    }\n}\n");
        $this->file("$root/config/alias.php", "<?php\nfunction pSQL(\$string, \$htmlOK = false)\n{\n}\n/** @deprecated */\nfunction bqSQL(\$string)\n{\n}\n");
        $this->file("$root/src/PrestaShopBundle/Resources/config/services.yml", <<<'YAML'
# The services.
parameters:
    foo: bar

services:
    _defaults:
        public: true

    prestashop.core.hook.dispatcher:
        class: PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher
        arguments: ['@foo']

    'prestashop.quoted':
        class: 'PrestaShop\PrestaShop\Core\Quoted'

    prestashop.short.alias: '@prestashop.core.hook.dispatcher'

    prestashop.long.alias:
        alias: prestashop.core.hook.dispatcher

    PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface: ~

    PrestaShopBundle\Controller\:
        resource: '../../Controller/*'

    - not a key

imports:
    - { resource: other.yml }
YAML);
        $this->file("$root/app/config/config.yml", "services:\n  app.service:\n    class: App\\Service\n");
        $this->file("$root/config/services/admin.yaml", "services:\n    admin.one: ~\n");
        $this->file("$root/install/data/xml/hook.xml", '<?xml version="1.0"?><entity_hook><entities>'
            . '<hook id="actionValidateOrder"><name>actionValidateOrder</name><title>New orders &amp; more</title><description/></hook>'
            . '<hook id="displayFooter"><name>displayFooter</name><title>Footer</title></hook>'
            . '<hook id="actionOrderRead"><name>actionOrderRead</name><title></title></hook>'
            . '<hook id="noName"><title>x</title></hook>'
            . '</entities></entity_hook>');
        $this->file("$root/install/data/xml/hook_alias.xml", '<?xml version="1.0"?><entity_hook_alias><entities>'
            . "<hook_alias id=\"newOrder\">\n<alias>newOrder</alias>\n<name>actionValidateOrder</name>\n</hook_alias>"
            . ($nine ? '' : '<hook_alias id="displayFooterOld"><alias>displayFooterOld</alias><name>displayFooter</name></hook_alias>')
            . '</entities></entity_hook_alias>');
        $this->file("$root/install/data/xml/configuration.xml", '<entity_configuration><entities><configuration id="PS_SHOP_NAME" name="PS_SHOP_NAME"><value>x</value></configuration><configuration id="PS_LANG_DEFAULT" name="PS_LANG_DEFAULT"/></entities></entity_configuration>');
        $this->file("$root/install/data/db_structure.sql", "CREATE TABLE `PREFIX_orders` (\n  `id_order` int(10) unsigned NOT NULL auto_increment,\n  `reference` varchar(9) DEFAULT NULL,\n" . ($nine ? "  `note` text,\n" : '') . "  PRIMARY KEY (`id_order`)\n) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;\n\nCREATE TABLE IF NOT EXISTS `PREFIX_address` (\n  `id_address` int(10)\n) ENGINE=InnoDB;\n");
        $this->file("$root/themes/classic/templates/footer.tpl", "{hook h='displayFooter'} {hook h=\"displayThemeOnly\"}");
        $this->file("$root/admin-dev/themes/default/template/page.tpl", '{hook h="displayAdminTemplate"}');
        $this->file("$root/src/PrestaShopBundle/Resources/views/page.html.twig", "{{ renderhook('displayAdminTwig', {}) }} {{ renderhooksarray(\"displayAdminTwigs\") }}");
        $packages = [
            ['name' => 'symfony/symfony', 'version' => $nine ? 'v6.4.1' : 'v4.4.50', 'license' => ['MIT'], 'replace' => ['symfony/console' => 'self.version']],
            ['name' => 'psr/log', 'version' => $nine ? '3.0.0' : '1.1.4', 'license' => ['MIT']],
            ['name' => 'no/version'],
            'not a package',
        ];
        if ($nine) {
            $packages[] = ['name' => 'psr/clock', 'version' => '1.0.0'];
            $this->file("$root/vendor/composer/installed.json", (string) json_encode(['packages' => $packages]));
        } else {
            $this->file("$root/composer.lock", (string) json_encode(['packages' => $packages]));
        }

        return $root;
    }

    /**
     * Writes the module folder that uses the releases; returns it.
     */
    public function module(): string
    {
        $root = "{$this->dir}/heaven-demo/demomodule";
        $this->file("$root/demomodule.php", <<<'PHP'
<?php
use Demo\Module\DemoTrait;

class Demomodule extends Module
{
    use DemoTrait, OtherTrait {
        DemoTrait::x insteadof OtherTrait;
    }

    const LOCAL = 1;

    public function install()
    {
        return parent::install() && $this->registerHook('displayFooter') && $this->registerHook(['displayFooterOld', 'actionDemoOwn']);
    }

    public function hookDisplayHeader($params)
    {
    }

    public function getContent()
    {
        $order = new Order(1);
        Order::getByReference('x');
        $state = Order::STATE;
        $nope = Order::NOPE;
        $local = self::LOCAL;
        $this->localMethod();
        $this->save();
        Hook::exec('actionDemoOwn');
        pSQL('x');
        \bqSQL('y');
        strlen('x');
        $fn = function () use ($order) {
            return $order;
        };
        $name = Order::class;

        return Configuration::get('X');
    }

    private function localMethod()
    {
    }
}
PHP);
        $this->file("$root/src/Install/ModuleDefinition.php", <<<'PHP'
<?php
namespace Demo\Install;

final class ModuleDefinition
{
    public const OTHER = ['notAHook'];

    public const HOOKS = [
        'actionValidateOrder',
        'actionObjectOrderAddAfter',
        'displayDemoCart',
        'displayDemoNothing',
        'newOrder',
        'actionNewInNine',
    ];
}
PHP);
        $this->file("$root/src/Module/DemoTrait.php", <<<'PHP'
<?php
namespace Demo\Module;

trait DemoTrait
{
    public function hookActionValidateOrder($params)
    {
        $this?->unregisterHook('displayFooter');
        $this->trans('x');
        $nope = static::NOPE2;
        $this->newWay();
    }
}

trait UnusedTrait
{
    public function run()
    {
        $this->whatever();
    }
}
PHP);
        $this->file("$root/src/Service/Thing.php", <<<'PHP'
<?php
namespace Demo\Service;

use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShop\PrestaShop\Core\Missing\Gone;
use PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher as Dispatcher;
use Some\Vendor\{Thing as T, Other};
use function strlen;
use const PHP_EOL;

class Thing extends \Order implements HookDispatcherInterface
{
    use ThingTrait;

    public function run($x)
    {
        try {
            $y = 1;
        } catch (\PrestaShopException | T $e) {
        }
        if ($x instanceof \Order) {
            $a = new static();
            $b = new self();
        }
        \Order::getByReference(1);
        parent::save();
        self::existsInDatabase(1, 'x');
        $this->oldWay();
        \Tools::magic();
        $d = new Dispatcher();
        \CartController::nothingHere();
        $definition = \Order::$definition;
        $text = "{$x}";
    }

    public function dispatchHook($hook)
    {
    }
}

class Vendored extends \Symfony\Component\Thing
{
    public function run()
    {
        $this->fromVendor();
        self::alsoVendor();
    }
}

class WithVendorTrait extends \Order
{
    use \Vendor\Helpers;

    public function run()
    {
        $this->fromVendorTrait();
        $this->newWay();
        $this->getByReference(1);
    }
}

class Orphan
{
    public function run()
    {
        self::undeclared();
        $c = self::NONE;
    }
}

trait ThingTrait
{
    public function fromTrait()
    {
        $this->newWay();
    }
}

function helper()
{
}
PHP);
        $this->file("$root/src/Braced.php", "<?php\nnamespace Demo\\Braced {\n    use PrestaShop\\PrestaShop\\Core\\Hook\\HookDispatcherInterface;\n}\n");
        // Version-aware code: each class is only used behind class_exists() (as X::class, as a string, fully qualified).
        $this->file("$root/src/Guarded.php", <<<'PHP'
<?php
namespace Demo\Guarded;

use PrestaShop\PrestaShop\Core\Missing\Gone;

class Guarded
{
    public function run(): void
    {
        if (class_exists(Gone::class)) {
            Gone::now();
        }
        if (\class_exists('PrestaShop\\PrestaShop\\Core\\Missing\\Other')) {
            \PrestaShop\PrestaShop\Core\Missing\Other::later();
        }
        if (interface_exists(\PrestaShop\PrestaShop\Core\Missing\Contract::class)) {
        }
        class_exists($dynamic);
        class_exists();
        class_exists(Gone::CONSTANT);
        $notACall = CLASS_EXISTS;
    }
}
PHP);
        $this->file("$root/controllers/front/cron.php", "<?php\nclass DemomoduleCronModuleFrontController extends FrontController\n{\n    public function init()\n    {\n        \$this->undefinedAnywhere();\n        parent::init();\n    }\n}\n");
        $this->file("$root/upgrade/upgrade-1.1.0.php", "<?php\nfunction upgrade_module_1_1_0(\$module)\n{\n    self::outside();\n    \$property = \$module->registerHook;\n\n    return \$module->registerHook('displayFooter');\n}\n");
        $this->file("{$this->dir}/heaven-demo/module.spec.json", (string) json_encode(['ps_min' => '8.1.0', 'ps_max' => null]));

        return $root;
    }

    /**
     * Writes a file.
     */
    public function file(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * Removes everything.
     */
    public function remove(?string $dir = null): void
    {
        $dir ??= $this->dir;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir("$dir/$entry") ? $this->remove("$dir/$entry") : unlink("$dir/$entry");
            }
        }
        rmdir($dir);
    }
}
