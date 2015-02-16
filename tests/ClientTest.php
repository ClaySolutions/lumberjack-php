<?php
namespace Ekho\Logstash\Lumberjack;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    /** @var Client */
    private $instance;
    /** @var SocketInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $socket;
    /** @var EncoderInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $encoder;

    protected function setUp()
    {
        $this->socket = $this->getMock(__NAMESPACE__ . '\\SocketInterface');
        $this->encoder = $this->getMock(__NAMESPACE__ . '\\EncoderInterface');

        $this->instance = new Client($this->socket, $this->encoder, 2);
    }

    public function testWrite()
    {
        $testData = array('a' => 'b');
        $encodedData = json_encode($testData);
        $dataLength = strlen($encodedData);

        $this->encoder
            ->expects($this->once())
            ->method('toCompressedFrame')
            ->with(
                $this->equalTo($testData),
                $this->equalTo(1)
            )
            ->willReturn($encodedData);

        $this->socket
            ->expects($this->once())
            ->method('write')
            ->with($this->equalTo($encodedData))
            ->willReturn($dataLength);

        $actual = $this->instance->write($testData);

        $this->assertEquals($dataLength, $actual);
    }
}
