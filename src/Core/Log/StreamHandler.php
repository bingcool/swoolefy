<?php

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swoolefy\Core\Log;

use Swoolefy\Core\Log\Logger;

/**
 * Stores to any stream resource
 *
 * Can be used to store into php://stderr, remote and local files, etc.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class StreamHandler extends AbstractProcessingHandler
{
    protected $stream;
    protected $url;
    private $errorMessage;
    protected $filePermission;
    protected $useLocking;
    private $dirCreated;
    protected $reopenInterval = 0;
    protected $inodeCheckInterval = 1;
    private $nextReopenAt = 0;
    private $nextInodeCheckAt = 0;
    private $streamDevice = null;
    private $streamInode = null;

    /**
     * @param resource|string $stream
     * @param int $level The minimum logging level at which this handler will be triggered
     * @param Boolean $bubble Whether the messages that are handled can bubble up the stack or not
     * @param int|null $filePermission Optional file permissions (default (0644) are only for owner read/write)
     * @param Boolean $useLocking Try to lock log file before doing any writes
     *
     * @throws \Exception                If a missing directory is not buildable
     * @throws \InvalidArgumentException If stream is not a resource or string
     */
    public function __construct($stream, $level = Logger::DEBUG, $bubble = true, $filePermission = null, $useLocking = false)
    {
        parent::__construct($level, $bubble);
        if (is_resource($stream)) {
            $this->stream = $stream;
        } elseif (is_string($stream)) {
            $this->url = $stream;
        } else {
            throw new \InvalidArgumentException('A stream must either be a resource or a string.');
        }

        $this->filePermission = $filePermission;
        $this->useLocking = $useLocking;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->url && is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->streamDevice = null;
        $this->streamInode = null;
        $this->nextReopenAt = 0;
        $this->nextInodeCheckAt = 0;
    }

    /**
     * Return the currently active stream if it is open
     *
     * @return resource|null
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Return the stream URL if it was configured with a URL and not an active resource
     *
     * @return string|null
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Configure periodic stream reopen interval (seconds), 0 disables.
     *
     * @param int $seconds
     * @return $this
     */
    public function setReopenInterval(int $seconds)
    {
        $this->reopenInterval = max(0, $seconds);
        $this->nextReopenAt = 0;
        return $this;
    }

    /**
     * Configure inode check interval (seconds), 0 disables.
     *
     * @param int $seconds
     * @return $this
     */
    public function setInodeCheckInterval(int $seconds)
    {
        $this->inodeCheckInterval = max(0, $seconds);
        $this->nextInodeCheckAt = 0;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record)
    {
        if (!is_resource($this->stream)) {
            $this->openStream();
        } elseif ($this->shouldReopenStream()) {
            $this->reopenStream();
        }

        if ($this->useLocking) {
            // ignoring errors here, there's not much we can do about them
            flock($this->stream, LOCK_EX);
        }

        $this->streamWrite($this->stream, $record);

        if ($this->useLocking) {
            flock($this->stream, LOCK_UN);
        }
    }

    /**
     * Write to stream
     * @param resource $stream
     * @param array $record
     */
    protected function streamWrite($stream, array $record)
    {
        fwrite($stream, (string)$record['formatted']);
    }

    public function customErrorHandler($code, $msg)
    {
        $this->errorMessage = preg_replace('{^(fopen|mkdir)\(.*?\): }', '', $msg);
    }

    private function shouldReopenStream(): bool
    {
        return $this->shouldReopenByInterval() || $this->shouldReopenByInodeChange();
    }

    private function shouldReopenByInterval(): bool
    {
        if ($this->reopenInterval <= 0) {
            return false;
        }

        $now = time();
        if ($this->nextReopenAt <= 0) {
            $this->nextReopenAt = $now + $this->reopenInterval;
            return false;
        }

        if ($now >= $this->nextReopenAt) {
            $this->nextReopenAt = $now + $this->reopenInterval;
            return true;
        }

        return false;
    }

    private function shouldReopenByInodeChange(): bool
    {
        if ($this->inodeCheckInterval <= 0 || !is_resource($this->stream)) {
            return false;
        }

        $filePath = $this->getFilePathFromStream($this->url);
        if ($filePath === null || $filePath === '') {
            return false;
        }

        $now = time();
        if ($this->nextInodeCheckAt > 0 && $now < $this->nextInodeCheckAt) {
            return false;
        }
        $this->nextInodeCheckAt = $now + $this->inodeCheckInterval;

        if ($this->streamInode === null || $this->streamDevice === null) {
            $this->refreshStreamStat();
            if ($this->streamInode === null || $this->streamDevice === null) {
                return false;
            }
        }

        clearstatcache(true, $filePath);
        $pathStat = @stat($filePath);
        if (!is_array($pathStat) || !isset($pathStat['ino'], $pathStat['dev'])) {
            return false;
        }

        return ((int)$pathStat['ino'] !== (int)$this->streamInode) || ((int)$pathStat['dev'] !== (int)$this->streamDevice);
    }

    private function reopenStream()
    {
        $this->close();
        $this->openStream();
    }

    private function openStream()
    {
        if (null === $this->url || '' === $this->url) {
            throw new \Exception('Missing stream url, the stream can not be opened. This may be caused by a premature call to close().');
        }
        $this->createDir();
        $this->errorMessage = null;
        set_error_handler(array($this, 'customErrorHandler'));
        $this->stream = fopen($this->url, 'a');
        if ($this->filePermission !== null) {
            @chmod($this->url, $this->filePermission);
        }
        restore_error_handler();
        if (!is_resource($this->stream)) {
            $this->stream = null;
            throw new \UnexpectedValueException(sprintf('The stream or file "%s" could not be opened: ' . $this->errorMessage, $this->url));
        }

        $this->refreshStreamStat();
        $now = time();
        $this->nextReopenAt = ($this->reopenInterval > 0) ? ($now + $this->reopenInterval) : 0;
        $this->nextInodeCheckAt = ($this->inodeCheckInterval > 0) ? ($now + $this->inodeCheckInterval) : 0;
    }

    private function refreshStreamStat()
    {
        if (!is_resource($this->stream)) {
            $this->streamDevice = null;
            $this->streamInode = null;
            return;
        }

        $streamStat = @fstat($this->stream);
        if (is_array($streamStat) && isset($streamStat['ino'], $streamStat['dev'])) {
            $this->streamInode = (int)$streamStat['ino'];
            $this->streamDevice = (int)$streamStat['dev'];
            return;
        }

        $this->streamDevice = null;
        $this->streamInode = null;
    }

    /**
     * @param string $stream
     *
     * @return null|string
     */
    private function getDirFromStream($stream)
    {
        $pos = strpos($stream, '://');
        if ($pos === false) {
            return dirname($stream);
        }

        if ('file://' === substr($stream, 0, 7)) {
            return dirname(substr($stream, 7));
        }
    }

    /**
     * @param string $stream
     * @return string|null
     */
    private function getFilePathFromStream($stream)
    {
        $pos = strpos($stream, '://');
        if ($pos === false) {
            return $stream;
        }

        if ('file://' === substr($stream, 0, 7)) {
            return substr($stream, 7);
        }

        return null;
    }

    private function createDir()
    {
        // Do not try to create dir if it has already been tried.
        if ($this->dirCreated) {
            return;
        }

        $dir = $this->getDirFromStream($this->url);
        if (null !== $dir && !is_dir($dir)) {
            $this->errorMessage = null;
            set_error_handler(array($this, 'customErrorHandler'));
            $status = mkdir($dir, 0777, true);
            restore_error_handler();
            if (false === $status) {
                throw new \UnexpectedValueException(sprintf('There is no existing directory at "%s" and its not buildable: ' . $this->errorMessage, $dir));
            }
        }
        $this->dirCreated = true;
    }
}
