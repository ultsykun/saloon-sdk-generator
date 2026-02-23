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

    public function generate(array $pathSegments, string $method, string $suffix = ''): string
    {
        $pathSegments = array_values(array_filter($pathSegments, fn ($s) => !preg_match('/^[:{]|^id|^code|^v[0-9]+/i', (string) $s) ));

        $baseName = $this->baseNameFromPath($pathSegments);
        $methodSuffix = ($suffix === 'Request' || $suffix === 'Response') ? $this->methodSuffix($method) : Str::studly($method);

        $candidate = $this->getCandidateName($baseName, $methodSuffix, $suffix);

        if (! $this->isUsed($candidate)) {
            $this->usedNames[strtolower($candidate)] = true;

            return $candidate;
        }

        $pathPrefix = $this->pathPrefix($pathSegments);
        $candidate = $this->getCandidateName($pathPrefix . ucfirst($baseName), $methodSuffix, $suffix);
        $counter = 0;

        while ($this->isUsed($candidate)) {
            $candidate = $this->getCandidateName($pathPrefix . ucfirst($baseName), $methodSuffix, $suffix . (++$counter > 1 ? (string) $counter : ''));
        }

        $this->usedNames[strtolower($candidate)] = true;

        return $candidate;
    }

    protected function getCandidateName($baseName, $methodSuffix, $suffix): string
    {
        if ($suffix === '') {
            return $methodSuffix . ucfirst($baseName);
        }

        return $baseName . $methodSuffix . $suffix;
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
