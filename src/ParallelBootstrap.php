<?php
declare(strict_types=1);

if (!\function_exists('setlocale')) {
    require_once __DIR__ . '/ParallelStandardFunctions.php';
}

(function(): void {
    $finder = new Symfony\Component\Finder\Finder();

    $finder->in(__DIR__ . '/../PHPUnit')->files();

    \assert($finder->hasResults());

    foreach ($finder as $file) {
        require_once __DIR__ . '/../PHPUnit/' . $file->getRelativePathname();
    }
})();
