<?php

namespace Tests;

use App\EvmReconstructionChains as Chains;
use App\EvmReconstructionConfig;

// ------------------------------------------------------------------------- chains

test('chains: supported / unsupported sets and history start blocks (decisions 2, 3, 7)', function () {
    assertSame(['ethereum', 'base', 'polygon', 'binance-smart-chain'], Chains::SUPPORTED_CHAINS);
    assertSame(['avalanche', 'xdai'], Chains::UNSUPPORTED_CHAIN_KEYS);
    assertSame(0, Chains::historyStartBlock('ethereum'));
    assertSame(0, Chains::historyStartBlock('base'));
    assertSame(0, Chains::historyStartBlock('binance-smart-chain'));
    assertSame(80406107, Chains::historyStartBlock('polygon'));
    assertTrue(Chains::isSupported('polygon'));
    assertFalse(Chains::isSupported('avalanche'));
    assertThrows(\InvalidArgumentException::class, fn () => Chains::historyStartBlock('avalanche'));
});

test('chains: the four Base blocks are skipped, nothing else is', function () {
    assertSame([[44661819, 44661820], [44692818, 44692819]], Chains::skippedRanges('base'));

    foreach ([44661819, 44661820, 44692818, 44692819] as $block) {
        assertTrue(Chains::isSkipped('base', $block), (string) $block);
    }

    foreach ([44661818, 44661821, 44692817, 44692820, 0] as $block) {
        assertFalse(Chains::isSkipped('base', $block), (string) $block);
    }

    assertFalse(Chains::isSkipped('ethereum', 44661819), 'a skipped block is per chain');
    assertSame([], Chains::skippedRanges('polygon'));
});

test('chains: every supported chain has a history start and a skipped-blocks entry', function () {
    foreach (Chains::SUPPORTED_CHAINS as $chain) {
        assertTrue(array_key_exists($chain, Chains::HISTORY_START_BLOCK), $chain);
        assertTrue(array_key_exists($chain, Chains::SKIPPED_BLOCKS), $chain);
    }
});

test('chains: range normalisation sorts and merges, rejects nonsense', function () {
    assertSame([[1, 5], [10, 12]], Chains::normalizeRanges([11, [3, 5], 10, [1, 4], 12]));
    assertSame([[1, 3]], Chains::normalizeRanges([1, 2, 3]), 'adjacent blocks merge');
    assertSame([], Chains::normalizeRanges([]));
    assertThrows(\InvalidArgumentException::class, fn () => Chains::normalizeRanges([[5, 4]]));
    assertThrows(\InvalidArgumentException::class, fn () => Chains::normalizeRanges([-1]));
});

// ------------------------------------------------------------------------- config

test('config: an existing config.php without the section still works (code-level defaults)', function () {
    $config = new EvmReconstructionConfig(['db' => []]);

    assertSame([['name' => 'drpc', 'url' => 'https://eth.drpc.org']], $config->providers('ethereum'));
    assertSame([['name' => 'drpc', 'url' => 'https://base.drpc.org']], $config->providers('base'));
    assertSame([['name' => 'drpc', 'url' => 'https://polygon.drpc.org']], $config->providers('polygon'));
    assertSame([], $config->providers('binance-smart-chain'), 'BSC has no keyless archive provider');
    assertTrue(str_ends_with($config->lockDirectory(), EvmReconstructionConfig::DEFAULT_LOCK_DIRECTORY_NAME));
});

test('config: configuring a chain replaces its defaults, leaves the others alone', function () {
    $config = new EvmReconstructionConfig(['reconstruction' => ['rpc' => [
        'binance-smart-chain' => [['name' => 'nodereal', 'url' => 'https://bsc-mainnet.nodereal.io/v1/KEY']],
        'ethereum' => [
            ['name' => 'nodereal', 'url' => 'https://eth-mainnet.nodereal.io/v1/KEY'],
            'https://eth.drpc.org',
        ],
    ]]]);

    assertSame([['name' => 'nodereal', 'url' => 'https://bsc-mainnet.nodereal.io/v1/KEY']], $config->providers('binance-smart-chain'));
    assertSame(
        [
            ['name' => 'nodereal', 'url' => 'https://eth-mainnet.nodereal.io/v1/KEY'],
            ['name' => 'drpc', 'url' => 'https://eth.drpc.org'],
        ],
        $config->providers('ethereum'),
        'a bare URL string gets a name derived from its host (never from the path, which holds the key)'
    );
    assertSame([['name' => 'drpc', 'url' => 'https://base.drpc.org']], $config->providers('base'));
});

test('config: a single {name, url} map and a bare string are accepted as a list of one', function () {
    $config = new EvmReconstructionConfig(['reconstruction' => ['rpc' => [
        'polygon' => ['name' => 'ankr', 'url' => 'https://rpc.ankr.com/polygon/KEY'],
        'base' => 'https://base.drpc.org',
    ]]]);

    assertSame([['name' => 'ankr', 'url' => 'https://rpc.ankr.com/polygon/KEY']], $config->providers('polygon'));
    assertSame([['name' => 'drpc', 'url' => 'https://base.drpc.org']], $config->providers('base'));
});

test('config: lock directory is configurable, trailing slash trimmed', function () {
    $config = new EvmReconstructionConfig(['reconstruction' => ['lock_directory' => '/var/locks/wha/']]);

    assertSame('/var/locks/wha', $config->lockDirectory());
});

test('config: malformed sections fail loudly', function () {
    $bad = [
        ['reconstruction' => 'nope'],
        ['reconstruction' => ['rpc' => 'nope']],
        ['reconstruction' => ['rpc' => ['avalanche' => ['https://x.example']]]],
        ['reconstruction' => ['rpc' => ['ethereum' => []]]],
        ['reconstruction' => ['rpc' => ['ethereum' => [['name' => 'a', 'url' => 'ftp://x']]]]],
        ['reconstruction' => ['rpc' => ['ethereum' => [['name' => 'Bad Name', 'url' => 'https://x.example']]]]],
        ['reconstruction' => ['rpc' => ['ethereum' => [['name' => '../etc', 'url' => 'https://x.example']]]]],
        ['reconstruction' => ['rpc' => ['ethereum' => [
            ['name' => 'a', 'url' => 'https://x.example'],
            ['name' => 'a', 'url' => 'https://y.example'],
        ]]]],
    ];

    foreach ($bad as $i => $config) {
        assertThrows(\InvalidArgumentException::class, fn () => new EvmReconstructionConfig($config), "case #{$i}");
    }
});

test('config: config.example.php is itself a valid configuration', function () {
    $example = require __DIR__ . '/../config.example.php';
    $config = new EvmReconstructionConfig($example);

    assertSame('nodereal', $config->providers('binance-smart-chain')[0]['name']);
    assertSame('drpc', $config->providers('ethereum')[0]['name']);
});
