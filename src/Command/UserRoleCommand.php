<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:user-role',
    description: 'Aggiunge o revoca ruoli a un utente esistente.',
)]
final class UserRoleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email dell\'utente')
            ->addOption(
                'add',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Ruolo da aggiungere (ripetibile, es. --add=ROLE_ADMIN)'
            )
            ->addOption(
                'remove',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Ruolo da revocare (ripetibile, es. --remove=ROLE_ADMIN)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $addRoles = $this->normalizeRoles((array) $input->getOption('add'));
        $removeRoles = $this->normalizeRoles((array) $input->getOption('remove'));

        if ($addRoles === [] && $removeRoles === []) {
            $output->writeln('<error>Specifica almeno --add o --remove.</error>');
            return Command::INVALID;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            $output->writeln(sprintf('<error>Nessun utente trovato con email %s</error>', $email));
            return Command::FAILURE;
        }

        // Evita di rimuovere ROLE_USER (aggiunto sempre di default).
        if (in_array('ROLE_USER', $removeRoles, true)) {
            $output->writeln('<comment>Ignoro la richiesta di rimuovere ROLE_USER: è il ruolo base.</comment>');
            $removeRoles = array_values(array_filter($removeRoles, static fn (string $r) => $r !== 'ROLE_USER'));
        }

        $current = $user->getRoles();

        // Applica revoche.
        if ($removeRoles !== []) {
            $current = array_values(array_diff($current, $removeRoles));
        }

        // Applica aggiunte.
        if ($addRoles !== []) {
            $current = array_values(array_unique(array_merge($current, $addRoles)));
        }

        // Garantisce il ruolo base.
        if (!in_array('ROLE_USER', $current, true)) {
            $current[] = 'ROLE_USER';
        }

        $user->setRoles($current);
        $this->em->flush();

        $output->writeln(sprintf(
            '<info>Ruoli aggiornati per %s:</info> [%s]',
            $email,
            implode(', ', $user->getRoles())
        ));

        return Command::SUCCESS;
    }

    /**
     * @param string[] $roles
     * @return string[]
     */
    private function normalizeRoles(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $trimmed = strtoupper(trim((string) $role));
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }
}
