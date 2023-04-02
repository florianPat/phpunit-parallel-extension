<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace ParallelExtension;

use PHPUnit\TextUI\Command;
use PHPUnit\TextUI\Exception;
use PHPUnit\TextUI\ShellExitCodeCalculator;
use PHPUnit\TextUI\TestRunner;
use const PHP_EOL;
use function is_file;
use function is_readable;
use function printf;
use function realpath;
use function sprintf;
use function trim;
use function unlink;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\UnknownSubscriberTypeException;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Logging\EventLogger;
use PHPUnit\Logging\JUnit\JunitXmlLogger;
use PHPUnit\Logging\TeamCity\TeamCityLogger;
use PHPUnit\Logging\TestDox\HtmlRenderer as TestDoxHtmlRenderer;
use PHPUnit\Logging\TestDox\PlainTextRenderer as TestDoxTextRenderer;
use PHPUnit\Logging\TestDox\TestResultCollector as TestDoxResultCollector;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\Extension\ExtensionBootstrapper;
use PHPUnit\Runner\Extension\Facade as ExtensionFacade;
use PHPUnit\Runner\Extension\PharLoader;
use PHPUnit\Runner\ResultCache\DefaultResultCache;
use PHPUnit\Runner\ResultCache\NullResultCache;
use PHPUnit\Runner\ResultCache\ResultCache;
use PHPUnit\Runner\ResultCache\ResultCacheHandler;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\Runner\Version;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use PHPUnit\TextUI\CliArguments\Builder;
use PHPUnit\TextUI\CliArguments\Configuration as CliConfiguration;
use PHPUnit\TextUI\CliArguments\Exception as ArgumentsException;
use PHPUnit\TextUI\Command\AtLeastVersionCommand;
use PHPUnit\TextUI\Command\GenerateConfigurationCommand;
use PHPUnit\TextUI\Command\ListGroupsCommand;
use PHPUnit\TextUI\Command\ListTestsAsTextCommand;
use PHPUnit\TextUI\Command\ListTestsAsXmlCommand;
use PHPUnit\TextUI\Command\ListTestSuitesCommand;
use PHPUnit\TextUI\Command\MigrateConfigurationCommand;
use PHPUnit\TextUI\Command\Result;
use PHPUnit\TextUI\Command\ShowHelpCommand;
use PHPUnit\TextUI\Command\ShowVersionCommand;
use PHPUnit\TextUI\Command\VersionCheckCommand;
use PHPUnit\TextUI\Command\WarmCodeCoverageCacheCommand;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\Output\DefaultPrinter;
use PHPUnit\TextUI\Output\Facade as OutputFacade;
use PHPUnit\TextUI\Output\Printer;
use PHPUnit\TextUI\XmlConfiguration\Configuration as XmlConfiguration;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use SebastianBergmann\Timer\Timer;
use Throwable;
use PHPUnit\Event;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Application
{
    private int $nCores;

    public function __construct(?int $nCores = null)
    {
        $this->nCores = $nCores ?? ((int) shell_exec('nproc'));
    }

    public function run(Configuration $configuration, string $extensionClassName, array $extensionParameters): int
    {
        EventFacade::instance()->emitter()->testRunnerBootstrappedExtension(
            $extensionClassName,
            $extensionParameters,
        );

        try {
            $pharExtensions = null;

            if (!$configuration->noExtensions()) {
                if ($configuration->hasPharExtensionDirectory()) {
                    $pharExtensions = (new PharLoader)->loadPharExtensionsInDirectory(
                        $configuration->pharExtensionDirectory()
                    );
                }

                $this->bootstrapExtensions($configuration, $extensionClassName, true);
            }

            CodeCoverage::instance()->init($configuration, CodeCoverageFilterRegistry::instance());

            $printer = OutputFacade::init($configuration);

            $this->writeRuntimeInformation($printer, $configuration);
            $this->writePharExtensionInformation($printer, $pharExtensions);
            $this->writeRandomSeedInformation($printer, $configuration);
            $this->writeCoreInformation($printer);

            $printer->print(PHP_EOL);

            $this->registerLogfileWriters($configuration);

            $testDoxResultCollector = $this->testDoxResultCollector($configuration);

            TestResultFacade::init();

            $resultCache = $this->initializeTestResultCache($configuration);

            EventFacade::instance()->seal();

            $timer = new Timer;
            $timer->start();

            $threads      = [];
            $futures      = [];
            $readChannels = [];

            for ($i = 0; $i < $this->nCores; $i++) {
                $threads[] = new \parallel\Runtime(PHPUNIT_COMPOSER_INSTALL);
            }

            $serializedConfiguration = \serialize($configuration);

            foreach ($threads as $i => $thread) {
                $readChannels[] = $eventDispatcherChannel = new \parallel\Channel;

                $futures[] = $thread->run(
                    static function (\parallel\Channel $eventDispatcherChannel, int $threadId, int $nCores) use ($extensionClassName, $serializedConfiguration, $resultCache)
                    {
                        $configuration = \unserialize($serializedConfiguration);

                        $GLOBALS['THREAD_ID'] = $threadId;
                        $GLOBALS['N_CORES']   = $nCores;

                        // NOTE: Run bootstrapping again in new thread
                        Registry::set($configuration);
                        (new PhpHandler)->handle($configuration->php());

                        if ($configuration->hasBootstrap()) {
                            self::loadBootstrapScript($configuration->bootstrap());
                        }
                        $testSuite = self::buildTestSuite($configuration);

                        if (!$configuration->noExtensions()) {
                            self::bootstrapExtensions($configuration, $extensionClassName, false);
                        }
                        CodeCoverage::instance()->init($configuration, CodeCoverageFilterRegistry::instance());
                        // NOTE: We do not init the output facade to not get output :)
                        //OutputFacade::init($configuration);
                        TestResultFacade::init();
                        EventFacade::instance()->seal();
                        EventFacade::instance()->initForParallel($eventDispatcherChannel);

                        $runner = new TestRunner;
                        $runner->run(
                            $configuration,
                            $resultCache,
                            $testSuite
                        );

                        if (!CodeCoverage::instance()->isActive()) {
                            return null;
                        }

                        return serialize(CodeCoverage::instance()->codeCoverage());
                    },
                    [$eventDispatcherChannel, $i, $this->nCores]
                );
            }

            $testRunnerFinished = $this->nCores;

            $events = new \parallel\Events;
            $events->setBlocking(true);

            foreach ($readChannels as $readChannel) {
                $events->addChannel($readChannel);
            }

            for ($i = 0; $i < count($futures); $i++) {
                $events->addFuture(sprintf('future_%d', $i), $futures[$i]);
            }

            $testRunnerExecutionFinishedEvent = null;
            while (0 !== $testRunnerFinished) {
                $event = $events->poll();

                if (str_starts_with($event->source, 'future')) {
                    $testRunnerFinished--;

                    if (null === $event->value) {
                        continue;
                    }

                    $coverage = unserialize($event->value);
                    assert($coverage instanceof \SebastianBergmann\CodeCoverage\CodeCoverage);
                    CodeCoverage::instance()->codeCoverage()->merge($coverage);
                    continue;
                }

                assert(null !== $event);
                $events->addChannel($event->object);
                $eventCollection = unserialize($event->value);

                foreach ($eventCollection->asArray() as $event) {
                    if ($event instanceof Event\TestRunner\ExecutionFinished) {
                        $testRunnerExecutionFinishedEvent = $event;
                        $newEventCollection = new Event\EventCollection();
                        $newEventCollection->add(
                            ...\array_filter(
                                $eventCollection->asArray(),
                                fn (Event\Event $event) => !($event instanceof Event\TestRunner\ExecutionFinished),
                            ),
                        );
                        $eventCollection = $newEventCollection;
                        break;
                    }
                }

                EventFacade::instance()->forward($eventCollection);
            }

            $eventCollection = new Event\EventCollection();
            $eventCollection->add($testRunnerExecutionFinishedEvent);
            EventFacade::instance()->forward($eventCollection);

            foreach ($readChannels as $readChannel) {
                $readChannel->close();
            }

            foreach ($threads as $thread) {
                $thread->close();
            }

            $duration = $timer->stop();

            $testDoxResult = null;

            if (isset($testDoxResultCollector)) {
                $testDoxResult = $testDoxResultCollector->testMethodsGroupedByClass();
            }

            if ($testDoxResult !== null &&
                $configuration->hasLogfileTestdoxHtml()) {
                OutputFacade::printerFor($configuration->logfileTestdoxHtml())->print(
                    (new TestDoxHtmlRenderer)->render($testDoxResult)
                );
            }

            if ($testDoxResult !== null &&
                $configuration->hasLogfileTestdoxText()) {
                OutputFacade::printerFor($configuration->logfileTestdoxText())->print(
                    (new TestDoxTextRenderer)->render($testDoxResult)
                );
            }

            $result = TestResultFacade::result();

            OutputFacade::printResult($result, $testDoxResult, $duration);
            CodeCoverage::instance()->generateReports($printer, $configuration);

            $shellExitCode = (new ShellExitCodeCalculator)->calculate(
                $configuration->failOnEmptyTestSuite(),
                $configuration->failOnRisky(),
                $configuration->failOnWarning(),
                $configuration->failOnIncomplete(),
                $configuration->failOnSkipped(),
                $result
            );

            EventFacade::instance()->emitter()->applicationFinished($shellExitCode);

            return $shellExitCode;
        } catch (Throwable $t) {
            $this->exitWithCrashMessage($t);
        }
    }

    private function exitWithCrashMessage(Throwable $t): never
    {
        $message = $t->getMessage();

        if (empty(trim($message))) {
            $message = '(no message)';
        }

        printf(
            '%s%sAn error occurred inside PHPUnit.%s%sMessage:  %s',
            PHP_EOL,
            PHP_EOL,
            PHP_EOL,
            PHP_EOL,
            $message
        );

        $first = true;

        do {
            printf(
                '%s%s: %s:%d%s%s%s%s',
                PHP_EOL,
                $first ? 'Location' : 'Caused by',
                $t->getFile(),
                $t->getLine(),
                PHP_EOL,
                PHP_EOL,
                $t->getTraceAsString(),
                PHP_EOL
            );

            $first = false;
        } while ($t = $t->getPrevious());

        exit(Result::CRASH);
    }

    private static function exitWithErrorMessage(string $message): never
    {
        print Version::getVersionString() . PHP_EOL . PHP_EOL . $message . PHP_EOL;

        exit(Result::EXCEPTION);
    }

    private function execute(Command\Command $command): never
    {
        print Version::getVersionString() . PHP_EOL . PHP_EOL;

        $result = $command->execute();

        print $result->output();

        exit($result->shellExitCode());
    }

    private static function loadBootstrapScript(string $filename): void
    {
        if (!is_readable($filename)) {
            self::exitWithErrorMessage(
                sprintf(
                    'Cannot open bootstrap script "%s"',
                    $filename
                )
            );
        }

        try {
            include_once $filename;
        } catch (Throwable $t) {
            self::exitWithErrorMessage(
                sprintf(
                    'Error in bootstrap script: %s:%s%s%s%s',
                    $t::class,
                    PHP_EOL,
                    $t->getMessage(),
                    PHP_EOL,
                    $t->getTraceAsString()
                )
            );
        }

        EventFacade::instance()->emitter()->testRunnerBootstrapFinished($filename);
    }

    private function buildCliConfiguration(array $argv): CliConfiguration
    {
        try {
            $cliConfiguration = (new Builder)->fromParameters($argv);
        } catch (ArgumentsException $e) {
            $this->exitWithErrorMessage($e->getMessage());
        }

        return $cliConfiguration;
    }

    private function loadXmlConfiguration(string|false $configurationFile): XmlConfiguration
    {
        if (!$configurationFile) {
            return DefaultConfiguration::create();
        }

        try {
            return (new Loader)->load($configurationFile);
        } catch (Throwable $e) {
            $this->exitWithErrorMessage($e->getMessage());
        }
    }

    private static function buildTestSuite(Configuration $configuration): TestSuite
    {
        try {
            return (new TestSuiteBuilder)->build($configuration);
        } catch (Exception $e) {
            self::exitWithErrorMessage($e->getMessage());
        }
    }

    private static function bootstrapExtensions(Configuration $configuration, string $extensionClassName, bool $skipTillExtension): void
    {
        $extensionBootstrapper = new ExtensionBootstrapper(
            $configuration,
            new ExtensionFacade
        );

        $seenExtensionClassName = $skipTillExtension;
        foreach ($configuration->extensionBootstrappers() as $bootstrapper) {
            if (!$seenExtensionClassName || $bootstrapper['className'] === $extensionClassName) {
                $seenExtensionClassName = true;
                continue;
            }

            try {
                $extensionBootstrapper->bootstrap(
                    $bootstrapper['className'],
                    $bootstrapper['parameters']
                );
            } catch (\PHPUnit\Runner\Exception $e) {
                self::exitWithErrorMessage(
                    sprintf(
                        'Error while bootstrapping extension: %s',
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function executeCommandsThatOnlyRequireCliConfiguration(CliConfiguration $cliConfiguration, string|false $configurationFile): void
    {
        if ($cliConfiguration->generateConfiguration()) {
            $this->execute(new GenerateConfigurationCommand);
        }

        if ($cliConfiguration->migrateConfiguration()) {
            if (!$configurationFile) {
                $this->exitWithErrorMessage('No configuration file found to migrate');
            }

            $this->execute(new MigrateConfigurationCommand(realpath($configurationFile)));
        }

        if ($cliConfiguration->hasAtLeastVersion()) {
            $this->execute(new AtLeastVersionCommand($cliConfiguration->atLeastVersion()));
        }

        if ($cliConfiguration->version()) {
            $this->execute(new ShowVersionCommand);
        }

        if ($cliConfiguration->checkVersion()) {
            $this->execute(new VersionCheckCommand);
        }

        if ($cliConfiguration->help()) {
            $this->execute(new ShowHelpCommand(Result::SUCCESS));
        }
    }

    private function executeCommandsThatRequireCliConfigurationAndTestSuite(CliConfiguration $cliConfiguration, TestSuite $testSuite): void
    {
        if ($cliConfiguration->listGroups()) {
            $this->execute(new ListGroupsCommand($testSuite));
        }

        if ($cliConfiguration->listTests()) {
            $this->execute(new ListTestsAsTextCommand($testSuite));
        }

        if ($cliConfiguration->hasListTestsXml()) {
            $this->execute(
                new ListTestsAsXmlCommand(
                    $cliConfiguration->listTestsXml(),
                    $testSuite
                )
            );
        }
    }

    private function executeCommandsThatRequireCompleteConfiguration(Configuration $configuration, CliConfiguration $cliConfiguration): void
    {
        if ($cliConfiguration->listSuites()) {
            $this->execute(new ListTestSuitesCommand($configuration->testSuite()));
        }

        if ($cliConfiguration->warmCoverageCache()) {
            $this->execute(new WarmCodeCoverageCacheCommand($configuration, CodeCoverageFilterRegistry::instance()));
        }
    }

    private function executeHelpCommandWhenThereIsNothingElseToDo(Configuration $configuration, TestSuite $testSuite): void
    {
        if ($testSuite->isEmpty() && !$configuration->hasCliArgument() && $configuration->testSuite()->isEmpty()) {
            $this->execute(new ShowHelpCommand(Result::FAILURE));
        }
    }

    private function writeRuntimeInformation(Printer $printer, Configuration $configuration): void
    {
        $printer->print(Version::getVersionString() . PHP_EOL . PHP_EOL);

        $runtime = 'PHP ' . PHP_VERSION;

        if (CodeCoverage::instance()->isActive()) {
            $runtime .= ' with ' . CodeCoverage::instance()->driver()->nameAndVersion();
        }

        $this->writeMessage($printer, 'Runtime', $runtime);

        if ($configuration->hasConfigurationFile()) {
            $this->writeMessage(
                $printer,
                'Configuration',
                $configuration->configurationFile()
            );
        }
    }

    /**
     * @psalm-param ?array{loadedExtensions: list<string>, notLoadedExtensions: list<string>} $pharExtensions
     */
    private function writePharExtensionInformation(Printer $printer, ?array $pharExtensions): void
    {
        if ($pharExtensions === null) {
            return;
        }

        foreach ($pharExtensions['loadedExtensions'] as $extension) {
            $this->writeMessage(
                $printer,
                'Extension',
                $extension
            );
        }

        foreach ($pharExtensions['notLoadedExtensions'] as $extension) {
            $this->writeMessage(
                $printer,
                'Extension',
                $extension
            );
        }
    }

    private function writeMessage(Printer $printer, string $type, string $message): void
    {
        $printer->print(
            sprintf(
                "%-15s%s\n",
                $type . ':',
                $message
            )
        );
    }

    private function writeRandomSeedInformation(Printer $printer, Configuration $configuration): void
    {
        if ($configuration->executionOrder() === TestSuiteSorter::ORDER_RANDOMIZED) {
            $this->writeMessage(
                $printer,
                'Random Seed',
                (string) $configuration->randomOrderSeed()
            );
        }
    }

    private function writeCoreInformation(Printer $printer): void
    {
        $this->writeMessage(
            $printer,
            'Cores',
            (string) $this->nCores,
        );
    }

    /**
     * @throws EventFacadeIsSealedException
     * @throws UnknownSubscriberTypeException
     */
    private function registerLogfileWriters(Configuration $configuration): void
    {
        if ($configuration->hasLogEventsText()) {
            if (is_file($configuration->logEventsText())) {
                unlink($configuration->logEventsText());
            }

            EventFacade::instance()->registerTracer(
                new EventLogger(
                    $configuration->logEventsText(),
                    false
                )
            );
        }

        if ($configuration->hasLogEventsVerboseText()) {
            if (is_file($configuration->logEventsVerboseText())) {
                unlink($configuration->logEventsVerboseText());
            }

            EventFacade::instance()->registerTracer(
                new EventLogger(
                    $configuration->logEventsVerboseText(),
                    true
                )
            );
        }

        if ($configuration->hasLogfileJunit()) {
            new JunitXmlLogger(
                OutputFacade::printerFor($configuration->logfileJunit()),
                EventFacade::instance()
            );
        }

        if ($configuration->hasLogfileTeamcity()) {
            new TeamCityLogger(
                DefaultPrinter::from(
                    $configuration->logfileTeamcity()
                ),
                EventFacade::instance()
            );
        }
    }

    /**
     * @throws EventFacadeIsSealedException
     * @throws UnknownSubscriberTypeException
     */
    private function testDoxResultCollector(Configuration $configuration): ?TestDoxResultCollector
    {
        if ($configuration->hasLogfileTestdoxHtml() ||
            $configuration->hasLogfileTestdoxText() ||
            $configuration->outputIsTestDox()) {
            return new TestDoxResultCollector(EventFacade::instance());
        }

        return null;
    }

    /**
     * @throws EventFacadeIsSealedException
     * @throws UnknownSubscriberTypeException
     */
    private function initializeTestResultCache(Configuration $configuration): ResultCache
    {
        if ($configuration->cacheResult()) {
            $cache = new DefaultResultCache($configuration->testResultCacheFile());

            new ResultCacheHandler($cache, EventFacade::instance());

            return $cache;
        }

        return new NullResultCache;
    }
}
