<?php

namespace App\DataFixtures;

use App\Entity\Product;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds a deterministic catalog and user base for load testing.
 *
 * Every user shares the same password (see PASSWORD) so CSV data sources can
 * be generated from the database (bin/console app:export-users). The pool is
 * large on purpose: each load-test scenario gets its own disjoint shard, and a
 * shard must hold more rows than the scenario's concurrent virtual users or
 * the same account ends up in two flows at once.
 */
class AppFixtures extends Fixture
{
    public const USER_COUNT = 30000;
    public const PRODUCT_COUNT = 200;
    public const PASSWORD = 'password';

    /** One deliberately scarce product every "flash sale" virtual user fights over. */
    public const FLASH_SALE_SLUG = 'flash-sale-studio-headphones';
    public const FLASH_SALE_STOCK = 500;

    private const CATEGORIES = ['Audio', 'Cameras', 'Computers', 'Gaming', 'Home', 'Wearables', 'Networking', 'Storage'];
    private const ADJECTIVES = ['Compact', 'Pro', 'Ultra', 'Wireless', 'Smart', 'Classic', 'Portable', 'Studio', 'Mini', 'Max'];
    private const NOUNS = ['Speaker', 'Headphones', 'Camera', 'Keyboard', 'Monitor', 'Router', 'Drive', 'Watch', 'Lamp', 'Charger'];

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(42);

        for ($i = 1; $i <= self::PRODUCT_COUNT; ++$i) {
            $name = sprintf('%s %s %d', self::ADJECTIVES[$i % 10], self::NOUNS[intdiv($i, 10) % 10], $i);
            $manager->persist(new Product(
                $name,
                strtolower(str_replace(' ', '-', $name)),
                sprintf('The %s is built for everyday use. Ships in 2 days.', $name),
                mt_rand(999, 49999),
                100_000,
                self::CATEGORIES[$i % count(self::CATEGORIES)],
            ));
        }

        $manager->persist(self::flashSaleProduct());

        // Hash once; hashing 1000 passwords at production cost would take minutes.
        $hash = $this->hasher->hashPassword(new User('seed@example.com', 'seed'), self::PASSWORD);

        for ($i = 1; $i <= self::USER_COUNT; ++$i) {
            $user = new User(sprintf('user%04d@example.com', $i), sprintf('Load Tester %d', $i));
            $user->setPassword($hash);
            $manager->persist($user);

            if ($i % 500 === 0) {
                $manager->flush();
                $manager->clear(User::class); // keep memory flat over 30k rows
            }
        }

        $manager->flush();
    }

    public static function flashSaleProduct(): Product
    {
        return new Product(
            'Flash Sale: Studio Headphones',
            self::FLASH_SALE_SLUG,
            sprintf('Only %d units. First come, first served.', self::FLASH_SALE_STOCK),
            4900,
            self::FLASH_SALE_STOCK,
            'Audio',
        );
    }
}
