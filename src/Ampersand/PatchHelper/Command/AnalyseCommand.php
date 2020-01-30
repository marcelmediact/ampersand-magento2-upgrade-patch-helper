<?php
namespace Ampersand\PatchHelper\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Ampersand\PatchHelper\Helper;
use Ampersand\PatchHelper\Patchfile;

class AnalyseCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('analyse')
            ->addArgument('project', InputArgument::REQUIRED, 'The path to the magento2 project')
            ->addOption('sort-by-type', null, InputOption::VALUE_NONE, 'Sort the output by override type')
            ->setDescription('Analyse a magento2 project which has had a ./vendor.patch file manually created');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectDir = $input->getArgument('project');
        if (!(is_string($projectDir) && is_dir($projectDir))) {
            throw new \Exception("Invalid project directory specified");
        }

        $patchDiffFilePath = $projectDir . DIRECTORY_SEPARATOR . 'vendor.patch';
        if (!(is_string($patchDiffFilePath) && is_file($patchDiffFilePath))) {
            throw new \Exception("$patchDiffFilePath does not exist, see README.md");
        }

        $magento2 = new Helper\Magento2Instance($projectDir);
        $output->writeln('<info>Magento has been instantiated</info>', OutputInterface::VERBOSITY_VERBOSE);
        $patchFile = new Patchfile\Reader($patchDiffFilePath);
        $output->writeln('<info>Patch file has been parsed</info>', OutputInterface::VERBOSITY_VERBOSE);

        $summaryOutputData = [];
        $patchFilesToOutput = [];
        $patchFiles = $patchFile->getFiles();
        if (empty($patchFiles)) {
            $output->writeln("<error>The patch file could not be parsed, are you sure its a unified diff? </error>");
            return 1;
        }
        foreach ($patchFiles as $patchFile) {
            $file = $patchFile->getPath();
            try {
                $patchOverrideValidator = new Helper\PatchOverrideValidator($magento2, $patchFile);
                if (!$patchOverrideValidator->canValidate()) {
                    $output->writeln("<info>Skipping $file</info>", OutputInterface::VERBOSITY_VERY_VERBOSE);
                    continue;
                }

                $output->writeln("<info>Validating $file</info>", OutputInterface::VERBOSITY_VERBOSE);

                foreach ($patchOverrideValidator->validate()->getErrors() as $errorType => $errors) {
                    if (!isset($patchFilesToOutput[$file])) {
                        $patchFilesToOutput[$file] = $patchFile;
                    }
                    foreach ($errors as $error) {
                        $summaryOutputData[] = [$errorType, $file, ltrim(str_replace($projectDir, '', $error), '/')];
                    }
                }
            } catch (\InvalidArgumentException $e) {
                $output->writeln("<error>Could not understand $file: {$e->getMessage()}</error>", OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
        }

        if ($input->getOption('sort-by-type')) {
            usort($summaryOutputData, function ($a, $b) {
                if (strcmp($a[0], $b[0]) !== 0) {
                    return strcmp($a[0], $b[0]);
                }
                if (strcmp($a[1], $b[1]) !== 0) {
                    return strcmp($a[1], $b[1]);
                }
                return strcmp($a[2], $b[2]);
            });
        }

        $outputTable = new Table($output);
        $outputTable->setHeaders(['Type', 'Core', 'To Check']);
        $outputTable->addRows($summaryOutputData);
        $outputTable->render();

        $autoPatched = [];
        $manualChecks = [];
        $messages =  [];
        foreach ($summaryOutputData as $fileToPatch) {
            if ($fileToPatch[0] === 'Override (phtml/js/html)') {
                $fileToPatch[1] = rtrim($projectDir, '/') . '/' . $fileToPatch[1];
                $patchFile = rtrim($projectDir, '/'). '/patches/' . md5(str_replace('/', '_', $fileToPatch[1])) . '.patch';
                xdiff_file_diff(str_replace('vendor/', 'vendor_orig/', $fileToPatch[1]), $fileToPatch[1], $patchFile);
                $patching = rtrim($projectDir, '/') . '/' . $fileToPatch[2];
                $result = xdiff_file_patch($patching, $patchFile, $patching . '.patched');
                if (is_string($result)) {
                    $messages[$fileToPatch[1]] = $result;
                }

                if ($result === true) {
                    $unpatchedFile = file_get_contents($patching);
                    $patchedFile = file_get_contents($patching . '.patched');
                    if ($unpatchedFile !== $patchedFile) {
                        unlink($patching);
                        unlink($patchFile);
                        rename($patching. '.patched', $patching);
                        $autoPatched[] = [$fileToPatch[2]];
                    } else {
                        rename($patchFile, $patching . '.patch');
                        unlink($patching . '.patched');
                        $manualChecks[] = $fileToPatch;
                    }
                } else {
                    $manualChecks[] = $fileToPatch;
                }
            } else {
                $manualChecks[] = $fileToPatch;
            }
        }

        $outputTable = new Table($output);
        $outputTable->setHeaders(['Automatically patched files']);
        $outputTable->addRows($autoPatched);
        $outputTable->render();

        $outputTable = new Table($output);
        $outputTable->setHeaders(['Leftovers! Type', 'Core', 'To Check']);
        $outputTable->addRows($manualChecks);
        $outputTable->render();

        if (count($messages) > 1) {
            $output->writeln('Some patches were partially applied and had errors:');
            foreach ($messages as $file => $message) {
                $output->writeln(sprintf('File: %s', $file));
                $output->writeln(sprintf('Message: %s', $message));
                $output->writeln('--------------------------------------');
            }
        }

        $countToCheck = count($summaryOutputData);
        $newPatchFilePath = rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor_files_to_check.patch';
        $output->writeln("<comment>You should review the above $countToCheck items alongside $newPatchFilePath</comment>");
        file_put_contents($newPatchFilePath, implode(PHP_EOL, $patchFilesToOutput));
    }
}
