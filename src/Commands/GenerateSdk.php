<?php

namespace Crescat\SaloonSdkGenerator\Commands;

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Exceptions\ParserNotRegisteredException;
use Crescat\SaloonSdkGenerator\Factory;
use Crescat\SaloonSdkGenerator\Generators\ComposerGenerator;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Nette\PhpGenerator\PhpFile;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class GenerateSdk extends Command
{
    protected $signature = 'generate:sdk
                            {path : Path to the API specification file to generate the SDK from, must be a local file}
                            {--type=openapi : The type of API Specification (postman, openapi)}
                            {--name= : The model name of the SDK, default taken from tags or titles. Cashiering example}
                            {--namespace= : Azds\\Sdk : The root namespace of the SDK}
                            {--composer-name= : The root composer namespace of the SDK,  example azds/vendor1 }
                            {--config= : Config file config.yaml}
                            {--output=./build : The output path where the code will be created, will be created if it does not exist.}
                            {--force : Force overwriting existing files}
                            {--dry : Dry run, will only show the files to be generated, does not create or modify any files.}
                            {--zip : Generate a zip archive containing all the files}
                            {--pest : Generate Pest test suites for each resource}';

    protected $description = 'Generate an SDK based on an API specification file.';

    protected Config $config;

    public function handle(): void
    {
        $inputPath = $this->argument('path');

        // TODO: Support remote URLs or move this into each parser class so they can deal with it instead.
        if (! file_exists($inputPath)) {
            $this->error("File not found: $inputPath");

            return;
        }

        $type = trim(strtolower($this->option('type')));

        $configuration = array_merge(
            $this->loadConfiguration('.saloon.yaml'),
            $this->option('config') ? $this->loadConfiguration($this->option('config')) : [],
        );

        $this->config = $config = new Config(
            moduleName: $this->option('name') ?? ($configuration['name'] ?? null),
            namespace: $this->option('namespace') ?? $configuration['namespace'],
            composerName: $this->option('composer-name') ?? ($configuration['composerName'] ?? null),
            resourceNamespaceSuffix: $configuration['resourceNamespaceSuffix'] ?? 'Resource',
            requestNamespaceSuffix: $configuration['requestNamespaceSuffix'] ?? 'Requests',
            dtoNamespaceSuffix: $configuration['dtoNamespaceSuffix'] ?? 'Dto',
            ignoredQueryParams: $configuration['ignoredQueryParams'] ?? [],
            ignoredBodyParams: $configuration['ignoredBodyParams'] ?? [],
            ignoredHeaderParams: $configuration['ignoredHeaderParams'] ?? ['Authorization', 'Content-Type', 'Accept', 'User-Agent'],
            generateGetters: $configuration['generateGetters'] ?? true,
            generateSetters: $configuration['generateSetters'] ?? true,
            publicPropVisibility: $configuration['publicPropVisibility'] ?? true,
            format: $configuration['format'] ?? 'application/json',
            overwritePropertiesType: $configuration['overwritePropertiesType'] ?? [],
        );

        $generator = new CodeGenerator(config: $config);

        // Always generate composer.json
        $generator->registerPostProcessor(new ComposerGenerator());

        try {
            $specification = Factory::parse($type, $inputPath, $config);
        } catch (ParserNotRegisteredException) {
            // TODO: Prettier errors using termwind
            $this->error("No parser registered for --type='$type'");

            if (in_array($type, ['yml', 'yaml', 'json', 'xml'])) {
                $this->warn('Note: the --type option is used to specify the API Specification type (ex: openapi, postman), not the file format.');
            }

            $this->line('Available types: '.implode(', ', Factory::getRegisteredParserTypes()));

            return;
        }

        $result = $generator->run($specification);

        if ($this->option('dry')) {
            $this->printGeneratedFiles($result);

            return;
        }

        $this->option('zip')
            ? $this->generateZipArchive($result)
            : $this->dumpGeneratedFiles($result);
    }

    protected function loadConfiguration(?string $file): array
    {
        if (!$file) {
            return [];
        }

        if (file_exists($file)) {
            $data = file_get_contents($file);

            if (!$data) {
                return [];
            }

            $data = str_ends_with('.json', $file) ? json_decode($data) : Yaml::parse($data) ;

            return is_array($data) ? $data : [];
        }

        return [];
    }

    protected function printGeneratedFiles(GeneratedCode $result): void
    {
        $this->title('Generated Files');

        $this->comment("\nConnector:");
        if ($result->connectorClass) {
            $this->line(Utils::formatNamespaceAndClass($result->connectorClass));
        }

        $this->comment("\nResources:");
        foreach ($result->resourceClasses as $resourceClass) {
            $this->line(Utils::formatNamespaceAndClass($resourceClass));
        }

        $this->comment("\nRequests:");
        foreach ($result->requestClasses as $requestClass) {
            $this->line(Utils::formatNamespaceAndClass($requestClass));
        }

        $this->comment("\nDTOs:");
        foreach ($result->dtoClasses as $dtoClass) {
            $this->line(Utils::formatNamespaceAndClass($dtoClass));
        }
    }

    protected function dumpGeneratedFiles(GeneratedCode $result): void
    {
        $this->title('Generated Files');

        $this->comment("\nConnector:");
        if ($result->connectorClass) {
            $this->dumpToFile($result->connectorClass);
        }

        $this->comment("\nResources:");
        foreach ($result->resourceClasses as $resourceClass) {
            $this->dumpToFile($resourceClass);
        }

        $this->comment("\nRequests:");
        foreach ($result->requestClasses as $requestClass) {
            $this->dumpToFile($requestClass);
        }

        $this->comment("\nDTOs:");
        foreach ($result->dtoClasses as $dtoClass) {
            $this->dumpToFile($dtoClass);
        }

        // Handle other additional files (composer.json, etc.) - excluding test files
        $otherFiles = collect($result->additionalFiles)
            ->filter(fn ($file) => $file instanceof \Crescat\SaloonSdkGenerator\Data\TaggedOutputFile)
            ->filter(fn ($file) => $file->tag !== 'pest')
            ->values();

        if ($otherFiles->isNotEmpty()) {
            $this->comment("\nProject Files:");
            foreach ($otherFiles as $file) {
                $filePath = $this->option('output').'/'.$file->path;

                if (! file_exists(dirname($filePath))) {
                    mkdir(dirname($filePath), recursive: true);
                }

                if (file_exists($filePath) && ! $this->option('force')) {
                    $this->warn("- File already exists: $filePath");

                    continue;
                }

                $ok = file_put_contents($filePath, $file->file);

                if ($ok === false) {
                    $this->error("- Failed to write: $filePath");
                } else {
                    $this->line("- Created: $filePath");
                }
            }
        }
    }

    protected function dumpToFile(PhpFile $file, $overrideFilePath = null): void
    {

        // TODO: Cleanup this, brittle and will break if you change the namespace
        $wip = sprintf(
            '%s/src/%s/%s.php',
            $this->option('output'),
            str_replace($this->config->namespace, '', Arr::first($file->getNamespaces())->getName()),
            Arr::first($file->getClasses())?->getName(),
        );

        $filePath = $overrideFilePath ?? Str::of($wip)->replace('\\', '/')->replace('//', '/')->toString();

        if (! file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), recursive: true);
        }

        $ok = file_put_contents($filePath, (string) $file);

        if ($ok === false) {
            $this->error("- Failed to write: $filePath");
        } else {
            $this->line("- Created: $filePath");
        }
    }

    protected function generateZipArchive(GeneratedCode $result): void
    {
        $zipFileName = $this->option('name').'_sdk.zip';
        $zipPath = $this->option('output').DIRECTORY_SEPARATOR.$zipFileName;

        if (! file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), recursive: true);
        }

        if (file_exists($zipPath) && ! $this->option('force')) {
            $this->warn("- Zip archive already exists: $zipPath");

            return;
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("- Failed to create the ZIP archive: $zipPath");

            return;
        }

        $filesToZip = array_merge(
            [$result->connectorClass],
            $result->resourceClasses,
            $result->requestClasses,
            $result->dtoClasses,
            $result->additionalFiles,
        );

        foreach ($filesToZip as $file) {
            $filePathInZip = str_replace('\\', '/', Arr::first($file->getNamespaces())->getName()).'/'.Arr::first($file->getClasses())->getName().'.php';
            $zip->addFromString($filePathInZip, (string) $file);
            $this->line("- Wrote file to ZIP: $filePathInZip");
        }

        $zip->close();

        $this->line("- Created zip archive: $zipPath");
    }
}
