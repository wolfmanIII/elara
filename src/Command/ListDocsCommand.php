<?php

namespace App\Command;

use App\Entity\DocumentFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:list-docs',
    description: 'Mostra l\'elenco dei documenti indicizzati (DocumentFile) con numero di chunk.'
)]
class ListDocsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Filtro sul path (substring case-insensitive).'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Numero massimo di risultati da mostrare (default: 50).',
                50
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pathFilter = $input->getOption('path');
        $limit      = (int) $input->getOption('limit');

        $repo = $this->em->getRepository(DocumentFile::class);

        $qb = $repo->createQueryBuilder('f')
            ->orderBy('f.path', 'ASC');

        if ($pathFilter) {
            $qb->andWhere('LOWER(f.path) LIKE :p')
               ->setParameter('p', '%'.mb_strtolower($pathFilter).'%');
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var DocumentFile[] $files */
        $files = $qb->getQuery()->getResult();

        if (!$files) {
            $output->writeln('<comment>Nessun documento trovato.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Path', 'Ext', 'Hash', 'Indexed At', 'Chunks']);

        foreach ($files as $file) {
            $hash = $file->getHash() ?? '';
            if ($hash !== '') {
                $hash = substr($hash, 0, 10) . '…';
            }

            $indexedAt = $file->getIndexedAt();
            $indexedStr = $indexedAt
                ? $indexedAt->format('Y-m-d H:i')
                : '-';

            // numero chunk tramite relazione
            $chunksCount = $file->getChunks()->count();

            $table->addRow([
                $file->getId(),
                $file->getPath(),
                $file->getExtension(),
                $hash,
                $indexedStr,
                $chunksCount,
            ]);
        }

        $table->render();

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Totale mostrato:</info> %d documenti%s',
            count($files),
            $pathFilter ? " (filtro path: \"$pathFilter\")" : ''
        ));

        return Command::SUCCESS;
    }
}
