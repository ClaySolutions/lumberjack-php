<?php
namespace Ekho\Logstash\Lumberjack;

interface EncoderInterface
{
    /**
     * @param array $hash
     * @param int $sequence
     * @return string
     */
    public function toCompressedFrame($hash, $sequence);

    /**
     * @param array $hash
     * @param int $sequence
     * @return string
     */
    public function toFrame($hash, $sequence);
}
