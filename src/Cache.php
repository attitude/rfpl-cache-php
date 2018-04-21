<?php

namespace RFPL;

/**
 * Respond First, Process Later Class
 */
class Cache
{
    protected $path;
    protected $ttl = null;
    protected $refreshInterval = null;
    protected $nextCronTimestamp = null;
    protected $url;
    protected $filter = null;
    protected $alreadySent = false;
    protected $reset = false;

    public function __construct(array $options = [])
    {
        $defaults = [
            'path'            => 'cache',
            'ttl'             => 300, // seconds to pass request
            'refreshInterval' => null, // at each N * seconds to pass request
            'requestUri'      => $_SERVER['REQUEST_URI']
        ];

        // Merge options with defaults
        $options = array_replace($defaults, $options);
        extract($options);

        if (!realpath($path)) {
            // Try to create
            @mkdir($path, 0755, true);
        } elseif (!is_dir($path)) {
            throw new \Exception("Cache path must be a directory", 500);
        }

        $path = realpath($path);

        if (!$path) {
            throw new \Exception("Unable to create cache directory", 500);
        }

        $this->path = $path;

        if ($ttl >= 0) {
            $this->ttl = (int) $ttl;
        }

        if ($refreshInterval !== null) {
            $this->refreshInterval   = $this->parseCronJobStamp($refreshInterval);
            $this->nextCronTimestamp = $this->nextCronTimestamp($refreshInterval);
        }

        $this->url = $_SERVER['HTTP_HOST'].$requestUri;

        // Catch `header()` as it would normally pass to the response
        set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
            // Fast exit if already reseting cache
            if ($this->reset) {
                return false;
            }

            // Make sure we exit without echoing anything
            // ob_start();

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
            // ob_end_clean();

            // It is important to remember that the standard PHP error handler
            // is completely bypassed for the error types specified by error_types
            // unless the callback function returns FALSE.
            //
            // Source: http://php.net/manual/en/function.set-error-handler.php
            return false;
        });
    }

    /**
     */
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
    public function serve(callable $filter = null, $sendHeaders = true)
    {
        // Handle only GET requests
        if (strtolower($_SERVER['REQUEST_METHOD']) !== 'get') {
            // Delete cache file
            @unlink($this->cacheFile());

            throw new \Exception("Only GET requests can be cached", 301);
        }

        // Store filter and apply it after store procedure
        if ($filter && is_callable($filter)) {
            $this->filter = $filter;
        }

        // Get cache file path
        if ($file = realpath($this->cacheFile())) {
            // File modify time
            $filemtime = filemtime($file);

            if (!$this->shouldRefresh($filemtime)) {
                $content = file_get_contents($file);

                if ($this->filter) {
                    $filter = $this->filter;
                    $content = $filter($content);
                }

                $encoding = array_map('trim', explode(',', @$_SERVER['HTTP_ACCEPT_ENCODING'] ? $_SERVER['HTTP_ACCEPT_ENCODING'] : ''));

                if ($sendHeaders && in_array('gzip', $encoding)) {
                    $content = gzencode($content);
                    header('Content-Encoding: gzip');
                }

                $contentLength = strlen($content);

                if ($sendHeaders) {
                    header('Content-Length: '.$contentLength);
                    header('Connection: close');
                }

                $ttl = null;

                if ($this->nextCronTimestamp) {
                    $ttl = $this->nextCronTimestamp - $filemtime;
                }

                if ($this->ttl && $ttl) {
                    if ($this->ttl < $ttl) {
                        $ttl = $this->ttl;
                    }
                } else {
                    $ttl = $this->ttl;
                }

                if ($sendHeaders && $ttl) {
                    header('Cache-Control: max-age:'.($filemtime - time() + $ttl));
                    header_remove('Pragma');
                    header('Expires: '.date('r', $filemtime + $ttl));
                }

                ob_start();
                echo $content;
                ob_end_flush();
                ob_flush();
                flush();

                $this->alreadySent = true;

                // Cache is still fresh:
                if ($this->ttl !== null && $filemtime + $this->ttl >= time()) {
                    exit();
                }
            }
        }

        // Cache served was old, continue with processing
        // store output but don't output anything:
        ob_start([$this, 'store']);
    }

    /**
     * Checks whether cache is invalid
     *
     * @param int $filemtime File modification time
     * @return boolean
     *
     */
    protected function shouldRefresh(/*int*/ $filemtime)
    {
        if (!$this->refreshInterval) {
            return false;
        }

        foreach ($this->refreshInterval['year'] as $year) {
            foreach ($this->refreshInterval['month_year'] as $month) {
                foreach ($this->refreshInterval['day_month'] as $day) {
                    foreach ($this->refreshInterval['hour'] as $hour) {
                        foreach ($this->refreshInterval['minute'] as $minute) {
                            if ($time = strtotime("{$year}-{$month}-{$day} {$hour}:{$minute}")) {
                                if (in_array(date('N', $time), $this->refreshInterval['day_week']) && $time > $filemtime) {
                                    // var_dump([
                                    //     "{$year}-{$month}-{$day} {$hour}:{$minute}",
                                    //     $time,
                                    //     $filemtime
                                    // ]);
                                    // exit;
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Calculate next refresh timestamp
     *
     * @param string $cronJobTimestamp Interval string
     * @return int Unix timestamp
     *
     */
    protected function nextCronTimestamp($cronJobTimestamp)
    {
        if (!$this->refreshInterval) {
            throw new \Exception('parseCronJobStamp() must be run before running nextCronTimestamp()', 500);
        }

        $nextCronJobTimestamp = $this->refreshInterval;

        foreach (explode(' ', $cronJobTimestamp) as $i => &$v) {
            if ($v === '*') {
                switch ($i) {
                    case 0: $nextCronJobTimestamp['minute'][]     = 1; $nextCronJobTimestamp['minute'][] = max($nextCronJobTimestamp['minute']) + 1; break;
                    case 1: $nextCronJobTimestamp['hour'][]       = 1; $nextCronJobTimestamp['hour'][] = max($nextCronJobTimestamp['hour']) + 1; break;
                    case 2: $nextCronJobTimestamp['day_month'][]  = 1; $nextCronJobTimestamp['day_month'][] = max($nextCronJobTimestamp['day_month']) + 1; break;
                    case 3: $nextCronJobTimestamp['month_year'][] = 1; $nextCronJobTimestamp['month_year'][] = max($nextCronJobTimestamp['month_year']) + 1; break;
                    case 4: $nextCronJobTimestamp['day_week'][]   = 1; $nextCronJobTimestamp['day_week'][] = max($nextCronJobTimestamp['day_week']) + 1; break;
                    case 5: $nextCronJobTimestamp['year'][] = max($nextCronJobTimestamp['year']) + 1; break;
                }
            }
        }

        $timestamps = [];
        $now = time();

        foreach ($nextCronJobTimestamp['year'] as $year) {
            foreach ($nextCronJobTimestamp['month_year'] as $month) {
                foreach ($nextCronJobTimestamp['day_month'] as $day) {
                    foreach ($nextCronJobTimestamp['hour'] as $hour) {
                        foreach ($nextCronJobTimestamp['minute'] as $minute) {
                            $timestamp = strtotime("{$year}-{$month}-{$day} {$hour}:{$minute}");

                            if ($timestamp && $timestamp >= $now) {
                                $timestamps[] = $timestamp;
                            }
                        }
                    }
                }
            }
        }

        if (count($timestamps) === 0) {
            return null;
        }

        return min($timestamps);
    }

    /**
     * Parse Cronjob interval format
     *
     * @param string $cronJobTimestamp Interval string
     * @return array Array of intervals
     *
     */
    protected function parseCronJobStamp($cronJobTimestamp)
    {
        $cronJobTimestamp = explode(' ', $cronJobTimestamp);

        if (count($cronJobTimestamp) !== 6) {
            throw new \Exception("Cron job timestamp expects 6 values", 500);
        }

        foreach ($cronJobTimestamp as $i => &$v) {
            if ($v === '*') {
                switch ($i) {
                    case 0: $v = [(int) date('i')]; break;
                    case 1: $v = [(int) date('G')]; break;
                    case 2: $v = [(int) date('j')]; break;
                    case 3: $v = [(int) date('n')]; break;
                    case 4: $v = [(int) date('N')]; break;
                    case 5: $v = [(int) date('Y')]; break;
                }
            } elseif (preg_match('|^(\d+)-(\d+)/(\d+)$|', $v, $m) || preg_match('|^(\d+)-(\d+)$|', $v, $m)) {
                if ($m[1] >= $m[2]) {
                    throw new \Exception("First number must be less than the second of the range", 500);
                }

                $step = 1;

                if (isset($m[3])) {
                    if ($m[3] < 0) {
                        throw new \Exception("Range step must be more than zero", 500);
                    }

                    $step = $m[3];
                }

                $v = range($m[1], $m[2], $step);
            } elseif (preg_match('|^[\d,]+$|', $v, $m)) {
                $v = array_filter(explode(',', $v), function ($v) {
                    return $v === '0' || (int) $v > 0;
                });
            } else {
                throw new \Exception("Unsupported crojjob format", 500);
            }
        }

        $cronValues = [
            'minute'     => $cronJobTimestamp[0],
            'hour'       => $cronJobTimestamp[1],
            'day_month'  => $cronJobTimestamp[2],
            'month_year' => $cronJobTimestamp[3],
            'day_week'   => $cronJobTimestamp[4],
            'year'       => $cronJobTimestamp[5]
        ];

        foreach ($cronValues['minute'] as $v) {
            if ($v < 0 || $v > 59) {
                throw new \Exception("Out of range: {$v} minute", 1);
            }
        }

        foreach ($cronValues['hour'] as $v) {
            if ($v < 0 || $v > 23) {
                throw new \Exception("Out of range: {$v} hour", 1);
            }
        }

        foreach ($cronValues['day_month'] as $v) {
            if ($v < 1 || $v > 31) {
                throw new \Exception("Out of range: {$v} day of the month", 1);
            }
        }

        foreach ($cronValues['month_year'] as $v) {
            if ($v < 1 || $v > 12) {
                throw new \Exception("Out of range: {$v} month of the year", 1);
            }
        }

        foreach ($cronValues['day_week'] as $v) {
            if ($v < 1 || $v > 7) {
                throw new \Exception("Out of range: {$v} day of the week", 1);
            }
        }

        foreach ($cronValues['year'] as $v) {
            if ($v < 1900 || $v > 3000) {
                throw new \Exception("Out of range: {$v} year", 1);
            }
        }

        return $cronValues;
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
        } elseif ($this->reset) {
            // Delete cache file
            @unlink($file);
            // Try to delete parent directory; works only dor empty dirs
            @rmdir($dir);
        }

        if (!$this->alreadySent) {
            // Filter was stored, apply it to content and send response
            if ($this->filter) {
                $filter = $this->filter;
                return $filter($content);
            }

            // Send original buffer
            return false;
        }

        // Send nothing
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
