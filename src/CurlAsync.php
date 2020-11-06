<?php

namespace gipfl\Curl;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use RuntimeException;
use function GuzzleHttp\Psr7\parse_response;
use function array_shift;
use function count;
use function curl_error;
use function curl_multi_add_handle;
use function curl_multi_close;
use function curl_multi_exec;
use function curl_multi_getcontent;
use function curl_multi_info_read;
use function curl_multi_init;
use function curl_multi_remove_handle;
use function curl_multi_select;
use function curl_multi_setopt;
use function React\Promise\resolve;

/**
 * This class provides an async CURL abstraction layer fitting into a ReactPHP
 * reality, implemented based on curl_multi.
 *
 * As long as there are requests pending, a timer fires
 *
 */
class CurlAsync
{
    const DEFAULT_POLLING_INTERVAL = 0.03;

    /** @var false|resource */
    protected $handle;

    /** @var Deferred[] resourceIdx => Deferred */
    protected $running = [];

    /** @var Deferred[] resourceIdx => Deferred */
    protected $pending = [];

    /** @var array resourceIdx => resource */
    protected $curl = [];

    /** @var int */
    protected $maxParallelRequests = 30;

    protected $maxParallelRequestsPerHost = 3;

    protected $handlesPerHost = [];

    /** @var LoopInterface */
    protected $loop;

    /** @var float */
    protected $fastInterval = self::DEFAULT_POLLING_INTERVAL;

    /** @var TimerInterface */
    protected $fastTimer;

    protected $curlOptions = [
        CURLOPT_HEADER         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_TCP_NODELAY    => true,
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_TCP_KEEPALIVE  => 1,
        CURLOPT_BUFFERSIZE     => 512 * 1024,
    ];

    /**
     * AsyncCurl constructor.
     * @param LoopInterface $loop
     */
    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->handle = curl_multi_init();
        // Hint: I had no specific reason to disable pipelining, nothing but
        // the desire to ease debugging. So, in case you feel confident you might
        // want to remove this line
        curl_multi_setopt($this->handle, CURLMOPT_PIPELINING, 0);
        if (! $this->handle) {
            throw new RuntimeException('Failed to initialize curl_multi');
        }
    }

    /**
     * @param int $max
     * @return $this
     */
    public function setMaxParallelRequests($max)
    {
        $this->maxParallelRequests = (int) $max;

        return $this;
    }

    public function send(RequestInterface $request)
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = "$name: $value";
            }
        }
        return $this
            ->getCurl($request->getMethod(), $request->getUri(), $headers)
            ->then(function ($curl) {
                return $this->curlRequest($curl);
            });
    }

    public function get($url, $headers = [])
    {
        return $this->send(new Request('GET', $url, $headers));
    }

    protected function getCurl($method, $url, $headers)
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (isset($this->handlesPerHost[$host])) {
            if (count($this->handlesPerHost[$host]) >= $this->maxParallelRequestsPerHost) {
                // $deferred = new Deferred()
            }
        }

        return resolve($this->createCurl($method, $url, $headers));
    }

    protected function createCurl($method, $url, $headers)
    {
        $curl = curl_init();
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_URL            => $url,
        ] + $this->curlOptions;
        curl_setopt_array($curl, $opts);

        return $curl;
    }

    /**
     * Promise resolves to a ResponseInterface
     *
     * @param resource $curl of type curl
     * @return \React\Promise\Promise
     */
    public function curlRequest($curl)
    {
        $deferred = new Deferred();
        $idx = (int) $curl;
        $this->curl[$idx] = $curl;
        $this->pending[] = [$idx, $deferred];
        $this->loop->futureTick(function () {
            $this->enablePolling();
            $this->eventuallyEnqueueNextRequest();
        });

        return $deferred->promise();
    }

    protected function eventuallyEnqueueNextRequest()
    {
        while (count($this->pending) > 0 && count($this->running) < $this->maxParallelRequests) {
            $next = array_shift($this->pending);
            $resourceIdx = $next[0];
            $this->running[$resourceIdx] = $next[1];
            $curl = $this->curl[$resourceIdx];
            // enqueued: curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
            curl_multi_add_handle($this->handle, $curl);
        }
    }

    protected function rejectAllPendingRequests()
    {
        foreach ($this->running as $resourceNum => $deferred) {
            unset($this->curl[$resourceNum]);
            $deferred->reject();
        }
        $this->running = [];
        foreach ($this->running as $resourceNum => $deferred) {
            $deferred->reject();
        }
    }

    /**
     * Returns true in case at least one request completed
     *
     * @return bool
     */
    protected function checkForResults()
    {
        if (empty($this->running)) {
            return false;
        } else {
            $handle = $this->handle;
            do {
                $status = curl_multi_exec($handle, $active);
            } while ($status > 0);
            // Hint: while ($status === CURLM_CALL_MULTI_PERFORM) ?

            if ($status !== CURLM_OK) {
                return false; // Fail? New curl_multi_init()? Does this ever happen?
            }
            if ($active) {
                $fds = curl_multi_select($handle, 0.01);
                // We take no action here, we'll info_read anyways:
                // $fds === -1 ->  select failed, returning. Probably only happens when running out of FDs
                // $fds === 0  ->  Nothing to do
            }

            $gotResult = false;
            while (false !== ($completed = curl_multi_info_read($handle))) {
                $this->requestCompleted($handle, $completed);
                if (empty($this->pending) && empty($this->running)) {
                    $this->disablePolling();
                }
                $gotResult = true;
            }

            return $gotResult;
        }
    }

    protected function requestCompleted($handle, $completed)
    {
        $curl = $completed['handle'];
        $resourceNum = (int) $curl;
        $deferred = $this->running[$resourceNum];
        unset($this->running[$resourceNum]);
        unset($this->curl[$resourceNum]);
        $content = curl_multi_getcontent($curl);
        curl_multi_remove_handle($handle, $curl);
        if ($completed['result'] === CURLE_OK) {
            $deferred->resolve(parse_response($content));
        } else {
            $deferred->reject(new \Exception(curl_error($curl)));
        }
    }

    /**
     * Set the polling interval used while requests are pending. Defaults to
     * self::DEFAULT_POLLING_INTERVAL
     *
     * @param float $interval
     */
    public function setInterval($interval)
    {
        if ($interval !== $this->fastInterval) {
            $this->fastInterval = $interval;
            $this->eventuallyReEnableTimer();
        }
    }

    protected function eventuallyReEnableTimer()
    {
        if ($this->fastTimer !== null) {
            $this->disablePolling();
            $this->enablePolling();
        }
    }

    /**
     * Polling timer should be active only while requests are pending
     */
    protected function enablePolling()
    {
        if ($this->fastTimer === null) {
            $this->fastTimer = $this->loop->addPeriodicTimer($this->fastInterval, function () {
                if ($this->checkForResults()) {
                    $this->eventuallyEnqueueNextRequest();
                }
            });
        }
    }

    protected function disablePolling()
    {
        if ($this->fastTimer) {
            $this->loop->cancelTimer($this->fastTimer);
            $this->fastTimer = null;
        }
    }

    public function __destruct()
    {
        curl_multi_close($this->handle);
    }
}
