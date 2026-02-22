<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\PhpGenerator\PhpFile;

class ComposerGenerator implements PostProcessor
{
    public function __construct(
        protected bool $pestEnabled = false
    ) {}

    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        $composer = [
            'name' => $this->generatePackageName($config, $specification),
            'description' => "{$specification->name} SDK",
            'type' => 'library',
            'require' => [
                'php' => '>=8.1',
                'azds/data-transfer' => '*'
            ],
            'require-dev' => $this->getDevDependencies(),
            'autoload' => [
                'psr-4' => [
                    "{$config->namespace}\\" => 'src/',
                ],
            ],
        ];

        $generatedCode->addAdditionalFile(
            new TaggedOutputFile(
                tag: 'composer',
                file: json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                path: 'composer.json',
            )
        );

        return [];
    }

    protected function generatePackageName(Config $config, ApiSpecification $specification): string
    {
        $namespaceParts = explode('\\', $config->namespace);

        // Normalize vendor and package names for Composer
        $vendor = $this->toComposerName(NameHelper::normalize($namespaceParts[0] ?? 'vendor'));

        $package = $this->toComposerName(NameHelper::normalize($config->moduleName ?? 'sdk'));

        return "{$vendor}/{$package}";
    }

    protected function toComposerName(string $value): string
    {
        // Convert to lowercase and replace spaces/underscores with hyphens
        return strtolower(str_replace(['_', ' '], '-', $value));
    }

    protected function getDevDependencies(): array
    {
        if ($this->pestEnabled) {
            return [
                'pestphp/pest' => '^2.0',
                'orchestra/testbench' => '^8.0|^9.0',
                'saloonphp/laravel-plugin' => '^3.0',
                'spatie/laravel-data' => '^3.0|^4.0',
                'vlucas/phpdotenv' => '^5.6',
            ];
        }

        return [
            'phpunit/phpunit' => '^10.0|^11.0',
        ];
    }
}
