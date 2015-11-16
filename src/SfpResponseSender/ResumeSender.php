<?php
/**
 * The most of methods are derived from code of the HumusStreamResponseSender
 * Code subject to the MIT license (https://github.com/prolic/HumusStreamResponseSender/blob/master/LICENSE)
 * Copyright (c) 2013 Sascha-Oliver Prolic
 *
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace SfpResponseSender;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;

class ResumeSender
{
    /**
     * @var string
     */
    protected $requestRange;

    /**
     * @var Options
     */
    protected $options = [
        'enableRangeSupport' => false,
        'enableSpeedLimit' => false,
        'chunkSize' => 262144
    ];

    /**
     * @var int
     */
    private $rangeStart;

    /**
     * @var int
     */
    private $rangeEnd;

    /**
     * @param array
     * @param string $requestRange
     */
    public function __construct(array $options = [], $requestRange = null)
    {
        $this->options = array_merge($this->options, $options);
        $this->requestRange = $requestRange;
    }

    /**
     * Get options
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }


    /**
     * Send HTTP headers
     *
     */
    protected function setupHeaders(ResponseInterface $response)
    {
        if (! $response->hasHeader('Content-Transfer-Encoding')) {
            $response = $response->withHeader('Content-Transfer-Encoding', 'binary');
        }

        $size = $response->getBody()->getSize();
        $size2 = $size - 1;

        $length = $size;
        $this->rangeStart = 0;
        $this->rangeEnd = null;

        $enableRangeSupport = $this->getOptions()['enableRangeSupport'];

        if ($enableRangeSupport && $this->requestRange) {
            list($a, $range) = explode('=', $this->requestRange);
            if (substr($range, -1) == '-') {
                // range: 3442-
                $range = substr($range, 0, -1);
                if (!is_numeric($range) || $range > $size2) {
                    // 416 (Requested range not satisfiable)
                    $response = $response->withStatus(416);
                    return $response;
                }
                $this->rangeStart = $range;
                $length = $size - $range;
            } else {
                $ranges = explode('-', $range, 2);
                $rangeStart = $ranges[0];
                $rangeEnd = $ranges[1];
                if (!is_numeric($rangeStart)
                    || !is_numeric($rangeEnd)
                    || ($rangeStart >= $rangeEnd)
                    || $rangeEnd > $size2
                ) {
                    // 416 (Requested range not satisfiable)
                    $response = $response->withStatus(416);
                    return $response;
                }
                $this->rangeStart = $rangeStart;
                $this->rangeEnd = $rangeEnd;
                $length = $rangeEnd - $rangeStart;
                $size2 = $rangeEnd;
            }
            $response = $response->withStatus(206); // 206 (Partial Content)
        }

        $response = $response->withHeader('Content-Length', $length);

        if ($enableRangeSupport) {
            $response = $response->withHeader('Accept-Ranges', 'bytes');
            $response = $response->withHeader('Content-Range', 'bytes ' . $this->rangeStart . '-' . $size2 . '/' . $size);
        } else {
            $response = $response->withHeader('Accept-Ranges', 'none');
        }

        return $response;
    }

    /**
     * Send the stream
     *
     * @param SendResponseEvent $event
     * @return StreamResponseSender
     */
    public function emitBody(ResponseInterface $response)
    {
        $enableRangeSupport = $this->options['enableRangeSupport'];
        $enableSpeedLimit = $this->options['enableSpeedLimit'];

        // use fpassthru, if download speed limit and download resume are disabled
        if (!$enableRangeSupport && !$enableSpeedLimit) {
            $stream = $response->getBody()->detach();
            fpassthru($stream);
            return ;
        }

        set_time_limit(0);
        $rangeStart = $this->rangeStart;
        if (null !== $this->rangeEnd) {
            $rangeEnd = $this->rangeEnd;
            $length = $rangeEnd-$rangeStart;
        } else {
            $length = $response->getBody()->getSize();
        }

        // todo isSeekable check
        $response->getBody()->seek($rangeStart);
        $chunkSize = $options['chunkSize'];

        if ($chunkSize > $length) {
            $chunkSize = $length;
        }
        $sizeSent = 0;


        $body = $response->getBody();
        while (!$body->eof() && (connection_status()==0)) {

            echo $body->read($chunkSize);
            flush();

            $sizeSent += $chunkSize;

            if ($sizeSent == $length) {
                return ;
            }

            if ($enableSpeedLimit) {
                sleep(1);
            }
        }
    }
}
