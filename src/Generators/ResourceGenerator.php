<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Illuminate\Support\Str;
use Nette\InvalidStateException;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;

class ResourceGenerator extends Generator
{
    protected array $duplicateRequests = [];

    protected int $duplicateCounter = 0;

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        return $this->generateResourceClasses($specification);
    }

    /**
     * @return array|PhpFile[]
     */
    protected function generateResourceClasses(ApiSpecification $specification): array
    {
        $classes = [];

        $groupedByCollection = collect($specification->endpoints)->groupBy(function (Endpoint $endpoint) {
            return NameHelper::resourceClassName(
                $endpoint->collection ?: $this->config->fallbackResourceName
            );
        });

        foreach ($groupedByCollection as $collection => $items) {
            $classes[] = $this->generateResourceClass($collection, $items->toArray());
        }

        return $classes;
    }

    /**
     * DTO FQN for schema name. Uses collection subfolder if desired: Namespace\Dto\Cashiering\BillingPayment.
     * Set $useCollectionSubfolder to true to match AZDS\OhipApi\Dto\Cashiering\* (requires DTOs generated per collection).
     */
    protected function dtoFqn(?string $collection, string $schemaName, bool $useCollectionSubfolder = true): string
    {
        $dtoClass = NameHelper::dtoClassName(NameHelper::safeClassName($schemaName));
        $base = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}";
        if ($useCollectionSubfolder && $collection) {
            $resourceName = NameHelper::resourceClassName($collection);

            return "{$base}\\{$resourceName}\\{$dtoClass}";
        }

        return "{$base}\\{$dtoClass}";
    }

    protected function resourceClassBodyConstructor(): string
    {
        return <<<TXT
\$connector ??= new Client();

if (!\$connector instanceof ConnectorInterface) {
    \$connector = new HttpConnector(\$connector);
}

\$this->connector = \$connector;
TXT;

    }

    /**
     * @param  array|Endpoint[]  $endpoints
     */
    public function generateResourceClass(string $resourceName, array $endpoints): ?PhpFile
    {
        $apiResourceName = str_ends_with($resourceName, 'Api') ? $resourceName : $resourceName . 'Api';

        $classType = new ClassType($apiResourceName);

        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->resourceNamespaceSuffix}");

        $namespace
            ->addUse('AZDS\DataTransfer\Http\ConnectorInterface')
            ->addUse('AZDS\DataTransfer\Http\HttpConnector')
            ->addUse('GuzzleHttp\Client')
            ->addUse('GuzzleHttp\ClientInterface')
            ->addUse('GuzzleHttp\Promise\PromiseInterface');

        $connectorInterfaceFqn = 'AZDS\DataTransfer\Http\ConnectorInterface';
        $clientInterfaceFqn = 'GuzzleHttp\ClientInterface';

        $classType->addProperty('connector')
            ->setType($connectorInterfaceFqn)
            ->setProtected();

        $constructor = $classType->addMethod('__construct')
            ->setPublic();
        $constructor->addParameter('connector')
            ->setType(sprintf('%s|%s|null',$connectorInterfaceFqn, $clientInterfaceFqn))
            ->setNullable()
            ->setDefaultValue(null);
        $constructor->setBody($this->resourceClassBodyConstructor());

        $this->duplicateCounter = 0;

        foreach ($endpoints as $endpoint) {
            $this->generateEndpoint($endpoint, $namespace, $classType, $resourceName);
        }

        $namespace->add($classType);

        return $classFile;
    }

    protected function buildUri(Endpoint $endpoint): string
    {
        $parts = [];

        foreach ($endpoint->pathSegments as $segment) {
            if (Str::startsWith($segment, ':')) {
                $paramName = NameHelper::safeVariableName(Str::after($segment, ':'));
                $parts[] = '{$' . $paramName . '}';
            } else {
                $parts[] = $segment;
            }
        }

        return '"/' . implode('/', $parts) . '"';
    }

    /**
     * @param  Parameter[]  $parameters
     */
    protected function buildQueryArray(array $parameters): string
    {
        if (empty($parameters)) {
            return '[]';
        }
        $pairs = [];
        foreach ($parameters as $p) {
            $var = NameHelper::safeVariableName($p->name);
            $key = var_export($p->name, true);
            $pairs[] = "{$key} => \${$var}";
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    protected function generateEndpoint(Endpoint $endpoint, PhpNamespace $namespace, ClassType $classType, string $resourceName): void
    {
        $pathBasedName = NameHelper::pathBasedName($endpoint);
        $requestClassName = NameHelper::resourceClassName($endpoint->name ?: $pathBasedName);
        $baseMethodName = NameHelper::safeVariableName($requestClassName);

        $bodyParameters = collect($endpoint->bodyParameters)
            ->reject(fn (Parameter $p) => in_array($p->name, $this->config->ignoredBodyParams))
            ->values()
            ->toArray();

        $queryParameters = collect($endpoint->queryParameters)
            ->reject(fn (Parameter $p) => in_array($p->name, $this->config->ignoredQueryParams))
            ->values()
            ->toArray();
        $headerParameters = collect($endpoint->headerParameters)
            ->reject(fn (Parameter $p) => in_array($p->name, $this->config->ignoredHeaderParams))
            ->values()
            ->toArray();

        $allParams = array_merge(
            array_values($endpoint->pathParameters),
            $queryParameters,
            $bodyParameters,
            $headerParameters,
        );
        uasort($allParams, fn (Parameter $p1, Parameter $p2) => $p1->nullable <=> $p2->nullable);

        $asyncMethodName = $baseMethodName . 'Async';

        $syncMethodName = $baseMethodName;

        $syncMethod = $classType->addMethod($syncMethodName);
        $asyncMethod = $classType->addMethod($asyncMethodName);

        $asyncMethod->setReturnType('GuzzleHttp\Promise\PromiseInterface');

        foreach ($allParams as $parameter) {
            $this->addParameterToMethod($asyncMethod, $parameter, $namespace, false);
        }

        $uri = $this->buildUri($endpoint);
        $queryStr = $this->buildQueryArray($queryParameters);
        $headersStr = $this->buildQueryArray($headerParameters);

        $bodyVar = null;
        if (count($bodyParameters) >= 1) {
            $bodyVar = '$' . NameHelper::safeVariableName($bodyParameters[0]->name);
        }

        $requestArg = $bodyVar ?? 'null';

        $responseFormatArg = 'null';

        if ($endpoint->responseSchemaName !== null) {
            $responseFqn = $this->dtoFqn($endpoint->collection ?? $this->config->fallbackResourceName, $endpoint->responseSchemaName);
            $responseShort = Str::afterLast($responseFqn, '\\');
            $namespace->addUse($responseFqn);
            $responseFormatArg = "{$responseShort}::class";
        }

        $httpMethod = $endpoint->method->value;

        $body = <<<TXT
return \$this->connector->send(
    method: '$httpMethod',
    uri: $uri,
    query: $queryStr,
    headers: $headersStr,
    request: $requestArg,
    requestFormat: null,
    responseFormat: $responseFormatArg,
);
TXT;

        $asyncMethod->setBody(new Literal($body));


        if ($endpoint->responseSchemaName) {
            $responseFqn = $this->dtoFqn($endpoint->collection ?? $this->config->fallbackResourceName, $endpoint->responseSchemaName);
            $syncMethod->setReturnType($responseFqn);
            $namespace->addUse($responseFqn);
        } else {
            $syncMethod->setReturnType('mixed');
        }

        foreach ($allParams as $parameter) {
            $this->addParameterToMethod($syncMethod, $parameter, $namespace);
        }

        $syncMethod->setBody(new Literal(<<<TXT
return \$this->$asyncMethodName(...func_get_args())->wait();
TXT
));
    }

    protected function addParameterToMethod(Method $method, Parameter $parameter, PhpNamespace $namespace, bool $printDocsBlock = true): Method
    {
        $name = NameHelper::safeVariableName($parameter->name);
        $docType = $type = $parameter->classFQN ?? $parameter->type;

        if (null !== $parameter->classFQN) {
            $classFQN = explode("\\", $parameter->classFQN);
            $docType = end($classFQN);
        }

        $param = $method
            ->addParameter($name)
            ->setType($type)
            ->setNullable($parameter->nullable);

        if ($printDocsBlock) {
            $method
                ->addComment(trim(sprintf('@param %s $%s %s', $docType, $name, $parameter->description)));
        }

        if (null !== $parameter->classFQN) {
            $namespace
                ->addUse($parameter->classFQN);
        }

        if ($parameter->nullable) {
            $param->setDefaultValue(null);
        }

        return $method;
    }

    protected function recordDuplicatedRequestName(string $requestClassName, string $deduplicatedMethodName): void
    {
        $this->duplicateRequests[$requestClassName][] = $deduplicatedMethodName;
    }
}
