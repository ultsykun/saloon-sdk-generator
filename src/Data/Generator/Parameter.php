<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

use Illuminate\Support\Str;

class Parameter
{
    public function __construct(
        public string $type,
        public bool $nullable,
        public string $name,
        public ?string $description = null,
        public ?string $format = null,
        public ?bool $isDto = false,
        public ?string $classFQN = null,
    ) {
    }

    public function isClassFQNIterable(): string
    {
        return str_ends_with((string) $this->classFQN, '[]');
    }

    public function getClassFQN(): ?string
    {
        return $this->classFQN ? str_replace('[]', '', $this->classFQN) : null;
    }

    public function getClassFQNDocsType(): ?string
    {
        if (null === $this->classFQN) {
            return null;
        }

        return Str::afterLast($this->classFQN, '\\');
    }

    public function getClassFQNFormatArg(): ?string
    {
        if (null === $this->classFQN) {
            return null;
        }

        if ($this->isClassFQNIterable()) {
            return sprintf('"%s"', $this->classFQN);
        }

        $responseShort = Str::afterLast($this->getClassFQN(), '\\');

        return "{$responseShort}::class";
    }

    public function getClassFQNReturnType(): ?string
    {
        return $this->isClassFQNIterable() ? 'array' : $this->getClassFQN();
    }
}
