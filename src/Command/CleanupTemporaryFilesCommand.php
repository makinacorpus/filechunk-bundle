<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Command;

use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use Psr\Log\LogLevel;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Deletes outdated temporary files.
 *
 * Warning, if for some reason, a folder was created a few days ago but user
 * session is still active, we might end up deleting folders where files are
 * still being uploaded. Only side effect is that the download should probably
 * just restart from the begining on the frontend side.
 */
final class CleanupTemporaryFilesCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private FileSessionHandler $sessionHandler;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('filechunk:cleanup');
        $this->setDescription("Deletes outdated temporary files");
        $this->setDefinition([
            new InputOption('dry-run', 'd', InputOption::VALUE_NONE, "Do not delete files, just output outdated file list."),
        ]);
    }

    /**
     * Set file session handler
     */
    public function setFileSessionHandler(FileSessionHandler $sessionHandler): void
    {
        $this->sessionHandler = $sessionHandler;
    }

    /**
     * Log message.
     */
    private function log(OutputInterface $output, string $message, $level = LogLevel::NOTICE): void
    {
        switch ($level) {
            case LogLevel::DEBUG:
                $output->writeln($message, OutputInterface::VERBOSITY_VERY_VERBOSE);
                break;

            case LogLevel::NOTICE:
            case LogLevel::INFO:
                if ($this->logger) {
                    $this->logger->log($level, $message);
                }
                $output->writeln($message, OutputInterface::VERBOSITY_NORMAL);
                break;

            default:
                if ($this->logger) {
                    $this->logger->log($level, $message);
                }
                $output->writeln($message, OutputInterface::VERBOSITY_NORMAL);
                break;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->sessionHandler) {
            throw new \Exception("Session handler is not set");
        }

        // Default file delta => 2 days
        $threshold = \time() - 2 * 24 * 3600;
        $directory = $this->sessionHandler->getUploadDirectory();

        if (!$directory) {
            throw new \Exception("What");
        }

        if ($dryrun = (bool)$input->getOption('dry-run')) {
            $this->log($output, 'Working in dry run (not removing files)');
        }
        $this->log($output, \sprintf("Working in '%s' folder", $directory));

        $found = 0;
        $removed = 0;
        $errors = 0;

        /** @var \SplFileInfo $file */
        foreach (new \DirectoryIterator($directory) as $file) {
            $filename = $file->getFilename();

            if ($file->isDir() && '.' !== $filename && '..' !== $filename) {
                $found++;

                if (($reference = $file->getCTime()) < $threshold) {

                    if ($dryrun || $output->isVeryVerbose()) {
                        $this->log($output, \sprintf("File '%s' will be deleted (latest of ctime/mtime is %d)", $file->getPathname(), $reference));
                    }

                    if (!$dryrun) {
                        try {
                            if ('\\' === DIRECTORY_SEPARATOR) {
                                (new Filesystem())->remove($file->getPathname());
                            } else {
                                \shell_exec('rm -rf '.\escapeshellarg($file->getPathname()));
                            }
                            $removed++;
                        } catch (IOExceptionInterface $e) {
                            /** @var \Exception $e */
                            $this->log($output, $e->getMessage());
                            $errors++;
                        }
                    } else {
                        $removed++;
                    }
                }
            }
        }

        $this->log($output, \sprintf("%d found, %d removed, %d errors", $found, $removed, $errors));

        return self::SUCCESS;
    }
}
