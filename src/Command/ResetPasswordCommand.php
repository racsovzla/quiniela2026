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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:reset-password', description: 'Reset password for an existing user')]
class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'New plain-text password')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Also mark user as verified');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $plainPassword = (string) $input->getArgument('password');

        $user = $this->userRepository->findByEmail($email);
        if (!$user instanceof User) {
            $io->error(sprintf('User "%s" not found.', $email));

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        if ($input->getOption('verify')) {
            $user->setIsVerified(true);
            $user->setEmailVerificationCode(null);
            $user->setEmailVerificationExpiresAt(null);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Password reset for %s (verified=%s, approved=%s)',
            $email,
            $user->isVerified() ? 'yes' : 'no',
            $user->isApproved() ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }
}
