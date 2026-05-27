<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create first admin user')]
class CreateAdminCommand extends Command
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
            ->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $plainPassword = (string) $input->getArgument('password');

        if ($this->userRepository->findByEmail($email) instanceof User) {
            $io->error('Email already exists.');

            return Command::FAILURE;
        }

        if (!$this->isStrongPassword($plainPassword)) {
            $io->error('Password must be at least 12 chars and include uppercase, lowercase, number, and symbol.');

            return Command::FAILURE;
        }

        $user = (new User())
            ->setName((string) $input->getArgument('name'))
            ->setEmail($email)
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true)
            ->setIsApproved(true)
            ->setPaymentValidatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('Admin created.');

        return Command::SUCCESS;
    }

    private function isStrongPassword(string $password): bool
    {
        if (strlen($password) < 12) {
            return false;
        }

        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).+$/', $password) === 1;
    }
}
