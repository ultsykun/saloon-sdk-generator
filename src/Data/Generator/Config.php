<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

class Config
{
    public function __construct(
        public readonly ?string $moduleName,
        public readonly ?string $namespace,
        public readonly ?string $composerName = null,
        public readonly ?string $resourceNamespaceSuffix = 'Resource',
        public readonly ?string $requestNamespaceSuffix = 'Requests',
        public readonly ?string $dtoNamespaceSuffix = 'Dto',
        public readonly ?string $fallbackResourceName = 'Misc',
        public readonly array $ignoredQueryParams = [],
        public readonly array $ignoredBodyParams = [],
        public readonly array $ignoredHeaderParams = ['Authorization', 'Content-Type', 'Accept', 'Accept-Language', 'User-Agent'],
        public readonly array $extra = [],
        public readonly ?bool $generateGetters = true,
        public readonly ?bool $generateSetters = true,
        public readonly ?bool $publicPropVisibility = true,
        public readonly ?string $format = 'application/json',
        public array $overwritePropertiesType = [],

    ) {}
}
