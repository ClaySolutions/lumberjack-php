<?php
namespace Ekho\Logstash\Lumberjack;

interface SocketInterface
{
    /**
     * @param mixed $buffer
     * @return int
     */
    public function write($buffer);

    /**
     * @param int $number
     * @return mixed
     */
    public function read($number);
}
