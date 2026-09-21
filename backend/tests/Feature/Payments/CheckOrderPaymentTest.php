<?php

use App\Enums\PaymentState;
use App\Jobs\CheckOrderPayment;
use App\Livewire\Public\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Services\Payments\FakeProvider;
use App\Services\Payments\PaymentResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    [, $organization] = organizerWithOrganization('Polling test');
    $this->order = forOrganization($organization, fn () => Order::factory()->for(Event::factory()->published())->create(['psp' => 'fake']));
});

it('met la verification en file sans appeler le PSP depuis la requete web', function () {
    Queue::fake();
    $this->mock(FakeProvider::class)->shouldNotReceive('checkPaymentStatus');
    Livewire::test(OrderStatus::class, ['reference' => $this->order->reference])->call('refreshStatus');
    Queue::assertPushed(CheckOrderPayment::class, fn ($job) => $job->orderId === $this->order->id && $job->queue === 'payment-checks');
});

it('borne le polling PSP a une verification par vingt secondes et par commande', function () {
    $this->mock(FakeProvider::class)->shouldReceive('checkPaymentStatus')->once()
        ->andReturn(new PaymentResult(PaymentState::Pending));
    $job = new CheckOrderPayment($this->order->id);
    app()->call($job->handle(...));
    app()->call($job->handle(...));
});

it('refuse une confirmation de secours avec un montant incoherent', function () {
    $this->mock(FakeProvider::class)->shouldReceive('checkPaymentStatus')->once()
        ->andReturn(new PaymentResult(PaymentState::Succeeded, amount: $this->order->amount + 1));
    $job = new CheckOrderPayment($this->order->id);
    expect(fn () => app()->call($job->handle(...)))->toThrow(RuntimeException::class, 'Montant');
    expect($this->order->fresh()->isPending())->toBeTrue();
});
