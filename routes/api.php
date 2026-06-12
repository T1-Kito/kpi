<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\InventoryBalanceController;
use App\Http\Controllers\Api\V1\InventoryTransactionController;
use App\Http\Controllers\Api\V1\GoodsIssueController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\KpiController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseRequestController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\RoleAdminController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\SkuController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TenantSettingController;
use App\Http\Controllers\Api\V1\UserAdminController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('jwt')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/lookups/users', [LookupController::class, 'users']);
        Route::get('/lookups/departments', [LookupController::class, 'departments']);

        Route::middleware('permission:master.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('/skus', [SkuController::class, 'index']);
            Route::get('/skus/{sku}', [SkuController::class, 'show']);
            Route::get('/warehouses', [WarehouseController::class, 'index']);
            Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show']);
            Route::get('/inventory-balances', [InventoryBalanceController::class, 'index']);
            Route::get('/inventory-transactions', [InventoryTransactionController::class, 'index']);
        });

        Route::middleware('permission:master.manage')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{customer}', [CustomerController::class, 'update']);
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::post('/skus', [SkuController::class, 'store']);
            Route::put('/skus/{sku}', [SkuController::class, 'update']);
            Route::post('/warehouses', [WarehouseController::class, 'store']);
            Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update']);
        });

        Route::middleware('permission:task.view')->group(function () {
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/{task}', [TaskController::class, 'show']);
        });

        Route::middleware('permission:task.create')->group(function () {
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
        });

        Route::middleware('permission:alert.view')->group(function () {
            Route::get('/alerts', [AlertController::class, 'index']);
            Route::post('/alerts/{alert}/status', [AlertController::class, 'updateStatus']);
        });

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::middleware('permission:audit.view')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
        });

        Route::get('/kpi/overview', [KpiController::class, 'overview']);
        Route::get('/kpi/snapshots', [KpiController::class, 'snapshots']);
        Route::get('/kpi/targets', [KpiController::class, 'targets']);
        Route::get('/kpi/exceptions', [KpiController::class, 'exceptions']);
        Route::post('/kpi/exceptions', [KpiController::class, 'storeException']);

        Route::middleware('permission:kpi.lock')->group(function () {
            Route::post('/kpi/snapshots/calculate', [KpiController::class, 'calculate']);
            Route::post('/kpi/exceptions/{kpiException}/review', [KpiController::class, 'reviewException']);
        });

        Route::middleware('permission:user.manage')->group(function () {
            Route::post('/tenant/logo', [TenantSettingController::class, 'updateLogo']);
            Route::get('/users', [UserAdminController::class, 'index']);
            Route::post('/users', [UserAdminController::class, 'store']);
            Route::post('/users/{user}/roles', [UserAdminController::class, 'syncRoles']);
            Route::post('/users/{user}/organization', [UserAdminController::class, 'updateOrganization']);
            Route::post('/users/{user}/status', [UserAdminController::class, 'updateStatus']);
            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::put('/departments/{department}', [DepartmentController::class, 'update']);
            Route::get('/positions', [PositionController::class, 'index']);
            Route::post('/positions', [PositionController::class, 'store']);
            Route::put('/positions/{position}', [PositionController::class, 'update']);
        });

        Route::middleware('permission:role.manage')->group(function () {
            Route::get('/roles', [RoleAdminController::class, 'index']);
            Route::post('/roles', [RoleAdminController::class, 'store']);
            Route::post('/roles/{role}/permissions', [RoleAdminController::class, 'syncPermissions']);
            Route::get('/permissions', [PermissionController::class, 'index']);
        });

        Route::middleware('permission:sales.lead.manage')->group(function () {
            Route::get('/leads', [LeadController::class, 'index']);
            Route::get('/leads/{lead}', [LeadController::class, 'show']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::post('/leads/{lead}/assign', [LeadController::class, 'assign']);
        });

        Route::middleware('permission:sales.quotation.create')->group(function () {
            Route::get('/quotations', [QuotationController::class, 'index']);
            Route::post('/quotations', [QuotationController::class, 'store']);
            Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
        });

        Route::middleware('permission:sales.margin.approve')->group(function () {
            Route::post('/quotations/{quotation}/approve', [QuotationController::class, 'approve']);
        });

        Route::middleware('permission:sales.order.create')->group(function () {
            Route::get('/sales-orders', [SalesOrderController::class, 'index']);
            Route::post('/sales-orders', [SalesOrderController::class, 'store']);
            Route::get('/sales-orders/{salesOrder}', [SalesOrderController::class, 'show']);
            Route::post('/sales-orders/{salesOrder}/confirm', [SalesOrderController::class, 'confirm']);
        });

        Route::middleware('permission:procurement.pr.approve')->group(function () {
            Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
            Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
            Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve']);
        });

        Route::middleware('permission:procurement.po.approve')->group(function () {
            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
            Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
        });

        Route::middleware('permission:inventory.receipt.confirm')->group(function () {
            Route::get('/goods-receipts', [GoodsReceiptController::class, 'index']);
            Route::post('/goods-receipts', [GoodsReceiptController::class, 'store']);
            Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
            Route::post('/goods-receipts/{goodsReceipt}/confirm', [GoodsReceiptController::class, 'confirm']);
        });

        Route::middleware('permission:inventory.issue.confirm')->group(function () {
            Route::get('/goods-issues', [GoodsIssueController::class, 'index']);
            Route::post('/goods-issues', [GoodsIssueController::class, 'store']);
            Route::get('/goods-issues/{goodsIssue}', [GoodsIssueController::class, 'show']);
            Route::post('/goods-issues/{goodsIssue}/confirm', [GoodsIssueController::class, 'confirm']);
        });
    });
});
