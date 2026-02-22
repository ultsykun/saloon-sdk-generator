<?php

namespace Crescat\SaloonSdkGenerator\Helpers;

use Illuminate\Support\Str;

/**
 * Generates unique schema names for inline request body schemas.
 * - End of path in singular form + "Request": appointments => appointmentRequest
 * - Non-POST methods get prefix: PUT => appointmentPutRequest
 * - On collision with existing names, use path segments: widgetAppointmentRequest
 */
class BodySchemaNameGenerator
{
    /** @var array<string, true> */
    protected array $usedNames = [];

    /**
     * @param  array<string>  $existingSchemaNames  Component schema names and already-used body schema names
     */
    public function __construct(array $existingSchemaNames = [])
    {
        foreach ($existingSchemaNames as $name) {
            $this->usedNames[strtolower($name)] = true;
        }
    }

    /**
     * Generate a unique name for a request body schema.
     *
     * @param  array<string>  $pathSegments  URL path segments (e.g. ['clients', '{code}', 'widgets', '{widgetCode}', 'appointments'])
     * @param  string  $method  HTTP method (get, post, put, patch, delete)
     * @return string Unique schema name (e.g. appointmentRequest, appointmentPutRequest, widgetAppointmentRequest)
     */
    public function generate(array $pathSegments, string $method): string
    {
        $pathSegments = array_values(array_filter($pathSegments, fn ($s) => !preg_match('/^[:{]|^id|^code/i', (string) $s) ));

        $baseName = $this->baseNameFromPath($pathSegments); // e.g. "appointment"
        $methodSuffix = $this->methodSuffix($method);       // e.g. "" for POST, "Put" for PUT
        $candidate = $baseName . $methodSuffix . 'Request'; // appointmentRequest, appointmentPutRequest

        if (! $this->isUsed($candidate)) {
            $this->usedNames[strtolower($candidate)] = true;

            return $candidate;
        }

        // Collision: prefix with path context (e.g. widgetAppointmentRequest)
        $pathPrefix = $this->pathPrefix($pathSegments);
        $candidate = $pathPrefix . ucfirst($baseName) . $methodSuffix . 'Request';
        $counter = 0;
        while ($this->isUsed($candidate)) {
            $candidate = $pathPrefix . ucfirst($baseName) . $methodSuffix . 'Request' . (++$counter > 1 ? (string) $counter : '');
        }

        $this->usedNames[strtolower($candidate)] = true;

        return $candidate;
    }

    protected function isUsed(string $name): bool
    {
        return isset($this->usedNames[strtolower($name)]);
    }

    /**
     * Last path segment in singular form, camelCase (e.g. appointments => appointment).
     */
    protected function baseNameFromPath(array $segments): string
    {
        $last = (string) (end($segments) ?: 'body');
        $singular = Str::singular($last);

        return Str::camel($singular);
    }

    /**
     * Suffix for non-POST methods: Put => Put, Patch => Patch, etc. Empty for POST.
     */
    protected function methodSuffix(string $method): string
    {
        $m = strtoupper($method);
        if ($m === 'POST' || $m === 'GET') {
            return '';
        }

        return Str::studly($method);
    }

    /**
     * Prefix from path when base name is taken (e.g. widget from .../widgets/.../appointments).
     */
    protected function pathPrefix(array $segments): string
    {
        if (count($segments) < 2) {
            return '';
        }
        // Use second-to-last segment (e.g. "widgets" -> "widget") for context
        $prev = (string) $segments[count($segments) - 2];

        return Str::studly(Str::singular($prev));
    }
}
