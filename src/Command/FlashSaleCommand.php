<?php

namespace App\Command;

use App\DataFixtures\AppFixtures;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `reset`  puts the flash-sale product back to its initial stock (creating it if
 *          missing) and empties it from every cart, so a run is repeatable.
 * `report` prints what a run did to it and fails if stock went negative or
 *          more units were sold than existed: the oversell check the load test
 *          itself cannot express with a single expected status code.
 */
#[AsCommand(name: 'app:flash-sale', description: 'Reset or report the flash-sale product used by the oversell load-test scenario')]
final class FlashSaleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, '"reset" or "report"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        return match ($input->getArgument('action')) {
            'reset' => $this->reset($io),
            'report' => $this->report($io),
            default => (function () use ($io) {
                $io->error('Action must be "reset" or "report"');

                return Command::INVALID;
            })(),
        };
    }

    private function reset(SymfonyStyle $io): int
    {
        $product = $this->products->findOneBy(['slug' => AppFixtures::FLASH_SALE_SLUG]);
        if ($product === null) {
            $product = AppFixtures::flashSaleProduct();
            $this->em->persist($product);
            $this->em->flush();
        }

        $this->db->executeStatement('DELETE FROM cart_item WHERE product_id = :id', ['id' => $product->getId()]);
        $this->db->executeStatement('UPDATE product SET stock = :stock WHERE id = :id', [
            'stock' => AppFixtures::FLASH_SALE_STOCK,
            'id' => $product->getId(),
        ]);

        $io->success(sprintf('Flash-sale product #%d "%s" reset to %d units; removed from all carts.',
            $product->getId(), $product->getName(), AppFixtures::FLASH_SALE_STOCK));

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io): int
    {
        $product = $this->products->findOneBy(['slug' => AppFixtures::FLASH_SALE_SLUG]);
        if ($product === null) {
            $io->error('Flash-sale product missing; run "app:flash-sale reset".');

            return Command::FAILURE;
        }

        $row = $this->db->fetchAssociative(
            'SELECT p.stock,
                    (SELECT count(*)              FROM order_item oi WHERE oi.product_id = p.id) AS orders,
                    (SELECT coalesce(sum(oi.quantity), 0) FROM order_item oi WHERE oi.product_id = p.id) AS units_sold,
                    (SELECT coalesce(sum(ci.quantity), 0) FROM cart_item ci WHERE ci.product_id = p.id) AS still_in_carts
             FROM product p WHERE p.id = :id',
            ['id' => $product->getId()]
        );

        $io->table(['metric', 'value'], [
            ['product id', $product->getId()],
            ['initial stock', AppFixtures::FLASH_SALE_STOCK],
            ['stock now', $row['stock']],
            ['units sold since last reset', AppFixtures::FLASH_SALE_STOCK - (int) $row['stock']],
            ['orders containing it (all time)', $row['orders']],
            ['units sold (all time)', $row['units_sold']],
            ['units still sitting in carts', $row['still_in_carts']],
        ]);

        if ((int) $row['stock'] < 0) {
            $io->error('OVERSOLD: stock is negative.');

            return Command::FAILURE;
        }
        if ((int) $row['stock'] + (int) $row['units_sold'] < AppFixtures::FLASH_SALE_STOCK) {
            $io->warning('stock + units sold is below the initial stock; the product was reset between runs, so "units sold" spans several runs.');
        }
        $io->success((int) $row['stock'] === 0 ? 'Sold out, never oversold.' : 'Not oversold.');

        return Command::SUCCESS;
    }
}
