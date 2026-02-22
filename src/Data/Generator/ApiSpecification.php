<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

class ApiSpecification
{
    /**
     * @param  SecurityRequirement[]  $securityRequirements
     * @param  Endpoint[]  $endpoints
     */
    public function __construct(
        public ?string $name,
        public ?string $description,
        public ?BaseUrl $baseUrl,
        public array $securityRequirements = [],
        public array $endpoints = [],
        public ?Components $components = null,
        /** @var array<string, string|null> */
        public array $dtoClassesMap = [],
        public ?string $moduleName = null,
    ) {}
}
