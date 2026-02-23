<?php

namespace Crescat\SaloonSdkGenerator\Services;

/**
 * Converts OpenAPI (Swagger) 2.0 specification to OpenAPI 3.0.x in memory.
 * Operates on array structures only; no filesystem usage.
 *
 * @see https://swagger.io/specification/v2/
 * @see https://spec.openapis.org/oas/v3.0.3
 */
class OpenApi20To30Converter
{
    /**
     * Check if the given spec array is OpenAPI/Swagger 2.0.
     */
    public static function isOpenApi20(array $spec): bool
    {
        return isset($spec['swagger']) && str_starts_with((string) $spec['swagger'], '2.0');
    }
    private const DEFAULT_PRODUCES = ['application/json'];

    private const DEFAULT_CONSUMES = ['application/json'];

    /**
     * Convert a Swagger 2.0 spec array to OpenAPI 3.0.x spec array.
     *
     * @param  array<string, mixed>  $spec  Decoded Swagger 2.0 JSON/YAML
     * @return array<string, mixed> OpenAPI 3.0.x structure
     */
    public function convert(array $spec): array
    {
        $openapi = [
            'openapi' => '3.0.3',
            'info' => $this->convertInfo($spec['info'] ?? []),
            'servers' => $this->convertServers($spec),
            'paths' => $this->convertPaths($spec),
            'tags' => $spec['tags'] ?? [],
            'externalDocs' => $spec['externalDocs'] ?? null,
        ];

        $components = [];

        if (! empty($spec['definitions'])) {
            $components['schemas'] = $this->rewriteRefsInStructure($spec['definitions'], '#/definitions/', '#/components/schemas/');
        }

        if (! empty($spec['parameters'])) {
            $components['parameters'] = $this->rewriteRefsInStructure($spec['parameters'], '#/definitions/', '#/components/schemas/');
        }

        if (! empty($spec['responses'])) {
            $components['responses'] = $this->convertResponsesDefinitions($spec['responses'], $spec['produces'] ?? self::DEFAULT_PRODUCES);
        }

        if (! empty($spec['securityDefinitions'])) {
            $components['securitySchemes'] = $this->convertSecurityDefinitions($spec['securityDefinitions']);
        }

        if ($components !== []) {
            $openapi['components'] = $components;
        }

        if (isset($spec['security'])) {
            $openapi['security'] = $spec['security'];
        }

        $openapi = $this->copyExtensions($spec, $openapi);

        return $openapi;
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function convertInfo(array $info): array
    {
        $result = [
            'title' => $info['title'] ?? 'API',
            'version' => $info['version'] ?? '1.0.0',
        ];
        if (! empty($info['description'])) {
            $result['description'] = $info['description'];
        }
        if (! empty($info['termsOfService'])) {
            $result['termsOfService'] = $info['termsOfService'];
        }
        if (! empty($info['contact'])) {
            $result['contact'] = $info['contact'];
        }
        if (! empty($info['license'])) {
            $result['license'] = $info['license'];
        }

        return $this->copyExtensions($info, $result);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, array<string, mixed>>
     */
    private function convertServers(array $spec): array
    {
        $host = $spec['host'] ?? '';
        $basePath = $spec['basePath'] ?? '/';
        $schemes = $spec['schemes'] ?? ['https'];

        if ($host === '') {
            return [['url' => $basePath]];
        }

        $servers = [];
        foreach ($schemes as $scheme) {
            $url = $scheme . '://' . rtrim($host, '/') . '/' . ltrim($basePath, '/');
            $url = rtrim($url, '/') ?: '/';
            $servers[] = ['url' => $url];
        }

        return $servers ?: [['url' => '/']];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function convertPaths(array $spec): array
    {
        $paths = $spec['paths'] ?? [];
        $globalConsumes = $spec['consumes'] ?? self::DEFAULT_CONSUMES;
        $globalProduces = $spec['produces'] ?? self::DEFAULT_PRODUCES;
        $globalParameters = $spec['parameters'] ?? [];

        $result = [];
        foreach ($paths as $pathKey => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }
            $result[$pathKey] = $this->convertPathItem($pathItem, $globalConsumes, $globalProduces, $globalParameters);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $pathItem
     * @param  array<int, string>  $globalConsumes
     * @param  array<int, string>  $globalProduces
     * @param  array<string, mixed>  $globalParameters
     * @return array<string, mixed>
     */
    private function convertPathItem(array $pathItem, array $globalConsumes, array $globalProduces, array $globalParameters): array
    {
        $result = [];

        if (isset($pathItem['$ref'])) {
            $result['$ref'] = $this->rewriteRef($pathItem['$ref']);
            $result = $this->copyExtensions($pathItem, $result);

            return $result;
        }

        $pathLevelParams = $pathItem['parameters'] ?? [];
        $operations = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch'];

        foreach ($operations as $op) {
            if (! isset($pathItem[$op]) || ! is_array($pathItem[$op])) {
                continue;
            }
            $opConsumes = $pathItem[$op]['consumes'] ?? $globalConsumes;
            $opProduces = $pathItem[$op]['produces'] ?? $globalProduces;
            $result[$op] = $this->convertOperation(
                $pathItem[$op],
                $pathLevelParams,
                $globalParameters,
                $opConsumes,
                $opProduces
            );
        }

        if (isset($pathItem['parameters'])) {
            $result['parameters'] = $this->convertParametersList($pathItem['parameters'], $globalParameters);
        }

        return $this->copyExtensions($pathItem, $result);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<int, mixed>  $pathParameters
     * @param  array<string, mixed>  $globalParameters
     * @param  array<int, string>  $consumes
     * @param  array<int, string>  $produces
     * @return array<string, mixed>
     */
    private function convertOperation(array $operation, array $pathParameters, array $globalParameters, array $consumes, array $produces): array
    {
        $opParams = $operation['parameters'] ?? [];
        $allParams = array_merge($pathParameters, $opParams);

        $requestBody = $this->extractRequestBody($allParams, $globalParameters, $consumes);
        $nonBodyParams = $this->filterNonBodyParameters($allParams, $globalParameters);

        $result = [
            'tags' => $operation['tags'] ?? [],
            'summary' => $operation['summary'] ?? null,
            'description' => $operation['description'] ?? null,
            'operationId' => $operation['operationId'] ?? null,
            'parameters' => $this->convertParametersList($nonBodyParams, $globalParameters),
            'responses' => $this->convertResponses($operation['responses'] ?? [], $produces),
            'deprecated' => $operation['deprecated'] ?? false,
        ];

        if ($requestBody !== null) {
            $result['requestBody'] = $requestBody;
        }

        if (isset($operation['externalDocs'])) {
            $result['externalDocs'] = $operation['externalDocs'];
        }
        if (isset($operation['security'])) {
            $result['security'] = $operation['security'];
        }

        return $this->copyExtensions($operation, $result);
    }

    /**
     * @param  array<int, mixed>  $params
     * @param  array<string, mixed>  $globalParameters
     * @return array<string, mixed>|null
     */
    private function extractRequestBody(array $params, array $globalParameters, array $consumes): ?array
    {
        $bodyParam = null;
        $formParams = [];

        foreach ($params as $p) {
            $param = is_array($p) ? $p : $this->resolveParamRef($p, $globalParameters);
            if (! is_array($param)) {
                continue;
            }
            $in = $param['in'] ?? null;
            if ($in === 'body') {
                $bodyParam = $param;
            }
            if ($in === 'formData') {
                $formParams[] = $param;
            }
        }

        if ($bodyParam !== null) {
            $content = [];
            $schema = $this->rewriteRefsInStructure($bodyParam['schema'] ?? [], '#/definitions/', '#/components/schemas/');
            $mediaType = $consumes[0] ?? 'application/json';
            $content[$mediaType] = ['schema' => $schema];

            return [
                'description' => $bodyParam['description'] ?? '',
                'content' => $content,
                'required' => ($bodyParam['required'] ?? false),
            ];
        }

        if ($formParams !== []) {
            $schema = ['type' => 'object', 'properties' => [], 'required' => []];
            foreach ($formParams as $fp) {
                $name = $fp['name'] ?? '';
                $schema['properties'][$name] = $this->paramToSchema($fp);
                if (! empty($fp['required'])) {
                    $schema['required'][] = $name;
                }
            }
            $content = [];
            $mediaType = strpos(implode(' ', $consumes), 'multipart') !== false ? 'multipart/form-data' : 'application/x-www-form-urlencoded';
            $content[$mediaType] = ['schema' => $schema];

            return [
                'description' => '',
                'content' => $content,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $param
     * @return array<string, mixed>
     */
    private function paramToSchema(array $param): array
    {
        $schema = ['type' => $param['type'] ?? 'string'];
        if (isset($param['format'])) {
            $schema['format'] = $param['format'];
        }
        if (isset($param['description'])) {
            $schema['description'] = $param['description'];
        }
        if (isset($param['default'])) {
            $schema['default'] = $param['default'];
        }
        if (($param['type'] ?? '') === 'array' && isset($param['items'])) {
            $schema['items'] = $param['items'];
        }
        if (isset($param['enum'])) {
            $schema['enum'] = $param['enum'];
        }

        return $schema;
    }

    /**
     * @param  array<int, mixed>  $params
     * @param  array<string, mixed>  $globalParameters
     * @return array<int, mixed>
     */
    private function filterNonBodyParameters(array $params, array $globalParameters): array
    {
        $out = [];
        foreach ($params as $p) {
            $param = is_array($p) ? $p : $this->resolveParamRef($p, $globalParameters);
            if (! is_array($param)) {
                continue;
            }
            $in = $param['in'] ?? null;
            if ($in !== 'body' && $in !== 'formData') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $ref  string $ref or array with $ref
     * @param  array<string, mixed>  $global
     * @return array<string, mixed>|null
     */
    private function resolveParamRef($ref, array $global): ?array
    {
        $refStr = is_string($ref) ? $ref : ($ref['$ref'] ?? null);
        if ($refStr === null) {
            return null;
        }
        if (preg_match('#^#/parameters/(.+)$#', $refStr, $m)) {
            $key = $m[1];

            return $global[$key] ?? null;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $params
     * @param  array<string, mixed>  $globalParameters
     * @return array<int, mixed>
     */
    private function convertParametersList(array $params, array $globalParameters): array
    {
        $result = [];
        foreach ($params as $p) {
            if (is_array($p) && isset($p['$ref']) && count($p) === 1) {
                $result[] = ['$ref' => $this->rewriteRef($p['$ref'])];

                continue;
            }
            $param = is_array($p) ? $p : $this->resolveParamRef($p, $globalParameters);
            if (! is_array($param) || ! isset($param['in'])) {
                $result[] = $this->rewriteRefInParam($p);

                continue;
            }
            $result[] = $this->convertParameter($param);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $param
     * @return array<string, mixed>
     */
    private function convertParameter(array $param): array
    {
        $result = [
            'name' => $param['name'] ?? '',
            'in' => $param['in'] === 'formData' ? 'query' : ($param['in'] ?? 'query'),
            'description' => $param['description'] ?? null,
            'required' => $param['required'] ?? false,
        ];

        if (($param['in'] ?? '') !== 'body') {
            $result['schema'] = $this->paramToSchema($param);
        }
        if (isset($param['allowEmptyValue'])) {
            $result['allowEmptyValue'] = $param['allowEmptyValue'];
        }

        return $this->copyExtensions($param, $result);
    }

    /**
     * @param  mixed  $param  Ref or array
     * @return mixed
     */
    private function rewriteRefInParam($param)
    {
        if (is_array($param) && isset($param['$ref'])) {
            $param['$ref'] = $this->rewriteRef($param['$ref']);
        }

        return $param;
    }

    /**
     * @param  array<string, mixed>  $responses
     * @param  array<int, string>  $produces
     * @return array<string, mixed>
     */
    private function convertResponses(array $responses, array $produces): array
    {
        $result = [];
        foreach ($responses as $code => $response) {
            if (! is_array($response)) {
                continue;
            }
            if (isset($response['$ref']) && count($response) === 1) {
                $result[(string) $code] = ['$ref' => $this->rewriteRef($response['$ref'])];
                continue;
            }
            $result[(string) $code] = $this->convertResponse($response, $produces);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $produces
     * @return array<string, mixed>
     */
    private function convertResponse(array $response, array $produces): array
    {
        $result = [
            'description' => $response['description'] ?? '',
        ];

        if (isset($response['headers'])) {
            $result['headers'] = $this->rewriteRefsInStructure($response['headers'], '#/definitions/', '#/components/schemas/');
        }

        if (isset($response['schema'])) {
            $mediaType = $produces[0] ?? 'application/json';
            $schema = $this->rewriteRefsInStructure($response['schema'], '#/definitions/', '#/components/schemas/');
            $result['content'] = [
                $mediaType => ['schema' => $schema],
            ];
        }

        return $this->copyExtensions($response, $result);
    }

    /**
     * @param  array<string, mixed>  $defs
     * @param  array<int, string>  $produces
     * @return array<string, mixed>
     */
    private function convertResponsesDefinitions(array $defs, array $produces): array
    {
        $result = [];
        foreach ($defs as $name => $response) {
            if (! is_array($response)) {
                continue;
            }
            $result[$name] = $this->convertResponse($response, $produces);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $defs
     * @return array<string, mixed>
     */
    private function convertSecurityDefinitions(array $defs): array
    {
        $result = [];
        foreach ($defs as $name => $scheme) {
            if (! is_array($scheme)) {
                continue;
            }
            $type = $scheme['type'] ?? 'basic';
            if ($type === 'basic') {
                $result[$name] = ['type' => 'http', 'scheme' => 'basic', 'description' => $scheme['description'] ?? null];
            } elseif ($type === 'apiKey') {
                $result[$name] = [
                    'type' => 'apiKey',
                    'in' => $scheme['in'] ?? 'header',
                    'name' => $scheme['name'] ?? 'Authorization',
                    'description' => $scheme['description'] ?? null,
                ];
            } elseif ($type === 'oauth2') {
                $flow = $scheme['flow'] ?? 'implicit';
                $scopes = $scheme['scopes'] ?? [];
                $result[$name] = [
                    'type' => 'oauth2',
                    'flows' => $this->convertOAuth2Flow($flow, $scheme, $scopes),
                    'description' => $scheme['description'] ?? null,
                ];
            } else {
                $result[$name] = $this->copyExtensions($scheme, $scheme);
            }
        }

        return $result;
    }

    /**
     * @param  string  $flow
     * @param  array<string, mixed>  $scheme
     * @param  array<string, string>  $scopes
     * @return array<string, mixed>
     */
    private function convertOAuth2Flow(string $flow, array $scheme, array $scopes): array
    {
        $flowKey = $flow === 'accessCode' ? 'authorizationCode' : $flow;
        $flowConfig = [];
        if (in_array($flow, ['implicit', 'accessCode'], true)) {
            $flowConfig['authorizationUrl'] = $scheme['authorizationUrl'] ?? '';
        }
        if (in_array($flow, ['password', 'application', 'accessCode'], true)) {
            $flowConfig['tokenUrl'] = $scheme['tokenUrl'] ?? '';
        }
        $flowConfig['scopes'] = $scopes;

        return [$flowKey => $flowConfig];
    }

    private function rewriteRef(string $ref): string
    {
        $ref = str_replace('#/definitions/', '#/components/schemas/', $ref);
        $ref = str_replace('#/parameters/', '#/components/parameters/', $ref);
        $ref = str_replace('#/responses/', '#/components/responses/', $ref);

        return $ref;
    }

    /**
     * Recursively rewrite $ref values in a structure.
     *
     * @param  mixed  $data
     * @return mixed
     */
    private function rewriteRefsInStructure($data, string $fromPrefix, string $toPrefix)
    {
        if (is_array($data)) {
            if (isset($data['$ref']) && is_string($data['$ref'])) {
                $data['$ref'] = str_replace($fromPrefix, $toPrefix, $data['$ref']);
            }
            foreach ($data as $k => $v) {
                $data[$k] = $this->rewriteRefsInStructure($v, $fromPrefix, $toPrefix);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function copyExtensions(array $source, array $target): array
    {
        foreach ($source as $key => $value) {
            if (str_starts_with((string) $key, 'x-')) {
                $target[$key] = $value;
            }
        }

        return $target;
    }
}
