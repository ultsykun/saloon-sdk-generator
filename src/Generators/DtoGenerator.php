<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use AZDS\DataTransfer\Attribute\Data;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassLike;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\EnumType;
use Nette\PhpGenerator\PhpFile;

class DtoGenerator extends Generator
{
    protected array $generated = [];
    protected array $generatedByHash = [];

    /** @var Schema[]|Reference[] */
    protected array $schemas = [];

    protected string $namespacePrefix = '';

    protected ApiSpecification $specification;

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $this->schemas = $specification->components?->schemas ?? [];
        $this->specification = $specification;

        $this->namespacePrefix = $this->config->moduleName ?? (string) $specification->moduleName;

        if ($specification->components) {
            foreach ($specification->components->schemas as $className => $schema) {
                if ($schema instanceof Reference) {
                    $schema = $schema->resolveReferences();
                }

                if (!$schema instanceof Schema) {
                    continue;
                }

                $classLike = $this->generateDtoClass(NameHelper::safeClassName($className), $schema);

                $specification->dtoClassesMap[strtolower($className)] = $classLike?->getFullName();
            }
        }

        $this->resolveAllParameters();

        $this->schemas = [];

        return $this->generated;
    }

    protected function resolveAllParameters()
    {
        foreach ($this->specification->endpoints as $endpoint) {
            foreach ($endpoint->allParameters() as $param) {
                if (!$param->isDto) {
                    continue;
                }

                $param->classFQN = $this->specification->dtoClassesMap[strtolower($param->type)] ?? null;
            }
        }
    }

    protected function generateDtoClass($className, Schema $schema): ?ClassLike
    {
        $dtoName = NameHelper::dtoClassName($className ?: $this->config->fallbackResourceName);

        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$this->namespacePrefix}");

        //Fixed for enum
        if (count($schema->enum ?? []) > 0) {
            $enumClass = $this->generateDtoEnum($dtoName, $schema);

            $namespace->add($enumClass);

            $this->generated[$dtoName] = $classFile;

            return $enumClass;
        }

        /** @var Schema[] $properties */
        $properties = $schema->properties ?? [];
        $schemaType = $this->convertOpenApiTypeToPhp($schema);

        if ($schemaType === 'array' && count($properties) === 0) {
            return null;
        }

        $classType = new ClassType($dtoName);

        $classType
            ->setComment($schema->title ?? '')
            ->addComment('')
            ->addComment(Utils::wrapLongLines($schema->description ?? ''));

        $classConstructor = $classType->addMethod('__construct');

        $generatedMappings = false;
        $referencedDtos = [];

        foreach ($properties as $propertyName => $propertySpec) {
            $attributeOptions = [];

            $openApiType = $this->convertOpenApiTypeToPhp($propertySpec);
            $type = $this->config->overwritePropertiesType[$propertyName] ?? $openApiType;

            $name = NameHelper::safeVariableName($propertyName);

            if ($name !== $propertyName) {
                $attributeOptions['field'] = $propertyName;
            }
            if ($type !== $openApiType) {
                $attributeOptions['type'] = $openApiType;
            }

            $propDocCommentType = null;
            // Check if this is a reference to another schema
            if ($propertySpec instanceof Reference) {
                $refSpecType = isset($this->schemas[$type]) ? $this->convertOpenApiTypeToPhp($this->schemas[$type]) : null;

                if ($refSpecType === 'array') {
                    $entryRef = $this->schemas[$type]->items;
                    $entryRefType = null !== $entryRef ? $this->convertOpenApiTypeToPhp($entryRef) : null;

                    $type = 'array';
                    if ($entryRef instanceof Reference && $entryRefType !== null && isset($this->schemas[$entryRefType])) {
                        $entryRefTypeSub = NameHelper::dtoClassName($entryRefType);

                        $entryType = $this->getClassFQN($entryRefTypeSub);
                        $attributeOptions['entryType'] = $entryType;

                        $propDocCommentType = $entryRefTypeSub . '[]|null';
                    }

                } else {
                    $schemaName = $type;
                    $dtoClassName = NameHelper::dtoClassName($schemaName);
                    // Use the FQN for the type
                    $type = $this->getClassFQN($dtoClassName);
                    // Track referenced DTOs
                    $referencedDtos[] = $dtoClassName;
                }
            }

            if ($type === 'object' || $type == 'array') {
                $hash = !empty($propertySpec->properties) ? $this->calculateHash($propertySpec->properties) : null;

                if ($hash !== null && !isset($this->generatedByHash[$hash])) {
                    $sub = $this->namespacePrefix . ucfirst($propertyName);
                    $sub1 = NameHelper::dtoClassName($className . ucfirst($propertyName));

                    $sub = isset($this->schemas[$sub]) || isset($this->generated[$sub]) ? $sub1 : $sub;

                    $classLike = $this->generateDtoClass($sub, $propertySpec);
                    $this->specification->dtoClassesMap[$sub] = $classLike?->getName();

                    $this->generatedByHash[$hash] = $this->getClassFQN($sub);
                }

                $type = $this->generatedByHash[$hash] ?? $type;
            }

            $property = $classConstructor->addPromotedParameter($name)
                ->setDefaultValue(null);

            $generateGetters = $this->config->generateGetters;
            if ($this->config->publicPropVisibility) {
                $property->setPublic();
            } else {
                $property->setPrivate();
                $generateGetters = true;
            }

            if ($generateGetters) {
                $this->generateGetMethod($classType, $name, $type, $propDocCommentType);
            }

            // Set the property type
            $property->setType($type);

            if ($attributeOptions) {
                $property->addAttribute(Data::class, $attributeOptions);
                $namespace->addUse(Data::class);
            }

            if (null !== $propDocCommentType) {
                $property->addComment("@var $propDocCommentType");
            }
        }

        $namespace->add($classType);

        $this->generated[$dtoName] = $classFile;

        return $classType;
    }

    protected function generateDtoEnum(string $dtoName, Schema $schema): EnumType
    {
        $enumType = new EnumType($dtoName);
        $enumType->setComment($schema->title ?? '')
            ->addComment('')
            ->addComment(Utils::wrapLongLines($schema->description ?? ''));

        $backingType = ($schema->type === 'integer') ? 'int' : 'string';
        $enumType->setType($backingType);

        $usedCaseNames = [];
        foreach ($schema->enum as $value) {
            $baseName = $this->enumValueToCaseName($value);
            $caseName = $baseName;
            $suffix = 0;

            while (isset($usedCaseNames[$caseName])) {
                $caseName = $baseName . '_' . (++$suffix);
            }
            $usedCaseNames[$caseName] = true;
            $backingValue = is_int($value) ? $value : (string) $value;
            $enumType->addCase($caseName, $backingValue);
        }

        return $enumType;
    }

    protected function enumValueToCaseName(mixed $value): string
    {
        $s = trim((string) $value);
        $s = preg_replace('/[^a-zA-Z0-9\s]/u', '', $s);
        $s = Str::upper(Str::snake($s));

        if ($s === '' || (strlen($s) > 0 && $s[0] >= '0' && $s[0] <= '9')) {
            $s = 'VALUE_' . $s;
        }

        return $s ?: 'VALUE';
    }

    protected function getClassFQN(string $class): string
    {
        return "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\$this->namespacePrefix\\{$class}";
    }

    protected function generateGetMethod(ClassType $class, string $name, string $type, ?string $propDocCommentType): void
    {
        $method = $class->addMethod('get' . ucfirst($name));

        $method
            ->setReturnNullable()
            ->setPublic()
            ->setBody('return $this->' . $name . ';')
            ->setReturnType($type);

        if (null !== $propDocCommentType) {
            $method->addComment("@return $propDocCommentType");
        }
    }

    /**
     * @param array $properties
     */
    protected function calculateHash(array $properties): string
    {
        $result = [];
        foreach ($properties as $name => $property) {
            $result[$name] = $this->convertOpenApiTypeToPhp($property);
        }

        ksort($result);

        return sha1(json_encode($result));
    }

    protected function convertOpenApiTypeToPhp(Schema|Reference $schema)
    {
        if ($schema instanceof Reference) {
            return Str::afterLast($schema->getReference(), '/');
        }

        if (is_array($schema->type)) {
            return collect($schema->type)->map(fn ($type) => $this->mapType($type))->implode('|');
        }

        if (is_string($schema->type)) {
            return $this->mapType($schema->type, $schema->format);
        }

        return 'mixed';
    }

    protected function mapType($type, $format = null): string
    {
        return match ($type) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            'object' => 'object', // Recurse
            'number' => match ($format) {
                'float' => 'float',
                'int32', 'int64	' => 'int',
                default => 'int|float',
            },
            'array' => 'array',
            'null' => 'null',
            default => 'mixed',
        };
    }
}
