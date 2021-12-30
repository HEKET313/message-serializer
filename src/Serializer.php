<?php

declare(strict_types=1);

namespace Happyr\MessageSerializer;

use Happyr\MessageSerializer\Hydrator\ArrayToMessageInterface;
use Happyr\MessageSerializer\Hydrator\Exception\HydratorException;
use Happyr\MessageSerializer\Transformer\MessageToArrayInterface;
use http\Env;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class Serializer implements SerializerInterface
{
    private $transformer;
    private $hydrator;

    public function __construct(MessageToArrayInterface $transformer, ArrayToMessageInterface $hydrator)
    {
        $this->transformer = $transformer;
        $this->hydrator = $hydrator;
    }

    /**
     * {@inheritdoc}
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        if (empty($encodedEnvelope['body'])) {
            throw new MessageDecodingFailedException('Encoded envelope should have at least a "body".');
        }

        try {
            $array = \json_decode($encodedEnvelope['body'], true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MessageDecodingFailedException(\sprintf('Error when trying to json_decode message: "%s"', $encodedEnvelope['body']), 0, $e);
        }

        $meta = $array['_meta'] ?? [];
        unset($array['_meta']);

        try {
            $message = $this->hydrator->toMessage($array);
            $envelope = $message instanceof Envelope ? $message : new Envelope($message);
        } catch (HydratorException $e) {
            throw new MessageDecodingFailedException('Failed to decode message', 0, $e);
        }

        $stamps = $this->decodeStamps($encodedEnvelope);
        $envelope = $envelope->with(...$stamps);

        return $this->addMetaToEnvelope($meta, $envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function encode(Envelope $envelope): array
    {
        $envelope = $envelope->withoutStampsOfType(NonSendableStampInterface::class);

        $message = $this->transformer->toArray($envelope);
        $message['_meta'] = $this->getMetaFromEnvelope($envelope);

        return [
            'headers' => array_merge(['Content-Type' => 'application/json'], $this->encodeStamps($envelope)),
            'body' => \json_encode($message),
        ];
    }

    /**
     * @return StampInterface[]
     */
    private function decodeStamps(array $encodedEnvelope): array
    {
        $stamps = [];
        foreach ($encodedEnvelope['headers'] ?? [] as $name => $value) {
            $stamps[] = new CustomHeaderStamp($name, $value);
        }
        return $stamps;
    }

    private function encodeStamps(Envelope $envelope): array
    {
        $headers = [];
        foreach ($envelope->all(CustomHeaderStamp::class) as $stamp) {
            $headers[$stamp->getName()] = $stamp->getValue();
        }
        return $headers;
    }

    private function getMetaFromEnvelope(Envelope $envelope): array
    {
        $meta = [];

        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        if ($redeliveryStamp instanceof RedeliveryStamp) {
            $meta['retry-count'] = $redeliveryStamp->getRetryCount();
        }

        return $meta;
    }

    private function addMetaToEnvelope(array $meta, Envelope $envelope): Envelope
    {
        if (isset($meta['retry-count'])) {
            $envelope = $envelope->with(new RedeliveryStamp((int) $meta['retry-count']));
        }

        return $envelope;
    }
}
