<?php

namespace Hametuha\PubLint;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Validator
 * @package publint
 */
class Validator
{

    /**
     * Max size of decoded ePub in bytes.
     *
     * Cloud Run rejects requests over 32MB, and base64 inflates the body by 4/3.
     */
    const MAX_EPUB_SIZE = 24 * 1024 * 1024;

    /**
     * Get root directory of jar.
     *
     * Can be overridden by EPUBCHECK_DIR (e.g. /opt/epubcheck in container).
     *
     * @return string
     */
    public static function jarRoot()
    {
        $dir = getenv('EPUBCHECK_DIR');
        return $dir ?: self::base() . '/jar';
    }

    /**
     * Get available versions, newest first.
     *
     * @return string[]
     */
    public static function getAvailables()
    {
        $jar = self::jarRoot();
        $versions = [];
        if (is_dir($jar)) {
            foreach (scandir($jar) as $dir) {
                if (preg_match('#^epubcheck-(\d+\.\d+(\.\d+)?)$#u', $dir, $match) && self::getJarFile($match[1])) {
                    $versions[] = $match[1];
                }
            }
        }
        usort($versions, function ($a, $b) {
            return version_compare($b, $a);
        });
        return $versions;
    }

    /**
     * Get the newest available version.
     *
     * @return string Empty string if no validator is installed.
     */
    public static function latest()
    {
        $versions = self::getAvailables();
        return $versions ? $versions[0] : '';
    }

    /**
     * Get jar file path of the version.
     *
     * @param string $version
     * @return string Empty string if not found.
     */
    public static function getJarFile($version)
    {
        if (!preg_match('#^\d+\.\d+(\.\d+)?$#u', $version)) {
            return '';
        }
        $jar_file = self::jarRoot() . '/epubcheck-' . $version . '/epubcheck.jar';
        return file_exists($jar_file) ? $jar_file : '';
    }

    /**
     * Get JVM options tuned for one-shot execution.
     *
     * ePubCheck runs once per request, so startup time matters more than peak performance.
     * If a class data sharing archive (built in Dockerfile) exists next to the jar, use it.
     *
     * @param string $jar_file
     * @return string
     */
    public static function javaOptions($jar_file)
    {
        $options = ['-XX:TieredStopAtLevel=1', '-XX:+UseSerialGC'];
        $archive = dirname($jar_file) . '/epubcheck.jsa';
        if (file_exists($archive)) {
            $options[] = '-XX:SharedArchiveFile=' . escapeshellarg($archive);
        }
        return implode(' ', $options);
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
     * Decode base64 encoded request body.
     *
     * @param string $body
     * @return string Binary data.
     * @throws \Exception
     */
    public static function decodeBody($body)
    {
        // Form-encoded clients may turn "+" into " ". Restore before decoding.
        // Trim line breaks only: a leading space may be a "+".
        $data = base64_decode(strtr(trim($body, "\r\n"), ' ', '+'), true);
        if (false === $data || '' === $data) {
            throw new \Exception('Request body must be a BASE64 encoded ePub.', 400);
        }
        if (strlen($data) > self::MAX_EPUB_SIZE) {
            throw new \Exception('ePub is too large.', 413);
        }
        return $data;
    }

    /**
     * Validate ePub.
     *
     * @param string $data    Binary data.
     * @param string $version ePub validator version.
     *
     * @return \SimpleXMLElement Result of ePubCheck.
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
        if (!$tmp_name) {
            throw new \Exception('Cannot write file. Please try again later.', 500);
        }
        $tmp_epub = $tmp_name . '.epub';
        $tmp_result = $tmp_name . '.xml';
        try {
            file_put_contents($tmp_epub, $data);
            // Validate epub.
            $command = sprintf(
                'java %s -jar %s %s -o %s 2>&1',
                self::javaOptions($jar_file),
                escapeshellarg($jar_file),
                escapeshellarg($tmp_epub),
                escapeshellarg($tmp_result)
            );
            exec($command, $output);
            $xml = file_exists($tmp_result) ? simplexml_load_file($tmp_result) : false;
            if (!$xml) {
                error_log('ePubCheck failed: ' . implode("\n", $output));
                throw new \Exception('Failed to run validator.', 500);
            }
            return $xml;
        } finally {
            // Cloud Run's /tmp is in memory. Never leave files behind.
            foreach ([$tmp_name, $tmp_epub, $tmp_result] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Convert ePubCheck XML to API result.
     *
     * @param \SimpleXMLElement $xml
     * @return array
     */
    public static function toResult(\SimpleXMLElement $xml)
    {
        $json = [
            'success' => false,
            'status'  => 'error',
            'messages' => [],
        ];
        if ($xml->repInfo->messages->count()) {
            $error = 0;
            foreach ($xml->repInfo->messages->message as $message) {
                // Check if message is fatal
                if (preg_match('#(FATAL|ERROR)#ui', $message)) {
                    $error++;
                }
                $json['messages'][] = (string) $message;
            }
            if (!$error) {
                $json['success'] = true;
                $json['status'] = 'success_with_error';
            }
        } else {
            $json['success'] = true;
            $json['status'] = 'success';
            $json['messages'][] = 'SUCCESS: This ePub is valid!';
        }
        return $json;
    }

    /**
     * Write JSON response.
     *
     * @param Response $response
     * @param array    $data
     * @param int      $status
     * @return Response
     */
    public static function json(Response $response, array $data, $status = 200)
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * Handle Request
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public static function handlePostRequest(Request $request, Response $response, array $args)
    {
        try {
            $version = isset($args['version']) ? $args['version'] : self::latest();
            $data = self::decodeBody((string) $request->getBody());
            return self::json($response, self::toResult(self::validate($data, $version)));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 500;
            }
            return self::json($response, [
                'messages' => [ $e->getMessage() ],
                'status'   => $code,
                'success'  => false,
            ], $code);
        }
    }
}
