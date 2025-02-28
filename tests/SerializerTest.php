<?php

declare(strict_types=1);

namespace Tests\Happyr\MessageSerializer;

use Happyr\MessageSerializer\CustomHeaderStamp;
use Happyr\MessageSerializer\Hydrator\ArrayToMessageInterface;
use Happyr\MessageSerializer\Serializer;
use Happyr\MessageSerializer\Transformer\MessageToArrayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * @internal
 */
final class SerializerTest extends TestCase
{
    public function testDecode()
    {
        $transformer = $this->getMockBuilder(MessageToArrayInterface::class)->getMock();
        $hydrator = $this->getMockBuilder(ArrayToMessageInterface::class)
            ->setMethods(['toMessage'])
            ->getMock();

        $payload = ['a' => 'b'];
        $data = [
            'body' => \json_encode($payload),
        ];

        $hydrator->expects(self::once())
            ->method('toMessage')
            ->with($payload)
            ->willReturn(new \stdClass());

        $serializer = new Serializer($transformer, $hydrator);
        $output = $serializer->decode($data);

        self::assertInstanceOf(Envelope::class, $output);
        self::assertInstanceOf(\stdClass::class, $output->getMessage());
    }

    public function testDecodeWithRetryCount(): void
    {
        $transformer = $this->getMockBuilder(MessageToArrayInterface::class)->getMock();
        $hydrator = $this->getMockBuilder(ArrayToMessageInterface::class)
            ->getMock();

        $payload = [
            'a' => 'b',
        ];
        $data = [
            'body' => \json_encode(
                array_merge($payload, ['_meta' => ['retry-count' => 2]]),
                JSON_THROW_ON_ERROR, 512),
        ];

        $hydrator->expects(self::once())
            ->method('toMessage')
            ->with($payload)
            ->willReturn(new \stdClass());

        $serializer = new Serializer($transformer, $hydrator);
        $output = $serializer->decode($data);

        self::assertInstanceOf(\stdClass::class, $output->getMessage());
        /** @var RedeliveryStamp $redeliveryStamp */
        $redeliveryStamp = $output->last(RedeliveryStamp::class);
        self::assertEquals(2, $redeliveryStamp->getRetryCount());
    }

    public function testDecodeWithCustomHeaderStamp(): void
    {
        $transformer = $this->getMockBuilder(MessageToArrayInterface::class)->getMock();
        $hydrator = $this->getMockBuilder(ArrayToMessageInterface::class)
            ->getMock();

        $payload = [
            'a' => 'b',
        ];
        $data = [
            'body' => \json_encode(
                array_merge($payload, ['_meta' => ['retry-count' => 2]]),
                JSON_THROW_ON_ERROR, 512),
            'headers' => [
                'custom-header' => 'header-value',
            ]
        ];

        $hydrator->expects(self::once())
            ->method('toMessage')
            ->with($payload)
            ->willReturn(new \stdClass());

        $serializer = new Serializer($transformer, $hydrator);
        $output = $serializer->decode($data);

        self::assertInstanceOf(\stdClass::class, $output->getMessage());
        /** @var RedeliveryStamp $redeliveryStamp */
        $redeliveryStamp = $output->last(RedeliveryStamp::class);
        self::assertEquals(2, $redeliveryStamp->getRetryCount());
        /** @var CustomHeaderStamp $customHeader */
        $customHeader = $output->last(CustomHeaderStamp::class);
        self::assertEquals('header-value', $customHeader->getValue());
    }

    public function testEncode()
    {
        $transformer = $this->getMockBuilder(MessageToArrayInterface::class)
            ->setMethods(['toArray'])
            ->getMock();
        $hydrator = $this->getMockBuilder(ArrayToMessageInterface::class)->getMock();

        $envelope = new Envelope(new \stdClass('foo'));

        $transformer->expects(self::once())
            ->method('toArray')
            ->with($envelope)
            ->willReturn(['foo' => 'bar']);

        $serializer = new Serializer($transformer, $hydrator);
        $output = $serializer->encode($envelope);

        self::assertArrayHasKey('headers', $output);
        self::assertArrayHasKey('Content-Type', $output['headers']);
        self::assertEquals('application/json', $output['headers']['Content-Type']);

        self::assertArrayHasKey('body', $output);
        self::assertEquals(\json_encode(['foo' => 'bar', '_meta' => []]), $output['body']);
    }

    public function testEncodeWithCustomHeaderStamp()
    {
        $transformer = $this->getMockBuilder(MessageToArrayInterface::class)
            ->setMethods(['toArray'])
            ->getMock();
        $hydrator = $this->getMockBuilder(ArrayToMessageInterface::class)->getMock();

        $envelope = (new Envelope(new \stdClass('foo')))->with(new CustomHeaderStamp('custom-header', 'header-value'));

        $transformer->expects(self::once())
            ->method('toArray')
            ->with($envelope)
            ->willReturn(['foo' => 'bar']);

        $serializer = new Serializer($transformer, $hydrator);
        $output = $serializer->encode($envelope);

        self::assertArrayHasKey('headers', $output);
        self::assertArrayHasKey('Content-Type', $output['headers']);
        self::assertEquals('application/json', $output['headers']['Content-Type']);
        self::assertEquals('header-value', $output['headers']['custom-header']);

        self::assertArrayHasKey('body', $output);
        self::assertEquals(\json_encode(['foo' => 'bar', '_meta' => []]), $output['body']);
    }

    public function testEncodeWithRedeliveryStamp()
    {
        $transformer = $this->getMockBuilder(MessageToArrayInterface::class)
            ->getMock();
        $hydrator = $this->getMockBuilder(ArrayToMessageInterface::class)->getMock();

        $envelope = new Envelope(new \stdClass('foo'), [new RedeliveryStamp(2)]);

        $transformer->expects(self::once())
            ->method('toArray')
            ->with($envelope)
            ->willReturn(['foo' => 'bar']);

        $serializer = new Serializer($transformer, $hydrator);
        $output = $serializer->encode($envelope);

        self::assertArrayHasKey('headers', $output);
        self::assertArrayHasKey('Content-Type', $output['headers']);
        self::assertEquals('application/json', $output['headers']['Content-Type']);

        self::assertArrayHasKey('body', $output);
        self::assertEquals(\json_encode(
            [
                'foo' => 'bar',
                '_meta' => [
                    'retry-count' => 2,
                ],
            ], JSON_THROW_ON_ERROR, 512),
            $output['body']
        );
    }
}
