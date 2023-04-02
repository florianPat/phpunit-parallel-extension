<?php
declare(strict_types=1);

namespace ParallelExtension;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class ExtensionBootstrap implements Extension
{
    private const PHP_EXTENSION = 'parallel';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        self::checkPhpExtension();

        $nCores = null;
        $extensionParameters = [];
        if ($parameters->has('nCores')) {
            $nCores = (int) $parameters->get('nCores');
            $extensionParameters['nCores'] = $parameters->get('nCores');
        }

        require_once __DIR__ . '/ParallelBootstrap.php';

        $app = new Application($nCores);
        exit($app->run($configuration, self::class, $extensionParameters));
    }

    private static function checkPhpExtension(): void
    {
        if (extension_loaded(self::PHP_EXTENSION)) {
            return;
        }

        fwrite(
            STDERR,
            sprintf(
                'PHPUnit-Parallel-Extension requires the "%s" extension.' . PHP_EOL,
                self::PHP_EXTENSION,
            )
        );

        die(1);
    }
}
