<?php

namespace Database\Seeders;

use App\Enums\DeliveryType;
use App\Enums\ProductStatus;
use App\Enums\SellerProfileStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reproducible marketplace data for the hostile-conditions simulation:
 * 5 staff accounts (one per staff role, two-factor on), 10 approved
 * sellers, 50 buyers, 8 categories, 200 products with deliverable content
 * (clean-scanned files and/or licence keys) and the USD/EUR exchange rates.
 *
 * Usage, on an empty, migrated database of a disposable stack only; every
 * account shares the known password self::PASSWORD, so in an environment
 * named production it also needs SHOP_SIMULATION=allow-known-passwords:
 *
 *     php artisan db:seed --class=Database\\Seeders\\SimulationSeeder
 *
 * A test harness logs in as staff with the TOTP secrets in self::STAFF.
 * Product files are written to the "products" disk under sim/{product id}/
 * so they never overwrite files of other seeds; delete that folder to clean up.
 */
class SimulationSeeder extends Seeder
{
    public const PASSWORD = 'Sim-Password-2026';

    public const SEED = 20261009;

    /** Staff accounts with their fixed, development-only TOTP secrets (base32). */
    public const STAFF = [
        'superadmin@sim.test' => ['role' => 'admin', 'name' => 'Sim Super Admin', 'secret' => 'UJZDEGXDNCF32EPF3DHODZDOCIS2JHTL'],
        'manager@sim.test' => ['role' => 'manager', 'name' => 'Sim Manager', 'secret' => 'GMXGEDN73U55XTPLPFT7V4SEH2KVJ72C'],
        'finance@sim.test' => ['role' => 'finance', 'name' => 'Sim Finance', 'secret' => 'EUVW75EFR6EDT4SYWB5WKH7DNSIPZZ7F'],
        'moderator@sim.test' => ['role' => 'moderator', 'name' => 'Sim Moderator', 'secret' => 'K4ZRI3R2WYOJFLJOOA7LQSAJ2XUID5ZZ'],
        'support@sim.test' => ['role' => 'support', 'name' => 'Sim Support', 'secret' => 'ZZG6ZDMEN4KHVDGAJGXBENYJQWX6HH75'],
    ];

    /** slug => [name, description, commission_bps, number of products]. */
    private const CATEGORIES = [
        'software-tools' => ['Software tools', 'Desktop and command-line utilities for everyday work.', null, 40],
        'e-books' => ['E-books', 'Guides and handbooks in PDF and EPUB format.', null, 35],
        'licences' => ['Licences', 'Licence keys for desktop and web applications.', 1500, 30],
        'templates' => ['Templates', 'Ready-made documents, slide decks and website templates.', null, 30],
        'fonts' => ['Fonts', 'Typefaces for print and screen, with a desktop and web licence.', null, 20],
        'icons' => ['Icons', 'Icon sets in SVG and PNG for interfaces and presentations.', null, 20],
        'courses' => ['Online courses', 'Self-paced video courses with exercises.', 800, 15],
        'subscriptions' => ['Subscriptions', 'Time-limited access to libraries that grow every month.', null, 10],
    ];

    /** [name, display name, payout currency, payout crypto, preferred category slugs]. */
    private const SELLERS = [
        ['Olivia Hartmann', 'Northwind Software', 'USD', 'BTC', ['software-tools', 'licences']],
        ['Marcus Lindqvist', 'Blue Lantern Books', 'EUR', 'LTC', ['e-books', 'courses']],
        ['Priya Raman', 'Keystone Licensing', 'USD', 'BTC', ['licences', 'software-tools']],
        ['Daniel Okafor', 'Pixel Harbor Studio', 'USD', 'BTC', ['icons', 'templates']],
        ['Sofia Marchetti', 'Typeforge Foundry', 'EUR', 'BTC', ['fonts', 'subscriptions']],
        ['Ethan Brooks', 'Glyphworks', 'USD', 'LTC', ['icons', 'subscriptions']],
        ['Hannah Weber', 'Brightpath Academy', 'USD', 'BTC', ['courses', 'e-books']],
        ['Lucas Moreau', 'Cedar and Pine Templates', 'USD', 'LTC', ['templates', 'fonts']],
        ['Aiko Tanaka', 'Quartz Utilities', 'USD', 'BTC', ['software-tools', 'templates']],
        ['Mateo Alvarez', 'Monarch Digital', 'USD', 'LTC', ['licences', 'e-books']],
    ];

    private const FIRST_NAMES = ['James', 'Emma', 'Noah', 'Mia', 'Liam', 'Chloe', 'Lucas', 'Ava', 'Oliver', 'Isla', 'Leon', 'Nora', 'Samuel', 'Zoe', 'Felix', 'Clara', 'Arthur', 'Lena', 'Hugo', 'Maya', 'Elias', 'Freya', 'Jonas', 'Alice', 'Adam'];

    private const LAST_NAMES = ['Smith', 'Novak', 'Garcia', 'Keller', 'Jensen', 'Rossi', 'Dubois', 'Kowalski', 'Nguyen', 'Silva', 'Fischer', 'Brennan', 'Petrov', 'Haas', 'Moreno', 'Lindgren', 'Costa', 'Walsh', 'Becker', 'Sato'];

    /** @var array<string, list<list<string>>> word lists the titles are built from, per category */
    private const TITLE_WORDS = [
        'software-tools' => [
            ['Swift', 'Lean', 'Smart', 'Batch', 'Quick', 'Secure', 'Portable', 'Tiny', 'Precise', 'Silent'],
            ['Markdown', 'CSV', 'Log', 'Image', 'Backup', 'Invoice', 'Screenshot', 'Clipboard', 'DNS', 'JSON', 'PDF', 'Subtitle'],
            ['Converter', 'Toolkit', 'Analyzer', 'Manager', 'Utility', 'Inspector', 'Optimizer', 'Formatter', 'Sync', 'Renamer'],
        ],
        'e-books' => [
            ['A Practical Guide to', 'Mastering', 'The Complete Handbook of', 'Field Notes on', 'Getting Started with', 'Advanced', 'The Pocket Guide to'],
            ['PostgreSQL Indexing', 'Laravel Queues', 'Home Network Security', 'Personal Finance', 'Technical Writing', 'Sourdough Baking', 'Product Photography', 'Rust for Web Developers', 'Kubernetes Operations', 'Freelance Contracts', 'Indoor Gardening', 'Public Speaking'],
            ['', '(2nd Edition)', '(2026 Edition)', 'Workbook'],
        ],
        'licences' => [
            ['PhotoForge', 'CodeLens', 'Ledgerly', 'VaultKey', 'StreamCut', 'MailPilot', 'DiskSage', 'NoteHive', 'PixelPress', 'AudioNest', 'ScanWise'],
            ['Personal', 'Pro', 'Business', 'Lifetime', 'Single-Seat', 'Family', 'Studio', 'Team'],
            ['Licence Key', 'Licence', 'Activation Key'],
        ],
        'templates' => [
            ['Minimal', 'Modern', 'Corporate', 'Clean', 'Bold', 'Retro', 'Elegant', 'Playful'],
            ['Invoice', 'Resume', 'Pitch Deck', 'Landing Page', 'Newsletter', 'Business Plan', 'Portfolio', 'Dashboard UI', 'Wedding Invitation', 'Social Media Kit', 'Meeting Notes'],
            ['Template', 'Template Pack', 'Kit', 'Bundle'],
        ],
        'fonts' => [
            ['Halcyon', 'Brixton', 'Marlowe', 'Oberon', 'Calder', 'Northgate', 'Juniper', 'Solace', 'Vesper', 'Ardent', 'Kestrel'],
            ['Sans', 'Serif', 'Mono', 'Display', 'Slab', 'Script', 'Grotesk'],
            ['Font Family', 'Typeface', 'Font Pack'],
        ],
        'icons' => [
            ['120', '250', '400', '600', '1200'],
            ['Line', 'Duotone', 'Flat', 'Glyph', 'Outline', 'Hand-drawn', 'Isometric'],
            ['Business Icons', 'E-commerce Icons', 'Weather Icons', 'Medical Icons', 'Travel Icons', 'Finance Icons', 'UI Icons', 'Social Icons', 'Food Icons'],
        ],
        'courses' => [
            ['Python', 'Excel', 'Video Editing', 'UX Design', 'SQL', 'Digital Marketing', 'Guitar', 'Photography', 'Data Visualization', 'Spanish', 'Watercolor'],
            ['Fundamentals', 'Masterclass', 'Bootcamp', 'in 30 Days', 'for Beginners', 'Beyond the Basics'],
            ['', '- Video Course', '- Self-Paced'],
        ],
        'subscriptions' => [
            ['Stock Photo Library', 'Icon Club', 'Font Vault', 'Template Club', 'Premium Presets', 'Plugin Bundle', 'Sound Effects Library', 'Mockup Club'],
            [],
            ['Subscription', 'Membership', 'Access Pass'],
        ],
    ];

    private const PERIODS = [30 => 'Monthly', 90 => 'Quarterly', 180 => 'Half-Year', 365 => 'Yearly'];

    private const PITCH = [
        'software-tools' => ['saves you hours of repetitive work every week', 'runs on Windows, macOS and Linux without installation', 'handles thousands of files in a single batch'],
        'e-books' => ['explains the topic step by step with worked examples', 'is written for readers with no prior experience', 'collects the lessons of ten years of practice'],
        'licences' => ['unlocks every feature of the desktop application', 'includes one year of free updates', 'can be moved to a new computer at any time'],
        'templates' => ['is fully editable in Word, Google Docs and Figma', 'comes with print-ready and screen versions', 'uses free fonts only, so nothing extra to buy'],
        'fonts' => ['includes desktop, web and app licences', 'ships in OTF, TTF, WOFF and WOFF2 formats', 'supports Latin, Cyrillic and Greek scripts'],
        'icons' => ['is drawn on a pixel-perfect 24px grid', 'comes as SVG, PNG and an icon font', 'matches popular interface kits'],
        'courses' => ['contains more than six hours of video lessons', 'includes exercises and downloadable project files', 'is taught by a working professional'],
        'subscriptions' => ['adds new content every month', 'includes a commercial licence for all downloads', 'can be renewed at any time from your orders page'],
    ];

    private const CLOSING = [
        'Questions are answered by the seller through the support ticket system.',
        'Free updates are included for minor versions.',
        'Refunds are handled through the dispute process if the product does not work as described.',
        'Tested and reviewed before publication.',
    ];

    public function run(): void
    {
        // The simulation stack runs with APP_ENV=production on purpose, so it
        // opts in explicitly; a real production deployment never sets this.
        if (app()->isProduction() && env('SHOP_SIMULATION') !== 'allow-known-passwords') {
            $this->command?->error('Refusing to seed simulation data in production (set SHOP_SIMULATION=allow-known-passwords only on a disposable test stack).');

            return;
        }
        if (User::query()->where('email', 'superadmin@sim.test')->exists()) {
            $this->command?->error('Simulation data already present; run it on an empty, migrated database.');

            return;
        }

        mt_srand(self::SEED);
        fake()->seed(self::SEED);

        DB::transaction(function () {
            $hash = Hash::make(self::PASSWORD);
            $admin = $this->seedStaff($hash);
            $categories = $this->seedCategories();
            $sellers = $this->seedSellers($hash, $admin);
            $this->seedBuyers($hash);

            ExchangeRate::create(['base' => 'USD', 'quote' => 'EUR', 'rate' => '0.9200000000', 'updated_by' => $admin->id]);
            ExchangeRate::create(['base' => 'EUR', 'quote' => 'USD', 'rate' => '1.0870000000', 'updated_by' => $admin->id]);

            $this->seedProducts($categories, $sellers);
        });

        $this->command?->info('Simulation data seeded: 5 staff, 10 sellers, 50 buyers, 8 categories, 200 products.');
    }

    private function seedStaff(string $hash): User
    {
        $admin = null;
        foreach (self::STAFF as $email => $staff) {
            $user = User::factory()->staff(UserRole::from($staff['role']))->create([
                'name' => $staff['name'],
                'email' => $email,
                'password_hash' => $hash,
                'two_factor_secret' => $staff['secret'],
            ]);
            $admin ??= $user;
        }

        return $admin;
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $categories = [];
        foreach (self::CATEGORIES as $slug => [$name, $description, $commission]) {
            $categories[$slug] = Category::create([
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'commission_bps' => $commission,
            ]);
        }

        return $categories;
    }

    /** @return list<User> */
    private function seedSellers(string $hash, User $admin): array
    {
        $sellers = [];
        foreach (self::SELLERS as $i => [$name, $displayName, $currency, $crypto]) {
            $n = sprintf('%02d', $i + 1);
            $seller = User::factory()->seller()->create([
                'name' => $name,
                'email' => "seller{$n}@sim.test",
                'password_hash' => $hash,
            ]);
            SellerProfile::create([
                'user_id' => $seller->id,
                'display_name' => $displayName,
                'payout_currency' => $currency,
                'payout_crypto' => $crypto,
                'payout_address' => $this->payoutAddress($crypto, "seller{$n}"),
                'about' => "{$displayName} has been selling digital products since ".(2014 + $i).'.',
                'status' => SellerProfileStatus::Approved,
                'commission_bps' => $n === '03' ? 500 : null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);
            $sellers[] = $seller;
        }

        return $sellers;
    }

    /** A plausible bech32-looking address: bc1q/ltc1q followed by 38 characters of the bech32 alphabet. */
    private function payoutAddress(string $crypto, string $salt): string
    {
        $alphabet = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $digest = hash('sha512', "sim-payout-{$salt}", true);
        $body = '';
        for ($i = 0; $i < 38; $i++) {
            $body .= $alphabet[ord($digest[$i]) & 31];
        }

        return ($crypto === 'LTC' ? 'ltc1q' : 'bc1q').$body;
    }

    private function seedBuyers(string $hash): void
    {
        foreach (range(1, 50) as $i) {
            $n = sprintf('%02d', $i);
            $balance = $i % 5 === 0 ? 0 : mt_rand(1, 500) * 100;
            $currency = $i % 9 === 0 ? 'EUR' : 'USD';
            User::factory()->withBalance($balance, $currency)->create([
                'name' => self::FIRST_NAMES[($i - 1) % count(self::FIRST_NAMES)].' '.self::LAST_NAMES[($i * 7) % count(self::LAST_NAMES)],
                'email' => "buyer{$n}@sim.test",
                'password_hash' => $hash,
            ]);
        }
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  list<User>  $sellers
     */
    private function seedProducts(array $categories, array $sellers): void
    {
        $slots = [];
        foreach (self::CATEGORIES as $slug => $category) {
            array_push($slots, ...array_fill(0, $category[3], $slug));
        }
        $slots = $this->shuffled($slots);
        $count = count($slots);

        // Pick the special products up front so the counts are exact.
        $plain = array_keys(array_filter($slots, fn ($slug) => ! in_array($slug, ['licences', 'subscriptions'], true)));
        $plain = $this->shuffled($plain);
        $outOfStock = array_splice($plain, 0, 3);
        $limited = array_splice($plain, 0, 8);
        $manual = array_splice($plain, 0, 13);
        $subscriptionIdx = $this->shuffled(array_keys(array_filter($slots, fn ($slug) => $slug === 'subscriptions')));
        $manual = [...$manual, ...array_slice($subscriptionIdx, 0, 2)];
        $licenceIdx = $this->shuffled(array_keys(array_filter($slots, fn ($slug) => $slug === 'licences')));
        $licenceWithFile = array_slice($licenceIdx, 0, 2);
        $statusPool = $this->shuffled(array_values(array_diff(range(0, $count - 1), $outOfStock)));
        $pending = array_slice($statusPool, 0, 10);
        $disabled = array_slice($statusPool, 10, 5);

        $affinity = [];
        foreach (self::SELLERS as $i => $seller) {
            foreach ($seller[4] as $slug) {
                $affinity[$slug][] = $i;
            }
        }

        $titles = [];
        $keyCounter = 0;
        foreach ($slots as $i => $slug) {
            $accessDays = $slug === 'subscriptions' ? array_keys(self::PERIODS)[mt_rand(0, 3)] : null;
            $title = $this->uniqueTitle($slug, $accessDays, $titles);
            $sellerIdx = mt_rand(1, 100) <= 75 ? $affinity[$slug][mt_rand(0, count($affinity[$slug]) - 1)] : mt_rand(0, 9);
            $seller = $sellers[$sellerIdx];
            $currency = self::SELLERS[$sellerIdx][2];
            if (mt_rand(1, 100) <= 10) {
                $currency = $currency === 'USD' ? 'EUR' : 'USD';
            }
            $isManual = in_array($i, $manual, true);
            $usesKeys = $slug === 'licences' || in_array($i, $limited, true);
            $hasFile = ! $isManual && ($slug !== 'licences' || in_array($i, $licenceWithFile, true));
            $keyCount = match (true) {
                $slug === 'licences' => mt_rand(5, 20),
                in_array($i, $limited, true) => mt_rand(1, 5),
                default => 0,
            };
            $stock = match (true) {
                in_array($i, $outOfStock, true) => 0,
                $usesKeys => $keyCount,
                default => null,
            };
            $status = match (true) {
                in_array($i, $pending, true) => ProductStatus::PendingReview,
                in_array($i, $disabled, true) => ProductStatus::Disabled,
                default => ProductStatus::Active,
            };

            $product = Product::create([
                'seller_id' => $seller->id,
                'category_id' => $categories[$slug]->id,
                'slug' => Str::slug($title),
                'title' => $title,
                'description' => $this->description($slug, $title, $isManual, $usesKeys, $accessDays),
                'price_minor' => mt_rand(1, 99) * 100 + (mt_rand(1, 4) === 1 ? 0 : 99),
                'currency' => $currency,
                'stock' => $stock,
                'access_days' => $accessDays,
                'delivery_type' => $isManual ? DeliveryType::Manual : DeliveryType::Instant,
                'status' => $status,
            ]);

            if ($hasFile) {
                $this->attachFile($product, $slug);
            }
            if ($keyCount > 0) {
                $keys = [];
                for ($k = 0; $k < $keyCount; $k++) {
                    $key = $this->licenceKey(++$keyCounter);
                    $keys[] = [
                        'key_encrypted' => $key,
                        'key_fingerprint' => hash_hmac('sha256', $key, (string) config('app.key')),
                    ];
                }
                $product->licenseKeys()->createMany($keys);
            }
        }
    }

    /** @param  array<string, true>  $taken */
    private function uniqueTitle(string $slug, ?int $accessDays, array &$taken): string
    {
        [$first, $second, $third] = self::TITLE_WORDS[$slug];
        for ($attempt = 0; ; $attempt++) {
            $parts = [$this->pick($first)];
            $parts[] = $slug === 'subscriptions' ? self::PERIODS[$accessDays] : $this->pick($second);
            $parts[] = $this->pick($third);
            $title = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
            if ($attempt > 20) {
                $title .= ' Vol. '.($attempt - 19);
            }
            if (! isset($taken[$title]) && ! isset($taken['slug:'.Str::slug($title)])) {
                $taken[$title] = true;
                $taken['slug:'.Str::slug($title)] = true;

                return $title;
            }
        }
    }

    private function description(string $slug, string $title, bool $manual, bool $keys, ?int $accessDays): string
    {
        $pitch = $this->shuffled(self::PITCH[$slug]);
        $text = "{$title} {$pitch[0]}. It also {$pitch[1]}.";
        $text .= match (true) {
            $manual => ' The seller delivers this product by hand, usually within 24 hours of payment.',
            $keys => ' Your licence key is shown on the order page right after payment.',
            default => ' The download is available on the order page right after payment.',
        };
        if ($accessDays !== null) {
            $text .= " Access lasts {$accessDays} days from delivery.";
        }

        return $text.' '.$this->pick(self::CLOSING);
    }

    private function attachFile(Product $product, string $slug): void
    {
        $name = Str::slug($product->title).'-'.($slug === 'licences' ? 'install-guide' : 'readme').'.txt';
        $path = 'sim/'.$product->id.'/'.$name;
        $contents = "{$product->title}\n\nSimulated deliverable for product #{$product->id} ({$slug}).\n".str_repeat('Lorem ipsum dolor sit amet. ', mt_rand(2, 40))."\n";
        Storage::disk('products')->put($path, $contents);
        $product->files()->create([
            'original_name' => $name,
            'storage_path' => $path,
            'checksum' => hash('sha256', $contents),
            'size' => strlen($contents),
            'scan_status' => 'clean',
            'scanned_at' => now(),
        ]);
    }

    /** Deterministic, unique keys in the common XXXXX-XXXXX-XXXXX-XXXXX shape. */
    private function licenceKey(int $n): string
    {
        return implode('-', str_split(strtoupper(substr(hash('sha256', 'sim-licence-'.$n), 0, 20)), 5));
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * Fisher-Yates shuffle on the seeded mt_rand, so the order is reproducible.
     *
     * @template T
     *
     * @param  array<T>  $items
     * @return list<T>
     */
    private function shuffled(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
