<?php

namespace Nemo64\WebpackEnvironment\Configurator;


use Composer\IO\IOInterface;
use Nemo64\Environment\Configurator\ConfigurableConfiguratorInterface;
use Nemo64\Environment\Configurator\DockerConfigurator;
use Nemo64\Environment\Configurator\GitignoreConfigurator;
use Nemo64\Environment\Configurator\MakefileConfigurator;
use Nemo64\Environment\ConfiguratorContainer;
use Nemo64\Environment\ExecutionContext;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WebpackConfigurator implements ConfigurableConfiguratorInterface
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_FORCE_OBJECT;

    /**
     * @var array
     */
    private $options;

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
        $resolver->setDefault('webpack-generated', false);
        $resolver->setAllowedTypes('webpack-generated', 'bool');
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
            'command' => 'yarn run encore dev-server --host 0.0.0.0 --port 9000',
            'ports' => [
                '9000:9000'
            ],
            'volumes' => [
                '.:/var/www:delegated',
                '~/.cache/yarn:/usr/local/share/.cache/yarn'
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

        // everything below this line will only be executed once.

        if ($container->getOption('webpack-generated')) {
            return;
        }

        if ($this->createPackageJson($context, $container)) {
            $context->info("Created <info>package.json</info>.");
        } else if (!preg_match('#@symfony/webpack-encore#', file_get_contents($context->getPath('package.json')))) {
            $msg = "packages.json already exists but does not contain @symfony/webpack-encore.";
            $msg .= "\nYou might need to manually configure it in order for the webpack-environment to work.";
            $msg .= "\nLook at Nemo64\WebpackEnvironment\Configurator\WebpackConfigurator::createPackageJson.";
            $context->warn($msg);
        }

        if ($this->createWebpackFile($context, $container)) {
            $context->info("Created <info>webpack.config.js</info>");

            if ($this->createPostCssFile($context, $container)) {
                $context->info("Created <info>postcss.config.js</info>");
            }
        }

        if ($this->createAppJs($context, $container)) {
            $context->warn("There is now an <info>app.js</info> in your project root. Adjust it as needed.");
        }

        $container->setOption('webpack-generated', true);
    }

    private function getOptions(IOInterface $io)
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $this->options = [
            'bootstrap' => $bootstrap = $io->askConfirmation("Use Bootstrap? (default yes): "),
            'jquery' => $bootstrap || $io->askConfirmation("Add jQuery? (default yes): "),
            'enable-autoprefixer' => $bootstrap || $io->askConfirmation("Enable autoprefixer? (default yes): "),
            'enable-sass' => $bootstrap || $io->askConfirmation("Enable sass support? (default yes): "),
        ];

        $this->options['enable-postcss'] = $this->options['enable-autoprefixer'];

        return $this->options;
    }

    private function createPackageJson(ExecutionContext $context, ConfiguratorContainer $container): bool
    {
        $packageJson = $context->getPath('package.json');
        if (file_exists($packageJson)) {
            return false;
        }

        $options = $this->getOptions($context->getIo());
        $devDependencies = ["@symfony/webpack-encore" => "^0.17.0"];
        $dependencies = [];

        if ($options['bootstrap']) {
            $dependencies['bootstrap'] = '^4.1.1';
            $dependencies['popper.js'] = '^1.14.3';
        }

        if ($options['jquery']) {
            $dependencies['jquery'] = '^3.3.1';
        }

        if ($options['enable-postcss']) {
            $devDependencies['postcss-loader'] = '^2.1.5';
        }

        if ($options['enable-autoprefixer']) {
            $devDependencies['autoprefixer'] = '^7.0.1';
        }

        if ($options['enable-sass']) {
            $devDependencies['node-sass'] = '^4.9.0';
            $devDependencies['sass-loader'] = '^7.0.1';
        }

        ksort($devDependencies);
        ksort($dependencies);

        file_put_contents($packageJson, json_encode([
            "devDependencies" => $devDependencies,
            "dependencies" => $dependencies,
            "license" => "UNLICENSED",
            "private" => true,
            "scripts" => [
                "dev-server" => "encore dev-server",
                "dev" => "encore dev",
                "watch" => "encore dev --watch",
                "build" => "encore production"
            ],
            "browserslist" => [
                "defaults"
            ]
        ], self::JSON_FLAGS));

        return true;
    }

    private function createWebpackFile(ExecutionContext $context, ConfiguratorContainer $container): bool
    {
        $webpackConfigFile = $context->getPath('webpack.config.js');
        if (file_exists($webpackConfigFile)) {
            return false;
        }

        $documentRoot = $container->getOption('document-root');
        $options = $this->getOptions($context->getIo());

        $encoreCalls = [
            "// the project directory where compiled assets will be stored",
            ".setOutputPath('$documentRoot/build/')",
            "// the public path used by the web server to access the previous directory",
            ".setPublicPath('/build')",
            ".cleanupOutputBeforeBuild()",
            ".enableSourceMaps(!Encore.isProduction())",
            "// uncomment to create hashed filenames (e.g. app.abc123.css)",
            "// .enableVersioning(Encore.isProduction())",
            "",
            "// uncomment to define the assets of the project",
            "// .addEntry('js/app', './assets/js/app.js')",
            "// .addStyleEntry('css/app', './assets/css/app.scss')",
            ".addEntry('app', './app.js')",
        ];

        if ($options['jquery']) {
            $encoreCalls[] = ".autoProvidejQuery()";
        }

        if ($options['enable-autoprefixer']) {
            $encoreCalls[] = ".enablePostCssLoader()";
        }

        if ($options['enable-sass']) {
            $encoreCalls[] = ".enableSassLoader()";
        }

        $encoreCallString = implode("\n    ", $encoreCalls);
        file_put_contents($webpackConfigFile, <<<WEBPACK_CONFIG
let Encore = require('@symfony/webpack-encore');

Encore
    $encoreCallString
;

module.exports = Encore.getWebpackConfig();
WEBPACK_CONFIG
        );

        return true;
    }

    private function createPostCssFile(ExecutionContext $context, ConfiguratorContainer $container): bool
    {
        $postCss = $context->getPath('postcss.config.js');
        if (file_exists($postCss)) {
            return false;
        }

        $options = $this->getOptions($context->getIo());
        if (!$options['enable-postcss']) {
            return false;
        }

        $configuration = [
            'plugins' => []
        ];

        if ($options['enable-autoprefixer']) {
            $configuration['plugins']['autoprefixer'] = [];
        }

        file_put_contents($postCss, 'module.exports = ' . json_encode($configuration, self::JSON_FLAGS) . ';');

        return true;
    }

    private function createAppJs(ExecutionContext $context, ConfiguratorContainer $container): bool
    {
        $appJs = $context->getPath('app.js');
        if (file_exists($appJs)) {
            return false;
        }

        file_put_contents($appJs, <<<JavaScript

function requireAll(r) {
    r.keys().forEach(r);
}

// uncomment and adjust as needed
//requireAll(require.context('./templates', true, /\.(js|css|scss|sass)$/i));

JavaScript
        );

        return true;
    }
}