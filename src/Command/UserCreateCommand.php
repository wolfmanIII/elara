<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\Annotation\Ignore;

#[AsCommand(
    name: 'app:user-create',
    description: 'Crea un utente con ruolo e password.',
)]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email dell\'utente')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Password in chiaro (se non specificata verrà richiesto)')
            ->addOption(
                'role',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Ruolo da assegnare (ripetibile, es. --role=ROLE_ADMIN)',
                ['ROLE_USER']
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $password = $input->getOption('password');
        $roles = (array) $input->getOption('role');

        if ($this->userRepository->findOneBy(['email' => $email])) {
            $output->writeln(sprintf('<error>Esiste già un utente con email %s</error>', $email));
            return Command::FAILURE;
        }

        if ($password === null) {
            $question = new Question('Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $password = $helper->ask($input, $output, $question);
        }

        if (!$password) {
            $output->writeln('<error>Password non valida.</error>');
            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        // l'opzione --role è ripetibile: puoi passare più ruoli specificando l'opzione più volte
        $user->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln(sprintf('<info>Creato utente %s con ruoli [%s]</info>', $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
