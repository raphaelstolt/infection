<?php

declare(strict_types=1);

namespace Infection;

use Infection\Console\OutputFormatter\DotFormatter;
use Infection\Console\OutputFormatter\OutputFormatter;
use Infection\Console\OutputFormatter\ProgressFormatter;
use Infection\EventDispatcher\EventDispatcher;
use Infection\Mutant\Generator\MutationsGenerator;
use Infection\Mutant\MetricsCalculator;
use Infection\Process\Builder\ProcessBuilder;
use Infection\Process\Listener\InitialTestsConsoleLoggerSubscriber;
use Infection\Process\Listener\MutationConsoleLoggerSubscriber;
use Infection\Process\Listener\TextFileLoggerSubscriber;
use Infection\Process\Runner\InitialTestsRunner;
use Infection\Process\Runner\MutationTestingRunner;
use Infection\TestFramework\Coverage\CodeCoverageData;
use Pimple\Container;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InfectionApplication
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct(Container $container, InputInterface $input, OutputInterface $output)
    {
        $this->container = $container;
        $this->input = $input;
        $this->output = $output;
    }

    public function run()
    {
        /** @var EventDispatcher $eventDispatcher */
        $eventDispatcher = $this->get('dispatcher');
        $adapter = $this->get('test.framework.factory')->create($this->input->getOption('test-framework'));

        $initialTestsProgressBar = new ProgressBar($this->output);
        $initialTestsProgressBar->setFormat('verbose');

        $metricsCalculator = new MetricsCalculator($adapter);

        $eventDispatcher->addSubscriber(new InitialTestsConsoleLoggerSubscriber($this->output, $initialTestsProgressBar));
        $eventDispatcher->addSubscriber(new MutationConsoleLoggerSubscriber($this->output, $this->getOutputFormatter(), $metricsCalculator, $this->get('diff.colorizer'), $this->input->getOption('show-mutations')));
        $eventDispatcher->addSubscriber(new TextFileLoggerSubscriber($this->get('infection.config'), $metricsCalculator));

        $processBuilder = new ProcessBuilder($adapter, $this->get('infection.config')->getProcessTimeout());

        $initialTestsRunner = new InitialTestsRunner($processBuilder, $eventDispatcher);
        $initialTestSuitProcess = $initialTestsRunner->run();

        if (! $initialTestSuitProcess->isSuccessful()) {
            $this->output->writeln(
                sprintf(
                    '<error>Tests do not pass. Error code %d. "%s". STDERR: %s</error>',
                    $initialTestSuitProcess->getExitCode(),
                    $initialTestSuitProcess->getExitCodeText(),
                    $initialTestSuitProcess->getErrorOutput()
                )
            );
            return 1;
        }

        $onlyCovered = $this->input->getOption('only-covered');
        $filesFilter = $this->input->getOption('filter');
        $codeCoverageData = new CodeCoverageData($this->get('coverage.dir'), $this->get('coverage.parser'));

        $this->output->writeln(['', 'Generate mutants...', '']);

        $mutationsGenerator = new MutationsGenerator($this->get('src.dirs'), $this->get('exclude.dirs'), $codeCoverageData);
        $mutations = $mutationsGenerator->generate($onlyCovered, $filesFilter);

        $threadCount = (int) $this->input->getOption('threads');
        $parallelProcessManager = $this->get('parallel.process.runner');
        $mutantCreator = $this->get('mutant.creator');

        $mutationTestingRunner = new MutationTestingRunner($processBuilder, $parallelProcessManager, $mutantCreator, $eventDispatcher, $mutations);
        $mutationTestingRunner->run($threadCount, $codeCoverageData);

        return 0;
    }

    /**
     * Shortcut for container getter
     *
     * @param string $name
     * @return mixed
     */
    private function get(string $name)
    {
        return $this->container[$name];
    }

    private function getOutputFormatter(): OutputFormatter
    {
        if ($this->input->getOption('formatter') === 'progress') {
            return new ProgressFormatter(new ProgressBar($this->output));
        }

        if ($this->input->getOption('formatter') === 'dot') {
            return new DotFormatter($this->output);
        }

        throw new \InvalidArgumentException('Incorrect formatter. Possible values: dot, progress');
    }
}