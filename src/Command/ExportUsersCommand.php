<?php

namespace App\Command;

use App\DataFixtures\AppFixtures;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:export-users', description: 'Export seeded users as CSV data sources for the VoltTest load test (per-scenario shards + a flash-sale shard)')]
final class ExportUsersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProductRepository $products,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Output CSV path', 'loadtest/data/users.csv')
            ->addOption('shards', null, InputOption::VALUE_REQUIRED, 'Also write N disjoint shard files (users-1.csv ...) so each scenario gets its own accounts', 3);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        // Use the ids that actually exist: sequences keep counting after a
        // fixtures reload, so products are not necessarily 1..N.
        $productIds = array_map('intval', $this->products->createQueryBuilder('p')
            ->select('p.id')->getQuery()->getSingleColumnResult());
        if ($productIds === []) {
            $io->error('No products found; load fixtures first.');

            return Command::FAILURE;
        }

        $flashSale = $this->products->findOneBy(['slug' => AppFixtures::FLASH_SALE_SLUG]);
        if ($flashSale === null) {
            $io->error('Flash-sale product missing; run "app:flash-sale reset" or reload fixtures.');

            return Command::FAILURE;
        }
        $productIds = array_values(array_diff($productIds, [$flashSale->getId()]));

        $shards = max(1, (int) $input->getOption('shards'));
        $header = ['email', 'password', 'product_id', 'quantity'];

        $all = fopen($path, 'w');
        fputcsv($all, $header, escape: '');

        $shardFiles = [];
        for ($i = 1; $i <= $shards; ++$i) {
            $shardFiles[$i] = fopen(preg_replace('/\.csv$/', "-$i.csv", $path), 'w');
            fputcsv($shardFiles[$i], $header, escape: '');
        }
        // The last chunk of users is reserved for the flash sale: same product, quantity 1.
        $flashFile = fopen(preg_replace('/\.csv$/', '-flash-sale.csv', $path), 'w');
        fputcsv($flashFile, $header, escape: '');
        $chunks = $shards + 1;

        mt_srand(7);
        $count = 0;
        $emails = $this->users->createQueryBuilder('u')
            ->select('u.email')->orderBy('u.id', 'ASC')
            ->getQuery()->getSingleColumnResult();

        foreach ($emails as $i => $email) {
            $chunk = $i % $chunks;
            if ($chunk === $shards) {
                $row = [$email, AppFixtures::PASSWORD, $flashSale->getId(), 1];
                fputcsv($flashFile, $row, escape: '');
            } else {
                // Spread traffic across the catalog instead of hammering product #1.
                $row = [
                    $email,
                    AppFixtures::PASSWORD,
                    $productIds[mt_rand(0, count($productIds) - 1)],
                    mt_rand(1, 3),
                ];
                fputcsv($shardFiles[$chunk + 1], $row, escape: '');
            }
            fputcsv($all, $row, escape: '');
            ++$count;
        }
        fclose($all);
        fclose($flashFile);
        foreach ($shardFiles as $fh) {
            fclose($fh);
        }

        $io->success(sprintf('Wrote %d users to %s: %d disjoint shards + 1 flash-sale shard of ~%d rows each', $count, $path, $shards, intdiv($count, $chunks)));

        return Command::SUCCESS;
    }
}
