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

use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Build\PrestashopIndex\Cli;
use PrestaShopHeaven\Build\PrestashopIndex\Finder;
use PrestaShopHeaven\Build\PrestashopIndex\Generator;
use PrestaShopHeaven\Build\PrestashopIndex\HookCalls;
use PrestaShopHeaven\Build\PrestashopIndex\IndexStore;
use PrestaShopHeaven\Build\PrestashopIndex\InstallData;
use PrestaShopHeaven\Build\PrestashopIndex\Merger;
use PrestaShopHeaven\Build\PrestashopIndex\ModuleChecker;
use PrestaShopHeaven\Build\PrestashopIndex\ModuleReferences;
use PrestaShopHeaven\Build\PrestashopIndex\ServiceIds;
use PrestaShopHeaven\Build\PrestashopIndex\VendorPackages;
use PrestaShopHeaven\Build\PrestashopIndex\VersionSet;

/**
 * The PrestaShop index on two fake releases (8.1.0, 9.0.0): generation, merge, store, search, the module check and the
 * commands.
 */
final class PrestashopIndexTest extends TestCase
{
    private FakePrestashop $fake;

    protected function setUp(): void
    {
        $this->fake = new FakePrestashop();
    }

    protected function tearDown(): void
    {
        $this->fake->remove();
    }

    public function testGenerateReadsClassesHooksServicesConfigTablesAndVendor(): void
    {
        $index = Generator::generate($this->fake->release('8.1.0'), '8.1.0');
        self::assertSame([Generator::FORMAT, '8.1.0'], [$index['format'], $index['version']]);
        $order = $index['classes']['OrderCore'];
        self::assertSame(['c', 'classes/order/Order.php', ['ObjectModel']], [$order['k'], $order['f'], $order['p']]);
        self::assertSame(['v' => 'public', 'g' => '($reference)', 's' => 1], $order['m']['getByReference']);
        self::assertSame('since 8.0, use newWay()', $order['m']['oldWay']['d']);
        self::assertSame(['STATE' => 'public'], $order['c']);
        $model = $index['classes']['ObjectModelCore'];
        self::assertSame(['__construct', 'save', 'existsInDatabase', 'hidden'], array_keys($model['m']), 'private methods are left out');
        self::assertSame(['TABLE' => 'public'], $model['c']);
        self::assertSame(['v' => 'protected', 'g' => '()'], $model['m']['hidden']);
        self::assertArrayHasKey('a', $index['classes']['PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface']['m']['dispatchHook']);
        self::assertSame('i', $index['classes']['PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface']['k']);
        self::assertSame('since 9.0', $index['classes']['PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher']['d']);
        self::assertSame(['PrestaShop\PrestaShop\Adapter\Hook\BaseDispatcher', 'PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface', 'PrestaShop\PrestaShop\Adapter\Hook\DispatchTrait'], $index['classes']['PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher']['p']);
        self::assertSame(['CartController' => 'CartControllerCore', 'FrontController' => 'FrontControllerCore', 'Hook' => 'HookCore', 'ObjectModel' => 'ObjectModelCore', 'Order' => 'OrderCore'], $index['aliases'], 'Tools exists itself; Core is no alias');
        self::assertSame(['bqSQL' => ['g' => '($string)', 'f' => 'config/alias.php', 'd' => ''], 'pSQL' => ['g' => '($string, $htmlOK = false)', 'f' => 'config/alias.php']], $index['functions']);

        self::assertSame(['t' => 'New orders & more', 's' => ['xml']], $index['hooks']['actionValidateOrder']);
        self::assertSame(['t' => '', 's' => ['code', 'xml']], $index['hooks']['actionOrderRead']);
        self::assertSame(['t' => 'Footer', 's' => ['template', 'xml']], $index['hooks']['displayFooter']);
        foreach (['actionAB', 'actionFrontInit', 'actionCoreHook', 'displayThemeOnly', 'displayAdminTemplate', 'displayAdminTwig', 'displayAdminTwigs'] as $hook) {
            self::assertArrayHasKey($hook, $index['hooks'], $hook);
        }
        self::assertSame(['actionObject*AddAfter', 'display*Cart'], array_keys($index['hookPatterns']));
        self::assertSame(['displayFooterOld' => 'displayFooter', 'newOrder' => 'actionValidateOrder'], $index['hookAliases']);

        self::assertSame([
            'PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface' => 'PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface',
            'admin.one' => '',
            'app.service' => 'App\Service',
            'prestashop.core.hook.dispatcher' => 'PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher',
            'prestashop.long.alias' => '@prestashop.core.hook.dispatcher',
            'prestashop.quoted' => 'PrestaShop\PrestaShop\Core\Quoted',
            'prestashop.short.alias' => '@prestashop.core.hook.dispatcher',
        ], $index['services']);
        self::assertSame(['PrestaShopBundle\Controller\\'], $index['servicePrefixes']);
        self::assertSame(['PS_FROM_CODE' => 'code', 'PS_LANG_DEFAULT' => 'install', 'PS_SHOP_NAME' => 'install'], $index['config']);
        self::assertSame(['address' => ['id_address'], 'orders' => ['id_order', 'reference']], $index['tables']);
        self::assertSame(['v' => 'v4.4.50', 'l' => 'MIT', 'via' => 'symfony/symfony'], $index['vendor']['symfony/console']);
        self::assertSame(['psr/log', 'symfony/console', 'symfony/symfony'], array_keys($index['vendor']));
    }

    public function testGenerateRefusesAFolderThatIsNoRelease(): void
    {
        $this->expectException(\RuntimeException::class);
        Generator::generate($this->fake->dir, '1.0.0');
    }

    public function testTheVendorListComesFromTheLockOrInstalledJson(): void
    {
        $nine = Generator::generate($this->fake->release('9.0.0'), '9.0.0');
        self::assertSame('1.0.0', $nine['vendor']['psr/clock']['v']);
        self::assertSame('', $nine['vendor']['psr/clock']['l']);
        self::assertSame([], VendorPackages::read($this->fake->dir));
        self::assertSame(['a/b' => ['v' => '1.0', 'l' => 'MIT OR GPL-2.0']], VendorPackages::fromComposerData([['name' => 'a/b', 'version' => '1.0', 'license' => ['MIT', 'GPL-2.0']]]));
    }

    public function testInstallDataAndServiceReadersOnTheirOwn(): void
    {
        self::assertSame([], InstallData::hooks(''));
        self::assertSame([], InstallData::tables('CREATE TABLE x'));
        self::assertSame(['services' => [], 'prefixes' => []], ServiceIds::fromYaml("services:\n    _instanceof:\n        Foo:\n            tags: [x]\n"));
        self::assertSame(['services' => ['a' => ''], 'prefixes' => []], ServiceIds::fromYaml("services:\n    a:\n        arguments:\n            - x\nother: 1\n    b: ~\n"));
    }

    public function testHookCallsAndPatterns(): void
    {
        self::assertSame(['names' => [], 'patterns' => []], HookCalls::fromTokens(token_get_all('<?php Hook::exec')));
        self::assertTrue(HookCalls::matches('actionObject*DeleteAfter', 'actionobjectOrderDeleteAfter'));
        self::assertFalse(HookCalls::matches('actionObject*DeleteAfter', 'actionObjectDeleteAfter'));
        self::assertSame(['a', 'b'], HookCalls::fromTemplate("{hook h='a'} {hook h='a'} {{ renderhook(\"b\") }}"));
    }

    public function testVersionSets(): void
    {
        self::assertSame('0-2,5,7-8', VersionSet::encode([8, 0, 1, 2, 5, 7, 2]));
        self::assertSame('', VersionSet::encode([]));
        self::assertSame([0, 1, 2, 5, 7, 8], VersionSet::decode('0-2,5,7-8'));
        self::assertSame([], VersionSet::decode(''));
        self::assertSame('8.1.0 – 9.0.0, ?', VersionSet::describe('0-1,4', ['8.1.0', '9.0.0']));
        self::assertSame('? – ?', VersionSet::describe('5-6', []));
    }

    public function testTheStoreSavesMergesAndLoads(): void
    {
        $store = $this->store();
        self::assertSame(['8.1.0', '9.0.0'], $store->versions());
        $data = $store->symbols()->data();
        self::assertSame(['8.1.0', '9.0.0'], $data['versions']);
        $order = $data['classes']['OrderCore'];
        self::assertSame('0-1', $order['v']);
        self::assertSame(['($reference)' => '0', '(string $reference): ?self' => '1'], array_map('strval', $order['m']['getByReference']['g']) + [], 'the signature per release');
        self::assertSame(['v' => '0', 'g' => '()', 'd' => '0', 'dm' => 'since 8.0, use newWay()'], $order['m']['oldWay']);
        self::assertSame('1', $order['m']['newWay']['v']);
        self::assertSame('protected', $data['classes']['ObjectModelCore']['m']['hidden']['vis']);
        self::assertEquals(['v' => '0-1', 'd' => '0-1', 'dm' => 'since 9.0', 'k' => 'c', 'f' => 'src/Adapter/Hook/HookDispatcher.php'], array_intersect_key($data['classes']['PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher'], array_flip(['v', 'd', 'dm', 'k', 'f'])));
        self::assertSame(['t' => 'displayFooter', 'v' => '0'], $data['hookAliases']['displayFooterOld']);
        self::assertSame('1', $data['hooks']['actionNewInNine']['v']);
        self::assertSame(['8.1.0' => '1.1.4', '9.0.0' => '3.0.0'], $data['vendor']['psr/log']);
        self::assertSame(['v' => '0-1', 'c' => ['id_order' => '0-1', 'reference' => '0-1', 'note' => '1']], $data['tables']['orders']);
        self::assertSame('0-1', $data['config']['PS_SHOP_NAME']);
        self::assertSame('0-1', $data['servicePrefixes']['PrestaShopBundle\Controller\\']);
        self::assertSame('0-1', $data['functions']['pSQL']['v']);
        self::assertSame('0-1', $data['hookPatterns']['display*Cart']);
        self::assertSame('0-1', $data['services']['app.service']['v']);
        self::assertSame('0-1', $data['aliases']['Order']['v']);
        self::assertSame($store->dir(), str_replace('\\', '/', $store->dir()));
    }

    public function testAMixedVisibilityIsKeptPerRelease(): void
    {
        $base = ['format' => 1, 'aliases' => [], 'functions' => [], 'hooks' => [], 'hookAliases' => [], 'hookPatterns' => [], 'services' => [], 'servicePrefixes' => [], 'config' => [], 'tables' => [], 'vendor' => []];
        $merged = Merger::merge([
            ['version' => '9.0.0', 'classes' => ['A' => ['k' => 'c', 'f' => 'a.php', 'm' => ['run' => ['v' => 'public', 'g' => '()']]]]] + $base,
            ['version' => '8.1.0', 'classes' => ['A' => ['k' => 'c', 'f' => 'a.php', 'm' => ['run' => ['v' => 'protected', 'g' => '()']]]]] + $base,
        ]);
        self::assertSame(['8.1.0', '9.0.0'], $merged['versions']);
        self::assertSame('protected:0,public:1', $merged['classes']['A']['m']['run']['vis']);
    }

    public function testTheStoreRefusesWhatIsMissing(): void
    {
        $store = new IndexStore($this->fake->dir . '/empty');
        try {
            $store->merge();
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('No PrestaShop release indexed', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $store->symbols();
    }

    public function testTheDefaultDataFolder(): void
    {
        self::assertSame('/x', IndexStore::defaultDir('/x'));
        putenv('PS_INDEX_DIR=/from/env');
        self::assertSame('/from/env', IndexStore::defaultDir());
        putenv('PS_INDEX_DIR');
        self::assertStringEndsWith('/prestashop-index/data', IndexStore::defaultDir());
    }

    public function testSymbolLookups(): void
    {
        $index = $this->store()->symbols();
        self::assertSame(['8.1.0', '9.0.0'], $index->versions());
        self::assertSame([1], $index->positionsInRange('9.0.0', null));
        self::assertSame([0], $index->positionsInRange(null, '8.9.9'));
        self::assertSame('OrderCore', $index->className('\order'));
        self::assertNull($index->className('Nope'));
        self::assertNull($index->classEntry('Nope'));
        self::assertSame([0, 1], $index->classPositions('Order'));
        self::assertSame([0, 1], $index->classPositions('OrderCore'));
        self::assertSame([], $index->classPositions('Nope'));
        self::assertSame('ObjectModelCore', $index->findMethod('Order', 'SAVE', 0)[0] ?? null);
        self::assertNull($index->findMethod('Order', 'oldWay', 1));
        self::assertNull($index->findMethod('Order', 'nothing', 0));
        self::assertSame('ObjectModelCore', $index->findConstant('Order', 'TABLE', 1));
        self::assertNull($index->findConstant('Order', 'NOPE', 1));
        self::assertTrue($index->hasKnownAncestry('Order'));
        self::assertTrue($index->hasKnownAncestry('PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher'), 'an interface reached twice');
        self::assertNull($index->findMethod('NineOnly', 'run', 0));
        self::assertSame('NineOnlyCore', $index->findMethod('NineOnly', 'run', 1)[0] ?? null);
        self::assertFalse($index->hasKnownAncestry('CartController'), 'Controller is not indexed');
        self::assertSame([0 => 'name', 1 => 'name'], $index->hookPositions('ACTIONVALIDATEORDER'));
        self::assertSame([0 => 'alias of displayFooter'], $index->hookPositions('displayFooterOld'));
        self::assertSame([0 => 'pattern actionObject*AddAfter', 1 => 'pattern actionObject*AddAfter'], $index->hookPositions('actionObjectOrderAddAfter'));
        self::assertSame([], $index->hookPositions('nothing'));
        self::assertSame('($string, $htmlOK = false)', $index->functionEntry('psql')['g'] ?? null);
        self::assertNull($index->functionEntry('nope'));
        self::assertSame(['8.1.0' => 'v4.4.50', '9.0.0' => 'v6.4.1'], $index->vendorVersions('Symfony/Console'));
        self::assertSame([], $index->vendorVersions('none/none'));
    }

    public function testFindShowsWhereWhatAndInWhichReleases(): void
    {
        $index = $this->store()->symbols();
        $hits = Finder::find($index, 'Order');
        self::assertSame(['class', 'OrderCore'], [$hits[0]['kind'], $hits[0]['name']]);
        self::assertSame('8.1.0 – 9.0.0', $hits[0]['versions']);
        self::assertStringContainsString('class in classes/order/Order.php; parents: ObjectModel', $hits[0]['detail']);
        $method = Finder::find($index, 'Order::getBy')[0];
        self::assertSame('OrderCore::getByReference', $method['name']);
        self::assertSame('($reference) [8.1.0] | (string $reference): ?self [9.0.0]', $method['signature']);
        self::assertSame('static public', $method['detail']);
        self::assertSame('OrderCore::STATE', Finder::find($index, 'Order::STATE')[0]['name']);
        self::assertSame('8.1.0 (since 8.0, use newWay())', Finder::find($index, 'oldWay')[0]['deprecated']);
        self::assertSame(['hook', 'actionValidateOrder'], [Finder::find($index, 'actionValidateOrder')[0]['kind'], Finder::find($index, 'actionValidateOrder')[0]['name']]);
        self::assertContains('actionValidateOrder', array_column(Finder::find($index, 'more'), 'name'), 'the title is searched');
        self::assertSame('service', Finder::find($index, 'prestashop.quoted')[0]['kind']);
        self::assertSame('config', Finder::find($index, 'PS_SHOP_NAME')[0]['kind']);
        self::assertSame(['table', 'columns: id_order, reference, note'], [Finder::find($index, 'orders')[0]['kind'], Finder::find($index, 'orders')[0]['detail']]);
        self::assertSame('function', Finder::find($index, 'pSQL')[0]['kind']);
        self::assertContains('PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface', array_column(Finder::find($index, 'hook dispatcher interface', 50), 'name'), 'every word of the term');
        self::assertSame([], Finder::find($index, ' '));
        self::assertSame([], Finder::find($index, 'Nothing::here'));
        self::assertCount(1, Finder::find($index, 'Order', 0));
    }

    public function testFindFormatsHits(): void
    {
        $index = $this->store()->symbols();
        $text = implode("\n", Finder::format(array_merge(Finder::find($index, 'getByReference', 1), Finder::find($index, 'oldWay', 1), Finder::find($index, 'pSQL', 1))));
        self::assertStringContainsString("method   OrderCore::getByReference\n         in 8.1.0 – 9.0.0\n         signatures: (\$reference) [8.1.0] | (string \$reference): ?self [9.0.0]", $text);
        self::assertStringContainsString('in 8.1.0; DEPRECATED in 8.1.0 (since 8.0, use newWay())', $text);
        self::assertStringContainsString('function pSQL($string, $htmlOK = false)', $text);
        self::assertStringStartsWith('PrestaShop has nothing', Finder::format([])[0]);
    }

    public function testTheModuleReferences(): void
    {
        $this->store();
        $module = ModuleReferences::collect($this->fake->module());
        self::assertSame(8, $module['files']);
        self::assertSame([
            'src/Guarded.php|PrestaShop\PrestaShop\Core\Missing\Gone',
            'src/Guarded.php|PrestaShop\PrestaShop\Core\Missing\Other',
            'src/Guarded.php|PrestaShop\PrestaShop\Core\Missing\Contract',
        ], $module['guarded'], 'class_exists() and friends with X::class or a string; a variable, nothing or a constant is no guard');
        self::assertSame(['actionDemoOwn'], $module['ownHooks']);
        $hooks = array_column(array_filter($module['references'], static fn (array $r): bool => $r['kind'] === 'hook'), 'via', 'name');
        self::assertSame(['displayFooter', 'displayFooterOld', 'actionValidateOrder', 'actionObjectOrderAddAfter', 'displayDemoCart', 'displayDemoNothing', 'newOrder', 'actionNewInNine', 'displayHeader'], array_keys($hooks));
        self::assertArrayNotHasKey('notAHook', $hooks);
        $classes = $module['classes'];
        self::assertSame('Module', $classes['Demomodule']['parent']);
        self::assertSame(['Demo\Module\DemoTrait', 'OtherTrait'], $classes['Demomodule']['traits']);
        self::assertSame(['LOCAL'], $classes['Demomodule']['constants']);
        self::assertTrue($classes['Demo\Module\DemoTrait']['trait']);
        $refs = array_map(static fn (array $r): string => "{$r['kind']}|{$r['class']}|{$r['name']}|{$r['via']}", $module['references']);
        foreach ([
            'class|Order||new', 'method|Order|getByReference|static', 'constant|Order|STATE|static', 'constant|Demomodule|LOCAL|self',
            'method|Demomodule|save|$this', 'function||pSQL|call', 'function||bqSQL|call', 'class|PrestaShop\PrestaShop\Core\Missing\Gone||use',
            'class|Some\Vendor\Thing||use', 'class|PrestaShopException||catch', 'class|Some\Vendor\Thing||catch', 'class|Order||instanceof',
            'method|Demo\Service\Thing|save|parent', 'class|Order||extends', 'class|PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface||implements',
            'class|PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher||new', 'class|Tools||name',
        ] as $expected) {
            self::assertContains($expected, $refs, $expected);
        }
        self::assertNotContains('class|static||new', $refs);
        self::assertNotContains('constant|Order|class|static', $refs);
    }

    public function testTheCheckFindsMissingDeprecatedAndChangedSymbols(): void
    {
        $index = $this->store()->symbols();
        $result = ModuleChecker::check($index, ModuleReferences::collect($this->fake->module()), '8.1.0', null);
        self::assertSame(['8.1.0', '9.0.0'], $result['versions']);
        $issues = array_map(static fn (array $i): string => "{$i['level']} {$i['symbol']}: {$i['message']}", $result['issues']);
        foreach ([
            'error Order::NOPE: missing in PrestaShop 8.1.0 – 9.0.0',
            'error Order::oldWay(): missing in PrestaShop 9.0.0',
            'error PrestaShop\PrestaShop\Core\Missing\Gone: missing in PrestaShop 8.1.0 – 9.0.0',
            'error hook displayDemoNothing: missing in PrestaShop 8.1.0 – 9.0.0',
            'error hook displayFooterOld: missing in PrestaShop 9.0.0',
            'warning hook actionNewInNine: missing in PrestaShop 8.1.0: it only runs in the other releases, so the module must also work without it (HK-09)',
            'error hook displayHeader: missing in PrestaShop 8.1.0 – 9.0.0',
            'warning PrestaShop\PrestaShop\Core\Missing\Gone: missing in PrestaShop 8.1.0 – 9.0.0 (the file tests it with class_exists(): only used where it exists)',
            'warning PrestaShop\PrestaShop\Core\Missing\Other: missing in PrestaShop 8.1.0 – 9.0.0 (the file tests it with class_exists(): only used where it exists)',
            'error Order::newWay(): missing in PrestaShop 8.1.0',
            'warning Order::getByReference(): the signature differs between the releases: OrderCore::getByReference($reference) (8.1.0); OrderCore::getByReference(string $reference): ?self (9.0.0)',
            'warning Order::oldWay(): deprecated in PrestaShop 8.1.0: since 8.0, use newWay()',
            'warning PrestaShop\PrestaShop\Adapter\Hook\HookDispatcher: deprecated in PrestaShop 8.1.0 – 9.0.0: since 9.0',
            'warning hook displayFooterOld: only known as an alias of displayFooter in some releases: register the hook by its real name',
            'warning hook newOrder: only known as an alias of actionValidateOrder in some releases: register the hook by its real name',
        ] as $expected) {
            self::assertContains($expected, $issues, $expected);
        }
        self::assertNotContains('error hook actionNewInNine: missing in PrestaShop 8.1.0', $issues, 'a new hook is a warning');
        self::assertContains('error hook displayFooterOld: missing in PrestaShop 9.0.0', $issues, 'a hook a newer release removed stays an error');
        $issuesText = implode("\n", $issues);
        self::assertStringNotContainsString('actionDemoOwn', $issuesText, 'the module executes it itself');
        self::assertStringNotContainsString('Tools::magic', $issuesText, '__callStatic answers it');
        self::assertStringNotContainsString('undefinedAnywhere', $issuesText, 'FrontController has a parent outside the index');
        self::assertStringNotContainsString('fromVendor', $issuesText, 'a vendor class or trait may declare it');
        self::assertSame(1, substr_count($issuesText, 'newWay(): missing'), 'not for the class with a vendor trait');
        self::assertStringNotContainsString('actionObjectOrderAddAfter', $issuesText, 'found through a pattern');
        self::assertGreaterThan(10, $result['checked']);
        self::assertGreaterThan(5, $result['skipped']);

        $lines = ModuleChecker::format($result);
        self::assertStringStartsWith('ps check: ', $lines[count($lines) - 1]);
        self::assertContains('ERROR hook displayFooterOld: missing in PrestaShop 9.0.0', $lines);
        self::assertNotSame([], preg_grep('/\(and \d+ more\)$/', $lines));
    }

    public function testTheCheckNeedsAReleaseInTheRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No indexed PrestaShop release between 10.0.0 and any');
        ModuleChecker::check($this->store()->symbols(), ['references' => [], 'classes' => []], '10.0.0', null);
    }

    public function testTheCommands(): void
    {
        $data = $this->fake->dir . '/data';
        [$code, $out] = $this->command(['generate', $this->fake->release('8.1.0'), '8.1.0', "--data=$data", '--no-merge']);
        self::assertSame(0, $code);
        self::assertStringContainsString('ps-8.1.0.json.gz', $out);
        self::assertStringNotContainsString('Merged', $out);
        [$code, $out] = $this->command(['generate', $this->fake->release('9.0.0'), '9.0.0', "--data=$data"]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Merged 2 release(s)', $out);
        self::assertSame([0, "Merged 8.1.0, 9.0.0 into $data/symbols.json.gz.\n"], array_slice($this->command(['merge', "--data=$data"]), 0, 2));
        self::assertSame([0, "8.1.0\n9.0.0\n"], array_slice($this->command(['versions', "--data=$data"]), 0, 2));
        [$code, $out] = $this->command(['find', 'getByReference', "--data=$data", '--limit=1']);
        self::assertSame(0, $code);
        self::assertStringStartsWith('method   OrderCore::getByReference', $out);
        [$code, $out] = $this->command(['find', 'getByReference', "--data=$data", '--json']);
        self::assertSame('OrderCore::getByReference', json_decode($out, true)[0]['name']);
        $module = $this->fake->module();
        [$code, $out] = $this->command(['check', $module, "--data=$data"]);
        self::assertSame(1, $code);
        self::assertStringContainsString('ERROR hook displayDemoNothing', $out);
        [$code, $out] = $this->command(['check', $module, "--data=$data", '--json', '--ps-max=8.1.0']);
        self::assertSame(['8.1.0'], json_decode($out, true)['versions']);
        $clean = $this->fake->dir . '/clean/clean';
        $this->fake->file("$clean/clean.php", "<?php\nclass Clean extends Module\n{\n}\n");
        self::assertSame(0, $this->command(['check', $clean, "--data=$data"])[0]);
    }

    public function testWrongUsageAndFailures(): void
    {
        foreach ([[], ['generate', 'x'], ['generate', 'x', 'nine'], ['find'], ['check', '/no/such/dir'], ['other']] as $args) {
            [$code, , $err] = $this->command($args);
            self::assertSame(2, $code, implode(' ', $args));
            self::assertStringStartsWith('Usage:', $err);
        }
        [$code, , $err] = $this->command(['find', 'x', '--data=' . $this->fake->dir . '/none']);
        self::assertSame(1, $code);
        self::assertStringContainsString('is missing or not a PrestaShop index file', $err);
    }

    public function testTheRangeOfAModule(): void
    {
        $module = $this->fake->module();
        self::assertSame(['8.1.0', null], Cli::range($module));
        $this->fake->file("$module/module.spec.json", (string) json_encode(['ps_min' => '8.2.0', 'ps_max' => '9.9.9']));
        self::assertSame(['8.2.0', '9.9.9'], Cli::range($module));
        $old = $this->fake->dir . '/old/oldmodule';
        $this->fake->file("$old/oldmodule.php", "<?php\n\$this->ps_versions_compliancy = ['min' => '1.7.8', 'max' => _PS_VERSION_];\n");
        self::assertSame(['1.7.8', null], Cli::range($old));
        self::assertSame([null, null], Cli::range($this->fake->dir . '/none'));
    }

    /**
     * A store with both fake releases indexed and merged.
     */
    private function store(): IndexStore
    {
        $store = new IndexStore($this->fake->dir . '/data/');
        $store->saveVersion(Generator::generate($this->fake->release('9.0.0'), '9.0.0'));
        $store->saveVersion(Generator::generate($this->fake->release('8.1.0'), '8.1.0'));
        $store->merge();

        return $store;
    }

    /**
     * Runs a command; returns the exit code, the output and the errors.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function command(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = Cli::run($args, $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
