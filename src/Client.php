<?php
namespace Ekho\Logstash\Lumberjack;

class Client
{
    const SEQUENCE_MAX = 4294967295;

    /** @var int */
    private $sequence = 0;

    /** @var int */
    private $lastAck = 0;

    /** @var int */
    private $windowSize;

    /** @var SocketInterface */
    private $socket;

    /** @var EncoderInterface */
    private $encoder;

    /**
     * @param SocketInterface $socket
     * @param EncoderInterface $encoder
     * @param int $windowSize
     */
    public function __construct(SocketInterface $socket, EncoderInterface $encoder, $windowSize = 5000)
    {
        $this->socket = $socket;
        $this->encoder = $encoder;
        $this->setWindowSize($windowSize);
    }

    /**
     * @param array $hash
     * @return int
     */
    public function write(array $hash)
    {
        $frame = $this->encoder->toCompressedFrame($hash, $this->nextSequence());
        if ($this->unackedSequenceSize() >= $this->windowSize) {
            $this->ack();
        }
        return $this->socket->write($frame);
    }

    /**
     * @param int $windowSize
     */
    private function setWindowSize($windowSize)
    {
        $this->windowSize = $windowSize;
        $buffer = pack('AAN', "1", "W", $windowSize);
        $this->socket->write($buffer);
    }

    /**
     * @return int
     */
    private function nextSequence()
    {
        if ($this->sequence + 1 > self::SEQUENCE_MAX) {
            $this->sequence = 0;
        }

        return ++$this->sequence;
    }

    /**
     * @throws \RuntimeException
     */
    private function ack()
    {
        list(, $type) = $this->readVersionAndType();
        if ($type != 'A') {
            throw new \RuntimeException(sprintf("Whoa we shouldn't get this frame: %s", var_export($type, true)));
        }
        $this->lastAck = $this->readLastAck();
        if ($this->unackedSequenceSize() >= $this->windowSize) {
            $this->ack();
        }
    }

    /**
     * @return int
     */
    private function unackedSequenceSize()
    {
        return $this->sequence - ($this->lastAck + 1);
    }

    /**
     * @return array
     */
    private function readVersionAndType()
    {
        $version = $this->socket->read(1);
        $type = $this->socket->read(1);
        return array($version, $type);
    }

    /**
     * @return int
     */
    private function readLastAck()
    {
        $unpacked = unpack('N', $this->socket->read(4));
        return reset($unpacked);
    }
}
