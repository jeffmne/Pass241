<?php

/** Test reel multiprocessus PostgreSQL, hors transaction RefreshDatabase. */
use App\Actions\Orders\CreateOrder;
use App\Enums\PaymentProvider;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TicketType;
use App\Services\Ticketing\StockLedger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || ! str_ends_with((string) DB::connection()->getDatabaseName(), '_testing')
    || config('payments.default') !== 'fake' || ! function_exists('pcntl_fork')) {
    fwrite(STDERR, "Requiert APP_ENV=testing, PostgreSQL *_testing, paiement fake et pcntl.\n");
    exit(1);
}

$buyers = min(100, max(2, (int) ($argv[1] ?? 20)));
$stock = max(1, intdiv($buyers, 2));
Queue::fake();
$organization = Organization::factory()->create(['name' => 'Test concurrence '.bin2hex(random_bytes(4))]);
[$event, $types] = app(OrganizationContext::class)->runFor($organization, function () use ($stock) {
    $event = Event::factory()->published()->create();
    $types = TicketType::factory()->count(2)->for($event)->create([
        'quantity' => $stock, 'max_per_order' => 1, 'is_active' => true, 'is_visible' => true,
        'sales_start_at' => null, 'sales_end_at' => null,
    ]);

    return [$event, $types];
});
$ids = $types->modelKeys();
$event->load('organization');
DB::disconnect(); // Aucun socket PostgreSQL partage entre enfants.
$start = microtime(true) + 3;
$children = [];

for ($buyer = 0; $buyer < $buyers; $buyer++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Impossible de lancer un acheteur de test.');
    }
    if ($pid !== 0) {
        $children[] = $pid;
        continue;
    }

    DB::purge();
    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '30s'");
    // Agrandit la fenetre de course : l'ancien code sans verrou surreservait.
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'sum(') && str_contains($query->sql, 'order_items')) {
            usleep(10_000);
        }
    });
    while (microtime(true) < $start) {
        usleep(1000);
    }
    $cart = $buyer % 2 ? [$ids[1] => 1, $ids[0] => 1] : [$ids[0] => 1, $ids[1] => 1];
    try {
        app(CreateOrder::class)->handle($event, $cart, 'Acheteur', 'Test', null, PaymentProvider::Airtel, '074000000');
        exit(0);
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), 'Complet') || str_contains($error->getMessage(), 'il ne reste')) {
            exit(2);
        }
        fwrite(STDERR, $error->getMessage()."\n");
        exit(1);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(1);
    }
}

$accepted = $rejected = $errors = 0;
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
    match ($code) { 0 => $accepted++, 2 => $rejected++, default => $errors++ };
}
DB::purge();
$reserved = $types->map(fn ($type) => app(StockLedger::class)->reserved($type))->all();
$orders = Order::query()->acrossOrganizations()->where('event_id', $event->id)->count();
$passed = $accepted === $stock && $orders === $stock && $errors === 0
    && $rejected === $buyers - $stock && $reserved === [$stock, $stock];
echo json_encode(compact('passed', 'buyers', 'stock', 'accepted', 'rejected', 'errors', 'reserved', 'orders'), JSON_PRETTY_PRINT)."\n";
echo "Donnees de diagnostic conservees : evenement {$event->id}.\n";
exit($passed ? 0 : 1);
