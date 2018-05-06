<?php

namespace Nemo64\WebpackEnvironment\Configurator;


use Nemo64\Environment\Configurator\ConfigurableConfiguratorInterface;
use Nemo64\Environment\Configurator\DockerConfigurator;
use Nemo64\Environment\Configurator\GitignoreConfigurator;
use Nemo64\Environment\Configurator\MakefileConfigurator;
use Nemo64\Environment\ConfiguratorContainer;
use Nemo64\Environment\ExecutionContext;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WebpackConfigurator implements ConfigurableConfiguratorInterface
{

    /**
     * A list of other generators that are configured by this generator.
     * Those other configurators are executed after this one.
     *
     * @return string[]
     */
    public function getInfluences(): array
    {
        return [
            MakefileConfigurator::class,
            DockerConfigurator::class
        ];
    }

    public function configureOptions(ExecutionContext $context, OptionsResolver $resolver): void
    {
        $resolver->setDefined('webpack-appjs-generated');
        $resolver->setAllowedTypes('webpack-appjs-generated', 'bool');
    }

    /**
     * This method configures other services and writes to the disk.
     *
     * @param ExecutionContext $context
     * @param ConfiguratorContainer $container
     * @return void
     */
    public function configure(ExecutionContext $context, ConfiguratorContainer $container): void
    {
        $docker = $container->get(DockerConfigurator::class);
        $make = $container->get(MakefileConfigurator::class);
        if (!$docker) {
            throw new \RuntimeException("WebpackConfigurator won't work without docker");
        }

        if (!$make) {
            throw new \RuntimeException("WebpackConfigurator won't work without make");
        }

        $docker->defineService('webpack', [
            'image' => 'node:carbon',
            'command' => 'yarn run encore dev --watch',
            'volumes' => [
                '.:/var/www'
            ],
            'working_dir' => '/var/www',
        ]);

        $make->setEnvironment('YARN', 'docker-compose run --rm --no-deps webpack yarn');
        $make['node_modules']->addDependencyString('docker-compose.log');
        $make['node_modules']->addDependencyString('$(wildcard package.* yarn.*)');
        $make['node_modules']->addCommand('$(YARN) install');
        $make['install']->addDependency($make['node_modules']);
        $make['clean']->addCommand('rm -rf node_modules');

        $gitignore = $container->get(GitignoreConfigurator::class);
        if ($gitignore !== null) {
            $gitignore->add('yarn-error.log');
        }

        $packageJsonFilename = $context->getPath('package.json');
        if (!file_exists($packageJsonFilename)) {
            $this->createPackageJson($packageJsonFilename, $container);
        } else {
            if (!preg_match('#@symfony/webpack-encore#', file_get_contents($packageJsonFilename))) {
                $msg = "packages.json already exists but does not contain @symfony/webpack-encore.";
                $msg .= "\nYou might need to manually configure it in order for the webpack-environment to work.";
                $msg .= "\nLook at Nemo64\WebpackEnvironment\Configurator\WebpackConfigurator::createPackageJson.";
                $context->getIo()->write($msg);
            }
        }

        $webpackFilename = $context->getPath('webpack.config.js');
        if (!file_exists($webpackFilename)) {
            $this->createWebpackFile($webpackFilename, $container);
        }

        $appJsFilename = $context->getPath('app.js');
        if (!file_exists($appJsFilename) && !$container->getOption('webpack-appjs-generated')) {
            $this->createAppJs($appJsFilename, $container);
            $container->setOption('webpack-appjs-generated', true);
            $context->getIo()->write("There is now an <info>app.js</info> in your project root. Adjust it as needed.");
        }
    }

    private function createPackageJson(string $filename, ConfiguratorContainer $container)
    {
        file_put_contents($filename, json_encode([
            "devDependencies" => [
                "@symfony/webpack-encore" => "^0.17.0"
            ],
            "license" => "UNLICENSED",
            "private" => true,
            "scripts" => [
                "dev-server" => "encore dev-server",
                "dev" => "encore dev",
                "watch" => "encore dev --watch",
                "build" => "encore production"
            ]
        ], JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function createWebpackFile(string $filename, ConfiguratorContainer $container)
    {
        $documentRoot = $container->getOption('document-root');
        file_put_contents($filename, <<<WEBPACK_CONFIG
let Encore = require('@symfony/webpack-encore');

Encore
    // the project directory where compiled assets will be stored
    .setOutputPath('$documentRoot/build/')
    // the public path used by the web server to access the previous directory
    .setPublicPath('/build')
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    // uncomment to create hashed filenames (e.g. app.abc123.css)
    .enableVersioning(Encore.isProduction())

    // uncomment to define the assets of the project
    // .addEntry('js/app', './assets/js/app.js')
    // .addStyleEntry('css/app', './assets/css/app.scss')
    .addEntry('app', './app.js')

    // uncomment if you use Sass/SCSS files
    // .enableSassLoader()

    // uncomment for legacy applications that require $/jQuery as a global variable
    // .autoProvidejQuery()
;

module.exports = Encore.getWebpackConfig();
WEBPACK_CONFIG
        );
    }

    private function createAppJs(string $filename, ConfiguratorContainer $container)
    {
        file_put_contents($filename, <<<JavaScript

function requireAll(r) {
    r.keys().forEach(r);
}

// uncomment and adjust as needed
//requireAll(require.context('./templates', true, /\.(js|css|scss)$/));

JavaScript
        );
    }
}