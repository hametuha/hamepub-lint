<?php

namespace Tests\Unit;

use Hametuha\PubLint\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\EpubFactory;

class ValidatorTest extends TestCase
{

    public function testAvailablesAreSortedNewestFirst()
    {
        $versions = Validator::getAvailables();
        $this->assertNotEmpty($versions, 'No ePubCheck is installed. Set EPUBCHECK_DIR or run bin/installer.sh.');
        $sorted = $versions;
        usort($sorted, fn($a, $b) => version_compare($b, $a));
        $this->assertSame($sorted, $versions);
        $this->assertSame($versions[0], Validator::latest());
    }

    public function testInvalidVersionIsRejected()
    {
        $this->assertSame('', Validator::getJarFile('../../etc/passwd'));
        $this->assertSame('', Validator::getJarFile('99.0.0'));
    }

    public function testDecodeBodyRestoresPlusSign()
    {
        $data = "\xfb\xff";
        $encoded = base64_encode($data);
        $this->assertStringContainsString('+', $encoded);
        $this->assertSame($data, Validator::decodeBody(strtr($encoded, '+', ' ')));
    }

    public function testDecodeBodyRejectsGarbage()
    {
        $this->expectExceptionCode(400);
        Validator::decodeBody('!!!not base64!!!');
    }

    public static function versionProvider()
    {
        return array_map(fn($v) => [$v], Validator::getAvailables());
    }

    #[DataProvider('versionProvider')]
    public function testValidEpubPasses($version)
    {
        $result = Validator::toResult(Validator::validate(EpubFactory::create(), $version));
        $this->assertTrue($result['success'], implode("\n", $result['messages']));
    }

    #[DataProvider('versionProvider')]
    public function testBrokenEpubFails($version)
    {
        $result = Validator::toResult(Validator::validate(EpubFactory::create(true), $version));
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['messages']);
    }

    public function testTempFilesAreRemoved()
    {
        $before = glob(sys_get_temp_dir() . '/epub*');
        Validator::validate(EpubFactory::create(), Validator::latest());
        $this->assertSame($before, glob(sys_get_temp_dir() . '/epub*'));
    }
}
