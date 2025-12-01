<?php

namespace App\Command;

use App\Entity\DocumentFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:unindex-file')]
class UnindexFileCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rimuove uno o più file dalla tabella document_file')
            ->addArgument(
                'pattern',
                InputArgument::REQUIRED,
                'Percorso ESATTO o pattern regex. Esempi:
                 "manuali/trast.md"
                 "^manuali/"
                 "\\\\.pdf$"
                 ".*"'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pattern = $input->getArgument('pattern');

        $qb = $this->em->createQueryBuilder()
            ->select('f')
            ->from(DocumentFile::class, 'f');

        /** @var DocumentFile[] $files */
        $files = $qb->getQuery()->getResult();

        $matched = [];

        foreach ($files as $file) {
            if (preg_match('/'.$pattern.'/', $file->getPath())) {
                $matched[] = $file;
            }
        }

        if (!$matched) {
            $output->writeln('<comment>Nessun file trovato per il pattern.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln("<info>Trovati ".count($matched)." file da rimuovere:</info>");

        foreach ($matched as $file) {
            $output->writeln("  → ".$file->getPath());
            $this->em->remove($file);
        }

        $this->em->flush();

        $output->writeln("<info>Rimozione completata.</info>");

        return Command::SUCCESS;
    }
}
