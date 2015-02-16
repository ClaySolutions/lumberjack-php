<?php
namespace Ekho\Logstash\Lumberjack;

interface SocketInterface
{
    /**
     * @return string
     */
    public function getHost();

    /**
     * @return int
     */
    public function getPort();

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
