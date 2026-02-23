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
            'authors' => [
                ['name' => 'AZDS', 'homepage' => 'https://www.azds.com/']
            ],
            'require' => [
                'php' => '>=8.1',
                'azds/data-transfer' => '*'
            ],
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
}
