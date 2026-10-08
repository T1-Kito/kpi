<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Alert;
use App\Models\BusinessEvent;
use App\Models\PurchaseOrder;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\VkNotification;
use App\Services\Kpi\KpiService;
use App\Support\BusinessEventPublisher;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tasks:check-overdue', function (BusinessEventPublisher $events) {
    $count = 0;

    Task::query()
        ->whereIn('status', ['new', 'in_progress'])
        ->whereNotNull('due_at')
        ->where('due_at', '<', now())
        ->with('assignee')
        ->chunkById(100, function ($tasks) use (&$count, $events) {
            foreach ($tasks as $task) {
                $old = $task->status;
                $task->update(['status' => 'overdue']);
                $task->history()->create([
                    'from_status' => $old,
                    'to_status' => 'overdue',
                    'changed_by' => null,
                    'reason' => 'scheduler_overdue',
                ]);

                if ($task->assignee_id) {
                    Alert::firstOrCreate(
                        [
                            'tenant_id' => $task->tenant_id,
                            'recipient_id' => $task->assignee_id,
                            'alert_type' => 'task_overdue',
                            'source_type' => 'Task',
                            'source_id' => $task->id,
                        ],
                        [
                            'level' => 'high',
                            'title' => 'Task quá hạn: '.$task->title,
                            'message' => 'Task '.$task->code.' đã quá SLA và cần xử lý ngay.',
                            'action_url' => '/tasks/'.$task->id,
                            'status' => 'open',
                        ],
                    );

                    VkNotification::firstOrCreate(
                        [
                            'tenant_id' => $task->tenant_id,
                            'recipient_id' => $task->assignee_id,
                            'source_type' => 'Task',
                            'source_id' => $task->id,
                        ],
                        [
                            'title' => 'Task quá hạn',
                            'message' => $task->code.' - '.$task->title,
                            'action_url' => '/tasks/'.$task->id,
                            'status' => 'unread',
                        ],
                    );
                }

                $events->publish($task->tenant_id, 'TaskOverdue', 'Task', $task->id, ['code' => $task->code]);
                $count++;
            }
        });

    $this->info("Marked {$count} task(s) as overdue.");
})->purpose('Mark overdue tasks and create alerts');

Artisan::command('procurement:check-late-po', function (BusinessEventPublisher $events) {
    $count = 0;

    PurchaseOrder::query()
        ->whereNotNull('expected_delivery_date')
        ->whereDate('expected_delivery_date', '<', now()->toDateString())
        ->whereNotIn('status', ['received', 'cancelled'])
        ->chunkById(100, function ($orders) use (&$count, $events) {
            foreach ($orders as $order) {
                $alert = Alert::firstOrCreate(
                    [
                        'tenant_id' => $order->tenant_id,
                        'alert_type' => 'late_purchase_order',
                        'source_type' => 'PurchaseOrder',
                        'source_id' => $order->id,
                    ],
                    [
                        'recipient_id' => $order->created_by,
                        'level' => 'high',
                        'title' => 'Đơn mua trễ hạn: '.$order->code,
                        'message' => 'Đơn mua '.$order->code.' đã quá ngày nhận dự kiến, cần kiểm tra với nhà cung cấp.',
                        'action_url' => '/purchase-orders',
                        'status' => 'open',
                    ],
                );

                if ($order->created_by) {
                    VkNotification::firstOrCreate(
                        [
                            'tenant_id' => $order->tenant_id,
                            'recipient_id' => $order->created_by,
                            'source_type' => 'Alert',
                            'source_id' => $alert->id,
                        ],
                        [
                            'title' => 'Đơn mua trễ hạn',
                            'message' => $order->code.' cần kiểm tra tiến độ giao hàng.',
                            'action_url' => '/purchase-orders',
                            'status' => 'unread',
                        ],
                    );
                }

                if ($alert->wasRecentlyCreated) {
                    $events->publish($order->tenant_id, 'PurchaseOrderLate', 'PurchaseOrder', $order->id, [
                        'code' => $order->code,
                        'expected_delivery_date' => optional($order->expected_delivery_date)->toDateString(),
                    ]);
                }
                $count++;
            }
        });

    $this->info("Created or verified {$count} late PO alert(s).");
})->purpose('Create alerts for late purchase orders');

Artisan::command('events:retry-failed', function () {
    $count = BusinessEvent::whereIn('status', ['pending', 'failed'])->count();
    $this->error("Retry dispatcher is not configured. Preserved {$count} pending/failed event(s); no business action was replayed.");
    return 1;
})->purpose('Report unavailable event retry without falsely marking events processed');

Artisan::command('kpi:calculate-snapshots', function (KpiService $kpis) {
    $count = 0;

    Tenant::where('status', 'active')->chunkById(50, function ($tenants) use (&$count, $kpis) {
        foreach ($tenants as $tenant) {
            $kpis->calculateSnapshot($tenant);
            $count++;
        }
    });

    $this->info("Calculated {$count} KPI snapshot(s).");
})->purpose('Calculate daily KPI snapshots for active tenants');

Schedule::command('tasks:check-overdue')->everyFiveMinutes();
Schedule::command('procurement:check-late-po')->hourly();
// Enable event retry only after a real, idempotent dispatcher is implemented.
Schedule::command('kpi:calculate-snapshots')->dailyAt('23:55');
