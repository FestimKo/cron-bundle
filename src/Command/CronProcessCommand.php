<?php

namespace Shapecode\Bundle\CronBundle\Command;

use Shapecode\Bundle\CronBundle\Entity\CronJobInterface;
use Shapecode\Bundle\CronBundle\Entity\CronJobResultInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CronProcessCommand
 *
 * @package Shapecode\Bundle\CronBundle\Command
 * @author  Nikita Loges
 */
class CronProcessCommand extends BaseCommand
{

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('shapecode:cron:process');

        $this->addArgument('cron', InputArgument::REQUIRED);
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var CronJobInterface $job */
        $job = $this->getCronJobRepository()->find($input->getArgument('cron'));

        $command = $job->getCommand();
        $watch = 'job-' . $command;

        $output->write("Running " . $job->getCommand() . ": ");

        $application = $this->getApplication();

        $jonInput = new StringInput($job->getFullCommand());
        $jobOutput = new BufferedOutput();

        $this->getStopWatch()->start($watch);

        try {
            $statusCode = $application->run($jonInput, $jobOutput);
        } catch (\Exception $ex) {
            $statusCode = 1;
            $jobOutput->writeln('');
            $jobOutput->writeln('Job execution failed with exception ' . get_class($ex) . ': ' . $ex->getMessage());
        }
        $this->getStopWatch()->stop($watch);

        if (is_null($statusCode)) {
            $statusCode = 0;
        }

        switch ($statusCode) {
            case 0:
                $statusStr = CronJobResultInterface::SUCCEEDED;
                break;
            case 2:
                $statusStr = CronJobResultInterface::SKIPPED;
                break;
            default:
                $statusStr = CronJobResultInterface::FAILED;
        }

        $duration = $this->getStopWatch()->getEvent($watch)->getDuration();
        $output->writeln($statusStr . ' in ' . number_format(($duration / 1000), 4) . ' seconds');

        // Record the result
        $this->recordJobResult($job, $duration, $jobOutput, $statusCode);
    }

    /**
     * @param CronJobInterface $job
     * @param                  $timeTaken
     * @param                  $output
     * @param                  $statusCode
     */
    protected function recordJobResult(CronJobInterface $job, $timeTaken, BufferedOutput $output, $statusCode)
    {
        $cronJobRepository = $this->getCronJobRepository();
        $cronJobResultManager = $this->getManager();

        /** @var CronJobInterface $job */
        $job = $cronJobRepository->find($job->getId());

        $className = $this->getCronJobResultRepository()->getClassName();

        $buffer = (!$output->isQuiet()) ? $output->fetch() : '';

        /** @var CronJobResultInterface $result */
        $result = new $className();
        $result->setCronJob($job);
        $result->setRunTime($timeTaken);
        $result->setOutput($buffer);
        $result->setStatusCode($statusCode);

        $cronJobResultManager->persist($result);
        $cronJobResultManager->flush();
    }

}
