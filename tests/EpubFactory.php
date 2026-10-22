<?php

namespace Tests;

/**
 * Build minimal EPUB 3 files for tests.
 */
class EpubFactory
{

    /**
     * Create a valid EPUB 3 and return its binary.
     *
     * @param bool $broken If true, drop the nav document to produce an invalid ePub.
     * @return string
     */
    public static function create($broken = false)
    {
        $path = tempnam(sys_get_temp_dir(), 'test-epub');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        // mimetype must be the first entry and stored uncompressed.
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML
        );
        $nav_item = $broken ? '' : '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
        $zip->addFromString('OEBPS/content.opf', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid" xml:lang="ja">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="uid">urn:uuid:0b6b6c2e-6a37-4e0b-9a3e-2f6f4b0f8a11</dc:identifier>
    <dc:title>テスト</dc:title>
    <dc:language>ja</dc:language>
    <meta property="dcterms:modified">2026-10-22T00:00:00Z</meta>
  </metadata>
  <manifest>
    {$nav_item}
    <item id="ch1" href="ch1.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="ch1"/>
  </spine>
</package>
XML
        );
        $zip->addFromString('OEBPS/nav.xhtml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="ja">
<head><title>目次</title></head>
<body>
<nav epub:type="toc"><ol><li><a href="ch1.xhtml">第一章</a></li></ol></nav>
</body>
</html>
XML
        );
        $zip->addFromString('OEBPS/ch1.xhtml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ja">
<head><title>第一章</title></head>
<body><p>本文。</p></body>
</html>
XML
        );
        $zip->close();
        $data = file_get_contents($path);
        unlink($path);
        return $data;
    }
}
