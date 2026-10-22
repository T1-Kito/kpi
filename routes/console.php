<?php

use App\Models\Alert;
use App\Models\KpiBundlePlan;
use App\Models\PurchaseOrder;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\VkNotification;
use App\Services\Automation\BusinessEventDispatcher;
use App\Services\Kpi\KpiBundleRolloutService;
use App\Services\Kpi\KpiService;
use App\Support\BusinessEventPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('crm:backup', function (\App\Support\LocalDatabaseBackup $backup) {
    try {$this->info('Đã sao lưu database: '.$backup->create()); $this->warn('Chưa bao gồm file tải lên, cấu hình và khóa ứng dụng.');}
    catch (\Throwable $e) {$this->error($e->getMessage()); return 1;}
})->purpose('Create and verify a private SQLite database snapshot');

Artisan::command('crm:backup-bundle {--writers-stopped}', function (\App\Support\BackupBundle $backup) {
    if (!$this->option('writers-stopped')) {$this->error('Dừng worker và xác nhận bằng --writers-stopped trước.'); return 1;}
    $password=$this->secret('Mật khẩu mã hóa gói (ít nhất 16 ký tự)');
    if ($password!==$this->secret('Nhập lại mật khẩu')) {$this->error('Mật khẩu không khớp.'); return 1;}
    try {$this->info('Gói đã mã hóa và kiểm chứng: '.$backup->create($password));}
    catch (\Throwable $e) {$this->error($e->getMessage()); return 1;}
})->purpose('Create encrypted database, uploads and environment backup during maintenance');

Artisan::command('crm:bundle-check {name} {--restore-test}', function (\App\Support\BackupBundle $backup) {
    $password=$this->secret('Mật khẩu gói sao lưu');
    try {
        $result=$backup->verify($this->argument('name'),$password,(bool)$this->option('restore-test'));
        $this->info('Đạt: '.$result['files'].' file.');
        if ($result['restore_directory']) $this->info('Khôi phục thử vào thư mục riêng: '.$result['restore_directory']);
    } catch (\Throwable $e) {$this->error($e->getMessage()); return 1;}
})->purpose('Verify encrypted bundle or restore into a separate staging directory');

Artisan::command('crm:backup-verify {name}', function (\App\Support\LocalDatabaseBackup $backup) {
    try {$backup->verify($this->argument('name')); $this->info('Checksum và toàn vẹn database: đạt.');}
    catch (\Throwable $e) {$this->error($e->getMessage()); return 1;}
})->purpose('Verify a database backup without changing live data');

Artisan::command('crm:restore-test {name}', function (\App\Support\LocalDatabaseBackup $backup) {
    try {$this->info('Đã khôi phục thử vào file riêng: '.$backup->restoreCopy($this->argument('name'))); $this->info('Không thay đổi database đang sử dụng.');}
    catch (\Throwable $e) {$this->error($e->getMessage()); return 1;}
})->purpose('Restore a backup into a separate file, never overwrite live data');

Artisan::command('tasks:check-overdue', function (BusinessEventPublisher $events) {
    $count = 0;

    Task::query()
        ->whereIn('status', ['new', 'in_progress'])
        ->whereNotNull('due_at')
        ->where('due_at', '<', now())
        ->with('assignee')
        ->chunkById(100, function ($tasks) use (&$count, $events) {
            foreach ($tasks as $task) {
                DB::transaction(function () use ($task, &$count, $events) {
                    $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($task->status, ['new', 'in_progress'], true) || ! $task->due_at || $task->due_at->gte(now())) {
                        return;
                    }
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
                                'action_url' => '/tasks',
                                'status' => 'open',
                            ],
                        );

                        VkNotification::firstOrCreate(
                            [
                                'tenant_id' => $task->tenant_id,
                                'recipient_id' => $task->assignee_id,
                                'source_type' => 'Task',
                                'source_id' => $task->id,
                                'title' => 'Task quá hạn',
                            ],
                            [
                                'title' => 'Task quá hạn',
                                'message' => $task->code.' - '.$task->title,
                                'action_url' => '/tasks',
                                'status' => 'unread',
                            ],
                        );
                    }

                    $events->publish($task->tenant_id, 'TaskOverdue', 'Task', $task->id, ['code' => $task->code]);
                    $count++;
                });
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

Artisan::command('events:retry-failed {--limit=100} {--max-attempts=5}', function (BusinessEventDispatcher $dispatcher) {
    $result = $dispatcher->process((int) $this->option('limit'), (int) $this->option('max-attempts'));
    $this->info("Processed {$result['processed']} event(s); {$result['failed']} failed; {$result['unsupported']} unsupported; {$result['exhausted']} exhausted.");

    return ($result['failed'] + $result['unsupported'] + $result['exhausted']) > 0 ? 1 : 0;
})->purpose('Reliably dispatch pending business events and retry transient failures');

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
Artisan::command('service:check-sla', function (\App\Support\ServiceTicketEscalation $service) {
    $this->info('Đã gửi '.$service->run().' nhắc hạn CSKH mới.');
})->purpose('Check ticket SLA and notify authorized recipients without duplicate alerts');
Schedule::command('service:check-sla')->everyMinute()->withoutOverlapping();
Artisan::command('imports:prune',function(){
    if(!\Illuminate\Support\Facades\Schema::hasTable('import_batches')){$this->info('Chưa có dữ liệu lô nhập để dọn.');return;}
    $expired=DB::table('import_batches')->where('status','ready')->where('expires_at','<=',now())->update(['encrypted_payload'=>null,'status'=>'expired','updated_at'=>now()]);
    DB::table('import_batches')->where('created_at','<',now()->subDays(30))->whereNotNull('result_json')->orderBy('id')->chunkById(100,function($rows){
        foreach($rows as $row){$result=json_decode($row->result_json,true) ?: [];DB::table('import_batches')->where('id',$row->id)->update(['encrypted_payload'=>null,'result_json'=>json_encode(['total'=>$result['total'] ?? 0,'imported'=>$result['imported'] ?? 0,'details_pruned'=>true])]);}
    });
    $this->info('Đã hủy file kiểm tra của '.$expired.' lô hết hạn. Giữ lịch sử và kết quả tổng hợp.');
})->purpose('Remove expired encrypted import payloads and old personal row details');
Schedule::command('imports:prune')->hourly()->withoutOverlapping();
Schedule::command('procurement:check-late-po')->hourly();
Schedule::command('events:retry-failed --limit=200 --max-attempts=5')->everyMinute()->withoutOverlapping();
Schedule::command('kpi:calculate-snapshots')->dailyAt('23:55');

Artisan::command('kpi:rollout-bundles', function (KpiBundleRolloutService $rollout) {
    $created = 0;
    $failed = 0;
    KpiBundlePlan::where('status', 'active')->with('bundle')->chunkById(100, function ($plans) use ($rollout, &$created, &$failed) {
        foreach ($plans as $plan) {
            try {
                $created += $rollout->ensure($plan, CarbonImmutable::today());
            } catch (Throwable $error) {
                report($error);
                $failed++;
            }
        }
    });
    $this->info("Created {$created} KPI assignment(s); {$failed} plan(s) need review.");

    return $failed ? 1 : 0;
})->purpose('Create KPI assignments for active recurring bundle plans');

Schedule::command('kpi:rollout-bundles')->dailyAt('00:10')->withoutOverlapping();
