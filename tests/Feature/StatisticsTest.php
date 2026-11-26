<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\StatisticsService;
use App\Services\WorkflowService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    public function test_delais_respect_des_echeances_rejets_et_goulot(): void
    {
        $workflow = app(WorkflowService::class);

        // Document A : Achats vise en 10 h (délai 24 h), Finance en 60 h (délai 48 h : en retard).
        $a = $this->draft(['title' => 'A']);
        $workflow->submit($a, $this->author);
        $this->travel(10)->hours();
        $workflow->decide($this->pendingTask($a), $this->buyerValidator, true, null);
        $this->travel(60)->hours();
        $workflow->decide($this->pendingTask($a), $this->financeValidator, true, null);

        // Document B : rejeté par Achats après 2 h.
        $b = $this->draft(['title' => 'B']);
        $workflow->submit($b, $this->author);
        $this->travel(2)->hours();
        $workflow->decide($this->pendingTask($b), $this->buyerValidator, false, 'Incomplet');

        $stats = app(StatisticsService::class)->compute(30);

        $this->assertSame(3, $stats['summary']['decisions']);
        $this->assertSame(24.0, $stats['summary']['avgStepHours']);            // (10 + 60 + 2) / 3
        $this->assertSame(66.7, $stats['summary']['onTimeRate']);              // 2 sur 3 dans les délais
        $this->assertSame(33.3, $stats['summary']['rejectionRate']);
        $this->assertSame(70.0, $stats['summary']['avgCycleHours']);           // A : soumis puis approuvé 70 h plus tard

        $finance = collect($stats['steps'])->firstWhere('department', 'Finance');
        $this->assertSame(60.0, $finance['avgHours']);
        $this->assertSame(0.0, $finance['onTimeRate']);
        $this->assertSame('Finance', $stats['bottleneck']['department']);
        $this->assertSame(1, collect($stats['monthly'])->sum('rejected'));
    }

    public function test_acces_reserve_aux_valideurs_et_a_l_administrateur(): void
    {
        $this->actingAs($this->author)->get('/statistiques')->assertForbidden();
        $this->actingAs($this->financeValidator)->get('/statistiques?period=90')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Statistics')->where('period', 90));
        $this->actingAs($this->admin)->get('/statistiques?period=7')->assertSessionHasErrors('period');
        $reader = User::factory()->role(Role::Reader, $this->finance)->create();
        $this->actingAs($reader)->get('/statistiques')->assertForbidden();
    }
}
