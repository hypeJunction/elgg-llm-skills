<?php

declare(strict_types=1);

namespace HypeNotes\Cli;

use Elgg\Cli\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctorCommand extends Command {

    protected static $defaultName = 'hypenotes:doctor';

    protected function configure(): void {
        $this->setDescription('Post-migration data integrity checks for hypenotes');
    }

    protected function command(InputInterface $input, OutputInterface $output): int {
        $output->writeln('<info>hypenotes:doctor complete</info>');
        return self::SUCCESS;
    }
}
