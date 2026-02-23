<?php

namespace Crescat\SaloonSdkGenerator\Parsers;

use cebe\openapi\DocumentContextInterface;
use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\Components;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter as OpenApiParameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Paths;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use cebe\openapi\spec\Server;
use cebe\openapi\spec\Type;
use Crescat\SaloonSdkGenerator\Contracts\Parser;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiKeyLocation;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\BaseUrl;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Method;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Data\Generator\SecurityScheme;
use Crescat\SaloonSdkGenerator\Data\Generator\SecuritySchemeType;
use Crescat\SaloonSdkGenerator\Data\Generator\ServerParameter;
use Crescat\SaloonSdkGenerator\Helpers\BodySchemaNameGenerator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Services\OpenApi20To30Converter;
use cebe\openapi\json\JsonPointer;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class OpenApiParser implements Parser
{
    /** @var Schema[]|Reference[]  */
    protected array $bodySchemas = [];

    protected ?BodySchemaNameGenerator $bodySchemaNameGenerator = null;

    public function __construct(
        protected OpenApi $openApi,
        protected Config $config,
    ) {
    }

    public static function build($content, Config $config): self
    {
        $contentPath = realpath($content);
        if ($contentPath === false) {
            throw new \InvalidArgumentException("File not found: {$content}");
        }

        $raw = file_get_contents($contentPath);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read file: {$content}");
        }

        $data = Str::endsWith(strtolower($contentPath), '.json')
            ? json_decode($raw, true)
            : Yaml::parse($raw);

        if (! is_array($data)) {
            throw new \RuntimeException('Invalid API specification: could not decode.');
        }

        if (OpenApi20To30Converter::isOpenApi20($data)) {
            $converter = new OpenApi20To30Converter();
            $data = $converter->convert($data);
        }

        $openApi = self::createOpenApiFromArray($data);

        return new self($openApi, $config);
    }

    /**
     * Create OpenApi spec object from array (no filesystem). Applies reference context and resolution in memory.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function createOpenApiFromArray(array $data): OpenApi
    {
        $openApi = new OpenApi($data);
        $context = new ReferenceContext($openApi, 'php://memory');
        $openApi->setReferenceContext($context);
        if ($openApi instanceof DocumentContextInterface) {
            $openApi->setDocumentContext($openApi, new JsonPointer(''));
        }

        $context->mode = ReferenceContext::RESOLVE_MODE_INLINE;
        $openApi->resolveReferences();

        return $openApi;
    }

    public function parse(): ApiSpecification
    {
        $this->bodySchemas = [];

        $existingNames =  array_keys($this->openApi->components?->schemas ?? []);
        $this->bodySchemaNameGenerator = new BodySchemaNameGenerator($existingNames);

        $endpoints = $this->parseItems($this->openApi->paths);

        return new ApiSpecification(
            name: $this->openApi->info->title,
            description: $this->openApi->info->description,
            baseUrl: $this->parseBaseUrl($this->openApi->servers),
            securityRequirements: [],
            endpoints: $endpoints,
            components: $this->parseComponents($this->openApi->components),
            moduleName: $this->parseModuleName(),
        );
    }

    protected function parseModuleName(): ?string
    {
        $title = $this->openApi->info->title;
        $tag = $this->openApi->tags[0] ?? null;

        $title = $tag->name ?? $title;

        if (empty($title)) {
            return null;
        }

        $pathSegments = preg_split('/\s+/', $title);

        $pathSegments = array_values(array_filter($pathSegments, fn ($s) => !preg_match('/^[:{]|^id|^code|^api|^sdk|^clould|^service|^micro|^module/i', (string) $s) ));
        $pathSegments = array_slice($pathSegments, 0, 3);

        if (empty($pathSegments)) {
            return null;
        }

        return NameHelper::safeClassName(implode(array_map('ucfirst', $pathSegments)), 'Sdk');
    }

    /**
     * @param  Server[]  $servers
     */
    protected function parseBaseUrl(?array $servers): BaseUrl
    {
        /** @var Server $server */
        $server = array_shift($servers);
        if (is_null($server->variables)) {
            return new BaseUrl('');
        }

        $parameters = [];
        foreach ($server->variables as $name => $variable) {
            $parameters[] = new ServerParameter($name, $variable->default, $variable->description);
        }

        return new BaseUrl($server->url, $parameters);
    }

    /**
     * @return array|Endpoint[]
     */
    protected function parseItems(?Paths $items): array
    {
        if (! $items) {
            return [];
        }

        $requests = [];

        foreach ($items as $path => $item) {

            if ($item instanceof PathItem) {
                foreach ($item->getOperations() as $method => $operation) {
                    // TODO: variables for the path
                    $requests[] = $this->parseEndpoint($operation, $this->mapParams($item->parameters, 'path'), $path, $method);
                }
            }
        }

        return $requests;
    }

    /**
     * @return \Crescat\SaloonSdkGenerator\Data\Generator\SecurityRequirement[]
     */
    protected function parseSecurityRequirements(array $security): array
    {
        $securityRequirements = [];

        foreach ($security as $key => $securityOption) {
            // Handle case where it's already an array (from SecurityRequirements->getSerializableData())
            if (is_array($securityOption)) {
                foreach ($securityOption as $name => $scopes) {
                    $securityRequirements[] = new \Crescat\SaloonSdkGenerator\Data\Generator\SecurityRequirement(
                        $name,
                        $scopes
                    );
                }

                continue;
            }

            // Handle case where it's a SecurityRequirement object
            $data = $securityOption->getSerializableData();
            if (gettype($data) !== 'object') {
                continue;
            }

            $securityProperties = get_object_vars($data);

            foreach ($securityProperties as $name => $scopes) {
                $securityRequirements[] = new \Crescat\SaloonSdkGenerator\Data\Generator\SecurityRequirement(
                    $name,
                    $scopes
                );
            }
        }

        return $securityRequirements;
    }

    protected function parseComponents(?Components $components): \Crescat\SaloonSdkGenerator\Data\Generator\Components
    {
        if (! $components) {
            return new \Crescat\SaloonSdkGenerator\Data\Generator\Components(
                schemas: $this->bodySchemas
            );
        }

        $securitySchemes = [];
        foreach ($components->securitySchemes as $securityScheme) {

            $securitySchemes[] = new SecurityScheme(
                type: SecuritySchemeType::tryFrom($securityScheme->type),
                name: $securityScheme->name,
                in: ApiKeyLocation::tryFrom($securityScheme->in ?? ''),
                scheme: $securityScheme->scheme,
                bearerFormat: $securityScheme->bearerFormat,
                description: $securityScheme->description,
                flows: $securityScheme->flows,
                openIdConnectUrl: $securityScheme->openIdConnectUrl
            );
        }

        $componentSchemas = is_array($components->schemas) ? $components->schemas : (array) $components->schemas;
        $schemas = array_merge($componentSchemas, $this->bodySchemas);

        return new \Crescat\SaloonSdkGenerator\Data\Generator\Components(
            schemas: $schemas,
            securitySchemes: $securitySchemes
        );
    }

    protected function parseEndpoint(Operation $operation, $pathParams, string $path, string $method): ?Endpoint
    {
        $pathSegments = Str::of($path)->replace('{', ':')->remove('}')->trim('/')->explode('/')->toArray();
        $name = trim($operation->operationId ?: $this->bodySchemaNameGenerator->generate($pathSegments, $method));

        return new Endpoint(
            name: $name,
            method: Method::parse($method),
            pathSegments: $pathSegments,
            collection: $operation->tags[0] ?? null, // In the real-world, people USUALLY only use one tag...
            response: null,
            responseParameter: $this->parseSuccessResponseSchemaName($operation, $name, $pathSegments, $method),
            description: $operation->description,
            queryParameters: $this->mapParams($operation->parameters ?? [], 'query'),
            // TODO: Check if this differs between spec versions
            pathParameters: $pathParams + $this->mapParams($operation->parameters ?? [], 'path'),
            bodyParameters: $this->parseRequestBody($operation->requestBody, $pathSegments, $method),
            headerParameters: $this->mapParams($operation->parameters ?? [], 'header'),
        );
    }

    protected function selectContentType(array $contentKeys): ?string
    {
        $format = $this->config->format ?? 'json';
        $format = strtolower(trim($format));


        foreach ($contentKeys as $key) {
            $keyLower = strtolower((string) $key);

            $part1 = explode(';', $keyLower)[0] ?? $keyLower;
            $part2 = explode('/', $keyLower, 2)[0] ?? $keyLower;

            if ($keyLower === $format || $part1 === $format || $part2 === $format) {
                return $key;
            }
        }

        // Default: first json-like
        foreach ($contentKeys as $key) {
            if (str_contains(strtolower((string) $key), 'json')) {
                return $key;
            }
        }

        return $contentKeys[0] ?? null;
    }

    /**
     * Extract schema name from a Reference (e.g. "#/components/schemas/billingPayment" => "billingPayment").
     */
    protected function getSchemaNameFromRef(Reference $ref): string
    {
        $refPath = $ref->getReference();

        return Str::afterLast($refPath, '/');
    }

    /**
     * @return Parameter[]
     */
    protected function parseRequestBody(RequestBody|Reference|null $requestBody, array $pathSegments, string $method): array
    {
        if (! $requestBody) {
            return [];
        }

        if ($requestBody instanceof Reference) {
            // todo: fix it
            return [];
        }

        if (! $requestBody instanceof RequestBody || empty($requestBody->content)) {
            return [];
        }

        $contentKeys = array_keys($requestBody->content);
        $selectedContentType = $this->selectContentType($contentKeys);
        if ($selectedContentType === null) {
            return [];
        }

        $mediaType = $requestBody->content[$selectedContentType];
        $schema = $mediaType->schema ?? null;
        if (! $schema) {
            return [];
        }

        $required = $requestBody->required ?? false;

        // Schema is a Reference (e.g. $ref or allOf with single $ref)
        $refSchemaName = $this->resolveRefSchemaName($schema);
        if ($refSchemaName !== null) {
            $dtoType = NameHelper::dtoClassName(NameHelper::safeClassName($refSchemaName));

            return [
                new Parameter(
                    type: $dtoType,
                    nullable: ! $required,
                    name: $refSchemaName,
                    description: $requestBody->description ?? '',
                    format: $selectedContentType,
                    isDto: true,
                ),
            ];
        }

        // Inline schema (type: object with properties) -> add to bodySchemas and one Parameter
        if ($schema instanceof Schema && ($schema->type === 'object' || isset($schema->properties)) && ! empty($schema->properties ?? [])) {
            $schemaName = $this->bodySchemaNameGenerator->generate($pathSegments, $method, 'Request');
            $this->bodySchemas[$schemaName] = $schema;

            $dtoType = NameHelper::dtoClassName(NameHelper::safeClassName($schemaName));

            return [
                new Parameter(
                    type: $dtoType,
                    nullable: ! $required,
                    name: $schemaName,
                    description: $requestBody->description ?? '',
                    format: $selectedContentType,
                    isDto: true,
                ),
            ];
        }


        return [];
    }

    /**
     * If schema is a Reference or allOf with single Reference, return the referenced schema name.
     */
    protected function resolveRefSchemaName(Schema|Reference|null $schema): ?string
    {
        if ($schema instanceof Reference) {
            return $this->getSchemaNameFromRef($schema);
        }

        if ($schema instanceof Schema && ! empty($schema->allOf)) {
            $first = $schema->allOf[0] ?? null;
            if ($first instanceof Reference) {
                return $this->getSchemaNameFromRef($first);
            }
        }

        return null;
    }

    protected function generateMethodName()
    {

    }

    /**
     * Parse success response (first 2xx) and return schema name for response content, or null if empty.
     */
    protected function parseSuccessResponseSchemaName(Operation $operation, string $methodName, array $pathSegments, string $method): ?Parameter
    {
        if (strtolower($methodName) === 'getappointment') {
            $t = 1;
        }

        $responses = $operation->responses ?? null;
        if (! $responses) {
            return null;
        }

        $getResponses = $responses->getResponses();
        $successCodes = ['200', '201', '202', '204', 'default'];
        $response = null;
        foreach ($successCodes as $code) {
            if (isset($getResponses[$code])) {
                $response = $getResponses[$code];
                break;
            }
        }

        if (!$response) {
            return null;
        }

        if ($response instanceof Reference) {
            try {
                $response = $response->resolve();
            } catch (Throwable) {
                return null;
            }
        }

        if (! $response instanceof Response || empty($response->content)) {
            return null;
        }

        $contentKeys = array_keys($response->content);
        $selectedType = $this->selectContentType($contentKeys);
        if ($selectedType === null) {
            return null;
        }

        $mediaType = $response->content[$selectedType];
        $schema = $mediaType->schema ?? null;
        if (! $schema) {
            return null;
        }

        $dtoType = $this->resolveRefSchemaName($schema);

        if (null === $dtoType && $schema instanceof Schema) {

            $methodName = trim($operation->operationId ?  $operation->operationId . 'Response' : $this->bodySchemaNameGenerator->generate($pathSegments, $method, 'Response'));

            $requestClassName = lcfirst(NameHelper::resourceClassName($methodName));
            $baseMethodName = NameHelper::safeVariableName($requestClassName);

            if (!isset($this->bodySchemas[$baseMethodName])) {
                $this->bodySchemas[$baseMethodName] = $schema;
                $dtoType = $baseMethodName;
            }
        }

        if (null === $dtoType) {
            return null;
        }

        return new Parameter(
            type: $dtoType,
            nullable: false,
            name: $dtoType,
            description: $response->description ?? '',
            isDto: true,
        );
    }

    /**
     * @param  array  $parameters  Array of OpenApiParameter or Reference objects
     * @return Parameter[] array
     */
    protected function mapParams(array $parameters, string $in): array
    {
        return collect($parameters)
            ->map(function ($parameter) {
                // Resolve Reference objects to their actual Parameter objects
                if ($parameter instanceof Reference) {
                    // When using RESOLVE_MODE_INLINE, we need to manually resolve the reference
                    $refPath = $parameter->getReference();

                    // Parse the reference path (e.g., "#/components/parameters/PathAlbumId")
                    if (str_starts_with($refPath, '#/components/parameters/')) {
                        $paramName = str_replace('#/components/parameters/', '', $refPath);

                        // Check if the parameter exists in components
                        if (isset($this->openApi->components->parameters[$paramName])) {
                            $resolvedParam = $this->openApi->components->parameters[$paramName];

                            // The resolved parameter might itself be a Reference in some cases
                            if ($resolvedParam instanceof Reference) {
                                return null;
                            }

                            return $resolvedParam;
                        }
                    }

                    return null;
                }

                return $parameter;
            })
            ->filter() // Remove any nulls from failed resolutions
            ->whereInstanceOf(OpenApiParameter::class)
            ->filter(fn (OpenApiParameter $parameter) => $parameter->in == $in)
            ->map(function (OpenApiParameter $parameter) {
                // Resolve schema if it's a reference
                $schema = $parameter->schema;
                if ($schema instanceof Reference) {
                    try {
                        $schema = $schema->resolve();
                    } catch (Throwable) {
                        $schema = null;
                    }
                }

                return new Parameter(
                    type: $this->mapSchemaTypeToPhpType($schema?->type ?? null),
                    nullable: $parameter->required == false,
                    name: $parameter->name,
                    description: $parameter->description,
                );
            })
            ->values() // Reset array keys
            ->all();
    }

    protected function mapSchemaTypeToPhpType($type): string
    {
        return match ($type) {
            Type::INTEGER => 'int',
            Type::NUMBER => 'float|int', // TODO: is "number" always a float in openapi specs?
            Type::STRING => 'string',
            Type::BOOLEAN => 'bool',
            Type::OBJECT, Type::ARRAY => 'array',
            default => 'mixed',
        };
    }
}
