<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FakeUpdateSiteQuery implements QueryInterface
{
    /** @var list<string> */
    public array $whereConditions = [];

    public function select(array|string $columns): self
    {
        return $this;
    }

    public function from(array|string $tables): self
    {
        return $this;
    }

    public function where(array|string $conditions, string $glue = 'AND'): self
    {
        foreach ((array) $conditions as $condition) {
            $this->whereConditions[] = $condition;
        }

        return $this;
    }

    public function order(array|string $columns): self
    {
        return $this;
    }

    public function update(string $table): self
    {
        return $this;
    }

    public function set(array|string $values): self
    {
        return $this;
    }

    public function insert(string $table): self
    {
        return $this;
    }

    public function columns(array|string $columns): self
    {
        return $this;
    }

    public function values(array|string $values): self
    {
        return $this;
    }

    public function __toString(): string
    {
        return 'WHERE ' . implode(' AND ', $this->whereConditions);
    }
}

final class FakeUpdateSiteDatabase implements DatabaseInterface
{
    /** Rows loadAssoc()/loadAssocList() hand back, oldest queued first. @var list<list<array<string, mixed>>> */
    public array $resultSets = [];

    /** @var list<array{table: string, row: object}> */
    public array $updatedObjects = [];

    /** The WHERE conditions of every query run, in order. @var list<list<string>> */
    public array $queried = [];

    private ?FakeUpdateSiteQuery $query = null;

    public function quoteName(array|string $name, array|string|null $alias = null): array|string
    {
        if (is_array($name)) {
            return array_map(static fn (string $one): string => '`' . $one . '`', $name);
        }

        return '`' . $name . '`';
    }

    public function quote(array|string $text, bool $escape = true): array|string
    {
        if (is_array($text)) {
            return array_map(static fn (string $one): string => "'" . addslashes($one) . "'", $text);
        }

        return "'" . addslashes($text) . "'";
    }

    public function getQuery(bool $new = false): QueryInterface|string
    {
        return new FakeUpdateSiteQuery();
    }

    public function setQuery(QueryInterface|string $query, int $offset = 0, int $limit = 0): self
    {
        $this->query = $query instanceof FakeUpdateSiteQuery ? $query : null;

        return $this;
    }

    public function loadAssoc(): ?array
    {
        return $this->nextResultSet()[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadAssocList(): array
    {
        return $this->nextResultSet();
    }

    public function loadResult(): mixed
    {
        return null;
    }

    public function updateObject(string $table, object $object, string $key): bool
    {
        $this->updatedObjects[] = ['table' => $table, 'row' => clone $object];

        return true;
    }

    public function execute(): bool
    {
        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nextResultSet(): array
    {
        $this->queried[] = $this->query?->whereConditions ?? [];

        return array_shift($this->resultSets) ?? [];
    }
}

class RpcServiceUpdateSitesTest extends TestCase
{
    private const KEY = 'ABCD-1234-EFGH-5678';

    private FakeUpdateSiteDatabase $db;

    /** Manifest fixtures written for a test, removed again in tearDown. @var list<string> */
    private array $writtenFiles = [];

    protected function setUp(): void
    {
        Factory::reset();

        $this->db = new FakeUpdateSiteDatabase();
        Factory::$dbo = $this->db;
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->writtenFiles = [];

        Factory::reset();
    }

    public function testListReportsServedExtensionsAndAMaskedKey(): void
    {
        $this->writePackageManifest('pkg_example', '<dlid prefix="license_key=" suffix=""/>');
        $this->queueSites([$this->siteRow(['extra_query' => 'license_key=' . self::KEY])]);

        $response = $this->callTool($this->makeService(), 'list_update_sites', []);
        $site = $this->decode($response)['data'][0];

        $this->assertSame(7, $site['update_site_id']);
        $this->assertSame('Example updates', $site['name']);
        $this->assertTrue($site['enabled']);
        $this->assertSame('2026-11-01T12:00:00Z', $site['last_check']);
        $this->assertSame(
            [['extension_id' => 42, 'name' => 'Example', 'type' => 'package', 'element' => 'pkg_example', 'version' => '1.0.4']],
            $site['extensions']
        );
        $this->assertSame(
            ['supported' => true, 'prefix' => 'license_key=', 'suffix' => '', 'is_set' => true, 'masked' => '********5678'],
            $site['download_key']
        );
    }

    /**
     * The whole point of masking: neither the key nor the raw extra_query may
     * appear anywhere in what the client receives.
     */
    public function testTheKeyNeverAppearsInTheResponse(): void
    {
        $this->writePackageManifest('pkg_example', '<dlid prefix="license_key=" suffix=""/>');
        $this->queueSites([$this->siteRow(['extra_query' => 'license_key=' . self::KEY])]);

        $text = $this->callTool($this->makeService(), 'list_update_sites', [])['result']['content'][0]['text'];

        $this->assertStringNotContainsString(self::KEY, $text);
        $this->assertStringNotContainsString('extra_query', $text);
    }

    public function testAShortKeyIsMaskedCompletely(): void
    {
        $this->writePackageManifest('pkg_example', '<dlid prefix="license_key=" suffix=""/>');
        $this->queueSites([$this->siteRow(['extra_query' => 'license_key=abc123'])]);

        $site = $this->decode($this->callTool($this->makeService(), 'list_update_sites', []))['data'][0];

        $this->assertSame('********', $site['download_key']['masked']);
    }

    public function testThePrefixAndSuffixAreStrippedBeforeMasking(): void
    {
        $this->writePackageManifest('pkg_example', '<dlid prefix="dlid=" suffix="&amp;site=1"/>');
        $this->queueSites([$this->siteRow(['extra_query' => 'dlid=' . self::KEY . '&site=1'])]);

        $key = $this->decode($this->callTool($this->makeService(), 'list_update_sites', []))['data'][0]['download_key'];

        $this->assertSame('********5678', $key['masked']);
        $this->assertSame('&site=1', $key['suffix']);
    }

    public function testASiteWithoutADlidIsReportedUnsupported(): void
    {
        $this->queueSites([$this->siteRow(['extra_query' => ''])]);

        $key = $this->decode($this->callTool($this->makeService(), 'list_update_sites', []))['data'][0]['download_key'];

        $this->assertSame(['supported' => false, 'is_set' => false], $key);
    }

    public function testFiltersApplyToEnabledStateExtensionAndSearch(): void
    {
        $sites = [
            $this->siteRow(['update_site_id' => 7, 'name' => 'Example updates']),
            $this->siteRow(['update_site_id' => 8, 'name' => 'Other updates', 'enabled' => 0, 'location' => 'https://other.test/u.xml']),
        ];
        $links = [['update_site_id' => 7, 'extension_id' => 42], ['update_site_id' => 8, 'extension_id' => 43]];
        $extensions = [$this->extensionRow(), $this->extensionRow(['extension_id' => 43, 'element' => 'pkg_other'])];

        foreach ([
            [['enabled' => 0], [8]],
            [['extension_id' => 42], [7]],
            [['search' => 'other.test'], [8]],
            [['search' => 'pkg_example'], [7]],
            [[], [7, 8]],
        ] as [$arguments, $expected]) {
            $this->db->resultSets = [$sites, $links, $extensions];

            $data = $this->decode($this->callTool($this->makeService(), 'list_update_sites', $arguments))['data'];

            $this->assertSame($expected, array_column($data, 'update_site_id'), json_encode($arguments));
        }
    }

    public function testSitesAreSortedByNameAndPaged(): void
    {
        $this->db->resultSets = [
            [
                $this->siteRow(['update_site_id' => 1, 'name' => 'Zeta']),
                $this->siteRow(['update_site_id' => 2, 'name' => 'alpha']),
                $this->siteRow(['update_site_id' => 3, 'name' => 'Beta']),
            ],
            [],
        ];

        $decoded = $this->decode($this->callTool($this->makeService(), 'list_update_sites', ['limit' => 2]));

        $this->assertSame(['alpha', 'Beta'], array_column($decoded['data'], 'name'));
        $this->assertTrue($decoded['pagination']['has_more']);
        $this->assertSame(2, $decoded['pagination']['next_offset']);
    }

    public function testSetWritesTheKeyInsideTheDlidPrefixAndSuffix(): void
    {
        $this->writePackageManifest('pkg_example', '<dlid prefix="license_key=" suffix=""/>');
        $this->queueSites([$this->siteRow(['extra_query' => ''])]);

        $response = $this->callTool($this->makeService(), 'set_update_site_key', [
            'update_site_id' => 7,
            'download_key' => '  ' . self::KEY . ' ',
        ]);
        $data = $this->decode($response)['data'];

        $this->assertSame(["`update_site_id` = 7"], $this->db->queried[0]);
        $this->assertCount(1, $this->db->updatedObjects);
        $this->assertSame('#__update_sites', $this->db->updatedObjects[0]['table']);
        $this->assertSame(7, $this->db->updatedObjects[0]['row']->update_site_id);
        $this->assertSame('license_key=' . self::KEY, $this->db->updatedObjects[0]['row']->extra_query);
        $this->assertSame('********5678', $data['download_key']['masked']);
        $this->assertStringNotContainsString(self::KEY, $response['result']['content'][0]['text']);
    }

    public function testAnEmptyKeyClearsTheSite(): void
    {
        $this->writePackageManifest('pkg_example', '<dlid prefix="license_key=" suffix=""/>');
        $this->queueSites([$this->siteRow(['extra_query' => 'license_key=' . self::KEY])]);

        $data = $this->decode($this->callTool($this->makeService(), 'set_update_site_key', [
            'update_site_id' => 7,
            'download_key' => '',
        ]))['data'];

        $this->assertSame('', $this->db->updatedObjects[0]['row']->extra_query);
        $this->assertFalse($data['download_key']['is_set']);
        $this->assertNull($data['download_key']['masked']);
    }

    /**
     * Joomla shows no Download Key field for such a site and its update server
     * expects nothing, so writing one would only put an unused secret in the URL.
     */
    public function testSetIsRefusedWhenNoServedExtensionDeclaresADlid(): void
    {
        $this->queueSites([$this->siteRow()]);

        $error = $this->callExpectingError('set_update_site_key', ['update_site_id' => 7, 'download_key' => self::KEY]);

        $this->assertStringContainsString('<dlid>', $error);
        $this->assertSame([], $this->db->updatedObjects);
    }

    public function testKeysThatWouldBreakTheQueryStringAreRejected(): void
    {
        foreach (['abc&def', 'abc def', "abc\ndef", 'abc#def'] as $key) {
            $error = $this->callExpectingError('set_update_site_key', ['update_site_id' => 7, 'download_key' => $key]);

            $this->assertStringContainsString('unencoded', $error, json_encode($key));
        }

        $this->assertSame([], $this->db->updatedObjects);
    }

    public function testAnUnknownSiteIsReported(): void
    {
        $this->db->resultSets[] = [];

        $error = $this->callExpectingError('set_update_site_key', ['update_site_id' => 99, 'download_key' => self::KEY]);

        $this->assertStringContainsString('Update site #99 not found', $error);
    }

    public function testSetIsRefusedInReadOnlyMode(): void
    {
        $error = $this->callExpectingError(
            'set_update_site_key',
            ['update_site_id' => 7, 'download_key' => self::KEY],
            $this->makeService(true)
        );

        $this->assertStringContainsString('read-only', strtolower($error));
        $this->assertSame([], $this->db->updatedObjects);
    }

    /**
     * Listing is safe to leave on, since keys are masked; writing one stores a
     * licence credential, so it waits for an administrator to opt in.
     */
    public function testOnlyTheWriteToolIsDisabledByDefault(): void
    {
        $policy = new PolicyService(new \Joomla\Registry\Registry());

        $this->assertTrue($policy->isToolAllowed('list_update_sites'));
        $this->assertFalse($policy->isToolAllowed('set_update_site_key'));
    }

    public function testListExtensionsReportsTheInstalledVersion(): void
    {
        $this->db->resultSets[] = [
            $this->extensionRow() + ['protected' => 0, 'locked' => 0],
            $this->extensionRow(['extension_id' => 43, 'manifest_cache' => '']) + ['protected' => 0, 'locked' => 0],
        ];

        $data = $this->decode($this->callTool($this->makeService(), 'list_extensions', []))['data'];

        $this->assertSame(['1.0.4', null], array_column($data, 'version'));
    }

    /**
     * Queues what one call reads: the update sites, then their extension links,
     * then the linked extensions.
     *
     * @param  list<array<string, mixed>>  $sites
     */
    private function queueSites(array $sites): void
    {
        $this->db->resultSets[] = $sites;
        $this->db->resultSets[] = array_map(
            static fn (array $site): array => ['update_site_id' => $site['update_site_id'], 'extension_id' => 42],
            $sites
        );
        $this->db->resultSets[] = [$this->extensionRow()];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function siteRow(array $overrides = []): array
    {
        return $overrides + [
            'update_site_id' => 7,
            'name' => 'Example updates',
            'type' => 'extension',
            'location' => 'https://example.test/updates.xml',
            'enabled' => 1,
            'last_check_timestamp' => 1793534400,
            'extra_query' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function extensionRow(array $overrides = []): array
    {
        return $overrides + [
            'extension_id' => 42,
            'name' => 'Example',
            'type' => 'package',
            'element' => 'pkg_example',
            'folder' => '',
            'client_id' => 0,
            'enabled' => 1,
            'manifest_cache' => '{"name":"Example","version":"1.0.4"}',
        ];
    }

    private function writePackageManifest(string $element, string $dlid): void
    {
        $path = JPATH_ADMINISTRATOR . '/manifests/packages/' . $element . '.xml';

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, '<extension type="package">' . $dlid . '</extension>');
        $this->writtenFiles[] = $path;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decode(array $response): array
    {
        $this->assertFalse(
            $response['result']['isError'] ?? false,
            (string) ($response['result']['content'][0]['text'] ?? '')
        );

        return json_decode($response['result']['content'][0]['text'], true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callExpectingError(string $tool, array $arguments, ?RpcService $service = null): string
    {
        $response = $this->callTool($service ?? $this->makeService(), $tool, $arguments);

        $this->assertTrue($response['result']['isError'] ?? false);

        return (string) $response['result']['content'][0]['text'];
    }

    private function makeService(bool $readOnly = false): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn($readOnly);

        return new RpcService(
            $this->createMock(RestClient::class),
            new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(RpcService $service, string $name, array $arguments): array
    {
        return $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }
}
