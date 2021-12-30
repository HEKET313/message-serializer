<?php

namespace Happyr\MessageSerializer;

use Symfony\Component\Messenger\Stamp\StampInterface;

class CustomHeaderStamp implements StampInterface
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var mixed
     */
    private $value;

    public function __construct(string $name, $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }
}