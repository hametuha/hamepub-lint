<?php

namespace Hametuha\PubLint;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * Validator
 * @package publint
 */
class Validator
{

    /**
     * Latest version.
     */
    const EPUB_LATEST = '4.0.2';

    /**
     * Get root directory of jar.
     *
     * @return string
     */
    public static function jarRoot()
    {
        return self::base() . '/' . 'jar';
    }

    /**
     * Get available version.
     *
     * @return array
     */
    public static function getAvailables()
    {
        $jar = self::jarRoot();
        $versions = [];
        if (is_dir($jar)) {
            foreach (scandir($jar) as $dir) {
                if (preg_match('#epubcheck-(\d+\.\d+(\.\d+)?)#u', $dir, $match)) {
                    $versions[] = $match[1];
                }
            }
        }
        return $versions;
    }

    /**
     * Check if directory exists.
     *
     * @param string $version
     * @return string
     */
    public static function getJarFile($version)
    {
        if (!preg_match('#^\d+\.\d+(\.\d+)?$#u', $version)) {
            return '';
        }
        $jar_root = self::jarRoot() . '/epubcheck-' . $version;
        if (!is_dir($jar_root)) {
            return '';
        }
        $jar_file = '';
        foreach (['epubcheck.jar', "epubcheck-{$version}.jar"] as $j) {
            $jar_file_path = $jar_root . '/' . $j;
            if (file_exists($jar_file_path)) {
                $jar_file = $jar_file_path;
                break;
            }
        }
        return $jar_file;
    }

    /**
     * Get project root dir.
     *
     * @return string
     */
    public static function base()
    {
        return dirname(dirname(dirname(__DIR__)));
    }

    /**
     * Validate post body.
     *
     * @param string $version ePUB validator version.
     *
     * @return string
     * @throws \Exception
     */
    public static function validatePostBody($version)
    {
        $body = base64_decode(file_get_contents('php://input'));

        return self::validate($body, $version);
    }

    /**
     * Generate validation command.
     *
     * @param string $jar     Jar executable.
     * @param string $epub    ePub file path.
     * @param string $xml     Result XML file path.
     * @param string $version Version of ePubCheck
     * @return string
     */
    protected static function generateCommand($jar, $epub, $xml, $version)
    {
        return sprintf('java -jar %s %s -o %s', $jar, $epub, $xml);
    }


    /**
     * Validate ePub.
     *
     * @param string $data Binary data.
     * @param string $version ePub validator version.
     *
     * @return string
     * @throws \Exception Failed to get XML result, throws error.
     */
    public static function validate($data, $version)
    {
        // Check jar file.
        $jar_file = self::getJarFile($version);
        if (!$jar_file) {
            throw new \Exception('Specified version is not found.', 404);
        }
        // Save epub.
        $tmp_name = tempnam(sys_get_temp_dir(), 'epub');
        $tmp_epub = $tmp_name . '.epub';
        $tmp_result = $tmp_name . '.xml';
        if (file_exists($tmp_epub) || !is_writable(dirname($tmp_epub)) || !file_put_contents($tmp_epub, $data)) {
            throw new \Exception('Cannot write file. Please try again later.', 403);
        }
        // Validate epub.
        $command = self::generateCommand($jar_file, $tmp_epub, $tmp_result, $version);
        $result = exec($command, $output);
        if (file_exists($tmp_result)) {
            return $tmp_result;
        } else {
            throw new \Exception('Failed to run validator.', 500);
        }
    }

    /**
     * Handle Request
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return void
     */
    public static function handlePostRequest(Request $request, Response $response, array $args)
    {
        try {
            $version = isset( $args['version'] ) ? $args['version'] : self::EPUB_LATEST;
            $xml_path = Validator::validatePostBody($version);
            header( 'Content-Type: text/xml; charset=UTF-8');
            readfile( $xml_path );
            exit;
        } catch (\Exception $e) {
            return $response->withJson([
                'message' => $e->getMessage(),
                'status'  => $e->getCode(),
            ], $e->getCode());
        }
    }
}
