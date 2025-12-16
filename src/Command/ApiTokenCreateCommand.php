<?php

namespace App\Command;

use App\Entity\ApiToken;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:api-token:create',
    description: 'Genera un token API per un utente esistente.',
)]
final class ApiTokenCreateCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email dell\'utente a cui assegnare il token')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Durata in ore del token', 24 * 365)
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Etichetta descrittiva del token', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $ttl = (int) $input->getOption('ttl');
        $label = $input->getOption('label');

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            $output->writeln(sprintf('<error>Nessun utente con email %s</error>', $email));
            return Command::FAILURE;
        }

        $tokenValue = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $tokenValue);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', max(1, $ttl)));

        $apiToken = new ApiToken($user, $tokenHash, $expiresAt, is_string($label) && $label !== '' ? $label : null);
        $this->em->persist($apiToken);
        $this->em->flush();

        $output->writeln('<info>Token generato con successo:</info>');
        $output->writeln(sprintf('  Utente: %s', $email));
        $output->writeln(sprintf('  Token: %s', $tokenValue));
        $output->writeln(sprintf('  Scade: %s', $expiresAt->format('Y-m-d H:i')));
        if ($label) {
            $output->writeln(sprintf('  Etichetta: %s', $label));
        }

        return Command::SUCCESS;
    }
}
