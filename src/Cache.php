<?php

namespace RFPL;

/**
 * Respond First, Process Later Class
 */
class Cache
{
    protected $path;
    protected $ttl;
    protected $url;
    protected $filter = null;
    protected $reset = false;

    public function __construct(array $options = [])
    {
        $defaults = [
            'path' => 'cache',
            'ttl' => 300 // seconds to pass request
        ];

        // Merge options with defaults
        $options = array_replace($defaults, $options);
        extract($options);

        if (realpath($path) && !is_dir(realpath($path))) {
            throw new \Exception("Cache path must be a directory", 500);
        }

        // Try to create
        @mkdir($path, 0755, true);

        if (!realpath($path)) {
            throw new \Exception("Unable to create cache directory", 500);
        }

        $this->path = realpath($path);

        if ($ttl >= 0) {
            $this->ttl = (int) $ttl;
        }

        $this->url = $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];

        // Catch `header()` as it would normally pass to the response
        set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
            // Fast exit if already reseting cache
            if ($this->reset) {
                return false;
            }

            // Make shure we exit without echoing anything
            ob_start();

            // First check
            if (strstr($errstr, 'Cannot modify header information - headers already sent')) {
                // Get backtrace
                $backtrace = debug_backtrace(null, 2);
                // file_put_contents('logs/'.microtime(true).'.txt', print_r([$this->reset, func_get_args(), $backtrace], true));

                // Second check: only `header` function passes
                // If 3rd argument is used, than we're passing a HTTP status code
                if (count($backtrace) === 2 && $backtrace[1]['function'] === 'header') {
                    if (count($backtrace[1]['args']) === 3 && $backtrace[1]['args'][2] >= 300) {
                        $this->resetCache();
                        // file_put_contents('logs/'.microtime(true).'-reset1.txt', print_r($this->reset, true));
                    }

                    if (count($backtrace[1]['args']) > 0) {
                        // Or maybe check the header string for HTTP/1.1 Status code
                        if (preg_match('|^HTTP/\d+.\d+\s+(\d+)|', $backtrace[1]['args'][0], $statusCode)) {
                            if ($statusCode[1] != 200) {
                                $this->resetCache();
                                // file_put_contents('logs/'.microtime(true).'-reset2.txt', print_r($this->reset, true));
                            }
                        }
                    }
                }
            }

            // Clean any output
            ob_end_clean();

            // It is important to remember that the standard PHP error handler
            // is completely bypassed for the error types specified by error_types
            // unless the callback function returns FALSE.
            //
            // Source: http://php.net/manual/en/function.set-error-handler.php
            return false;
        });
    }

    protected function resetCache()
    {
        $this->reset = true;
    }

    /**
     * Serve cache of current request
     *
     * @param callable $filter Filter to apply to content string
     * @return void
     *
     */
    public function serve(callable $filter = null)
    {
        // Handle only GET requests
        if (strtolower($_SERVER['REQUEST_METHOD']) !== 'get') {
            throw new \Exception("Only GET requests can be cached", 301);
        }

        // Get cache file path
        if ($file = realpath($this->cacheFile())) {
            $content = file_get_contents($file);

            if ($filter && is_callable($filter)) {
                $content = $filter($content);
            }

            $encoding = array_map('trim', explode(',', @$_SERVER['HTTP_ACCEPT_ENCODING'] ? $_SERVER['HTTP_ACCEPT_ENCODING'] : ''));

            if (in_array('gzip', $encoding)) {
                $content = gzencode($content);
                header('Content-Encoding: gzip');
            }

            $contentLength = strlen($content);
            header('Content-Length: '.$contentLength);
            header('Connection: close');

            ob_start();
            echo $content;
            ob_end_flush();
            ob_flush();
            flush();

            // Cache is still fresh:
            if (filemtime($file) + $this->ttl >= time()) {
                exit;
            }
        } else {
            // Remove cache so the next time 404 or 301 could occur
            @unlink($file);
            @rmdir(dirname($file));

            // Store filter and apply it after store procedure
            $this->filter = $filter;
        }

        // Cache served was old, continue with processing
        // store output but don't output anything:
        ob_start([$this, 'store']);
    }

    /**
     * Stores response to cache
     *
     * @param string $content Buffered output string
     * @return string Empty string or buffer
     *
     */
    public function store($content)
    {
        $statusCode = http_response_code();

        $file = $this->cacheFile();
        $dir  = dirname($file);

        if (!$this->reset && $statusCode === 200) {
            // Create dir if not exists
            @mkdir($dir, 0755, true);

            if (!realpath($dir)) {
                throw new \Exception("Unable to create cache file directory", 500);
            }

            if (!file_put_contents($file, $content)) {
                throw new \Exception("Failed to store cache for $url", 500);
            }
        } else {
            // Delete cache file
            @unlink($file);
            // Try to delete parent directory; works only dor empty dirs
            @rmdir($dir);

            // Forces default output in case of no cached response exists
            return false;
        }

        // Filter was stored, apply it to content and send response
        if ($this->filter !== null && is_callable($this->filter)) {
            $filter = $this->filter;

            return $filter($content);
        }

        // No response, cache already sent
        return '';
    }

    protected function cacheFile()
    {
        return $this->path.'/'.$this->hash($this->url);
    }

    protected function hash($str)
    {
        $str = hash('sha256', $str);

        return substr($str, 0, 2).'/'.substr($str, 2);
    }
}
